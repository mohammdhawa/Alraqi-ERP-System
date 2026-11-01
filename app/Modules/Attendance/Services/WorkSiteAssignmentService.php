<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\EmployeeWorkSiteAssignment;
use App\Modules\Attendance\Models\WorkSite;
use App\Modules\Attendance\Models\WorkSiteVersion;
use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\HR\Models\Employee;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Work sites, their effective-dated boundaries, and which site each employee
 * is assigned to (design spec, 2026-10-29 work-site attendance review).
 *
 * HALF-OPEN AND NEVER BACKDATED. A version or assignment covers
 * `effective_from <= t < effective_to`, in the attendance timezone like
 * `recorded_at`. A change may start now or later, never earlier: an offline
 * event not yet synced must still be judged by the boundary it was recorded
 * under.
 *
 * THE LOCKS. An assignment write locks the employee row and a site write locks
 * the site row, so two concurrent writes cannot both pass the overlap check.
 * An archive is refused while anyone is still assigned, now or later.
 */
final class WorkSiteAssignmentService
{
    public function __construct(private readonly AttendanceCalendar $calendar) {}

    public function siteFor(Employee $employee, CarbonInterface $at): ?EffectiveWorkSite
    {
        $assignment = EmployeeWorkSiteAssignment::query()
            ->where('employee_id', $employee->getKey())
            ->where('effective_from', '<=', $at)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $at))
            ->orderByDesc('effective_from')
            ->first();

        if ($assignment === null) {
            return null;
        }

        $site = $assignment->site;
        $version = WorkSiteVersion::query()
            ->where('work_site_id', $site->getKey())
            ->where('effective_from', '<=', $at)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $at))
            ->orderByDesc('effective_from')
            ->first();

        return new EffectiveWorkSite($assignment, $site, $version);
    }

    /** @param array{name: string, latitude: float|string, longitude: float|string, radius_m: float|string} $data */
    public function createSite(array $data): WorkSite
    {
        return DB::transaction(function () use ($data): WorkSite {
            $now = $this->now();
            $site = WorkSite::create(['name' => $data['name']]);
            $site->versions()->create([
                'name' => $data['name'],
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'radius_m' => $data['radius_m'],
                'effective_from' => $now,
            ]);

            return $site->load('versions');
        });
    }

    /** @param array<string, mixed> $data */
    public function updateSite(WorkSite $site, array $data): WorkSite
    {
        return DB::transaction(function () use ($site, $data): WorkSite {
            $site = WorkSite::whereKey($site->getKey())->lockForUpdate()->firstOrFail();
            if ($site->archived_at !== null) {
                throw ValidationException::withMessages(['work_site' => 'لا يمكن تعديل موقع عمل مؤرشف.']);
            }

            if (array_intersect_key($data, array_flip(['name', 'latitude', 'longitude', 'radius_m'])) === []) {
                throw ValidationException::withMessages(['work_site' => 'أدخل تغييراً واحداً على الأقل لموقع العمل.']);
            }

            $current = $site->versions()->orderByDesc('effective_from')->firstOrFail();
            $at = isset($data['effective_from']) ? $this->notPast($data['effective_from']) : $this->now();
            if ($at->lte($current->effective_from)) {
                throw ValidationException::withMessages(['effective_from' => 'وقت التعديل يجب أن يكون بعد بداية النسخة الحالية.']);
            }

            $current->update(['effective_to' => $at]);
            $name = $data['name'] ?? $site->name;
            $site->versions()->create([
                'name' => $name,
                'latitude' => $data['latitude'] ?? $current->latitude,
                'longitude' => $data['longitude'] ?? $current->longitude,
                'radius_m' => $data['radius_m'] ?? $current->radius_m,
                'effective_from' => $at,
            ]);
            $site->update(['name' => $name]);

            return $site->load('versions');
        });
    }

    public function archiveSite(WorkSite $site): WorkSite
    {
        return DB::transaction(function () use ($site): WorkSite {
            $site = WorkSite::whereKey($site->getKey())->lockForUpdate()->firstOrFail();
            if ($site->archived_at !== null) {
                return $site;
            }

            $now = $this->now();
            $active = $site->assignments()
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $now))
                ->with('employee')->get();
            if ($active->isNotEmpty()) {
                $names = $active->map(fn (EmployeeWorkSiteAssignment $a): string => $a->employee?->full_name ?? '#'.$a->employee_id)->unique()->implode('، ');
                throw ValidationException::withMessages(['assignments' => 'الموظفون المرتبطون بالموقع: '.$names]);
            }

            $site->update(['archived_at' => $now]);

            return $site->load('versions');
        });
    }

    /** @param array{employee_id: int, work_site_id: int, effective_from?: string} $data */
    public function assign(array $data): EmployeeWorkSiteAssignment
    {
        return DB::transaction(function () use ($data): EmployeeWorkSiteAssignment {
            Employee::withTrashed()->whereKey($data['employee_id'])->lockForUpdate()->firstOrFail();
            $site = WorkSite::whereKey($data['work_site_id'])->lockForUpdate()->firstOrFail();
            $at = isset($data['effective_from']) ? $this->notPast($data['effective_from']) : $this->now();
            if ($site->archived_at !== null && $site->archived_at->lte($at)) {
                throw ValidationException::withMessages(['work_site_id' => 'موقع العمل مؤرشف.']);
            }

            $overlap = EmployeeWorkSiteAssignment::where('employee_id', $data['employee_id'])
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $at))
                ->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['effective_from' => 'للموظف موقع عمل آخر في هذه الفترة.']);
            }

            return EmployeeWorkSiteAssignment::create([
                'employee_id' => $data['employee_id'],
                'work_site_id' => $site->getKey(),
                'effective_from' => $at,
            ])->load(['employee', 'site']);
        });
    }

    public function endAssignment(EmployeeWorkSiteAssignment $assignment, string $effectiveTo): EmployeeWorkSiteAssignment
    {
        return DB::transaction(function () use ($assignment, $effectiveTo): EmployeeWorkSiteAssignment {
            Employee::withTrashed()->whereKey($assignment->employee_id)->lockForUpdate()->firstOrFail();
            $assignment->refresh();
            $at = $this->notPast($effectiveTo, 'effective_to');
            if ($assignment->effective_to !== null || $at->lte($assignment->effective_from)) {
                throw ValidationException::withMessages(['effective_to' => 'وقت انتهاء التعيين غير صالح.']);
            }
            $assignment->update(['effective_to' => $at]);

            return $assignment->load(['employee', 'site']);
        });
    }

    private function now(): Carbon
    {
        return Carbon::now($this->calendar->timezone())->startOfSecond();
    }

    private function notPast(string $value, string $field = 'effective_from'): Carbon
    {
        $at = $this->calendar->parse($value)->startOfSecond();
        if ($at->lt($this->now())) {
            throw ValidationException::withMessages([$field => 'لا يمكن تغيير موقع العمل بتاريخ سابق.']);
        }

        return $at;
    }
}
