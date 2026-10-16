<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\AttendanceRecordStatus;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\NotificationService;
use App\Shared\Support\NotificationEventCatalogue;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * The attendance day's schedule (work order §4.5 slice 3): legacy's
 * check-out reminder and day close, unchanged, and the selfie purge the
 * decision round added (Q11). Legacy's monthly cleanup — which deleted every
 * record older than a month — is NOT ported (D7): payroll reads these rows.
 *
 * A refused event is out of all of it, as legacy: nobody is nudged to close,
 * or flagged for, a day HR already threw out.
 *
 * The day close and the purge write in bulk, as legacy's close did, so they
 * add no audit row per record: the row itself says what happened
 * (`status`, `selfie_purged_at`).
 */
class AttendanceScheduleService
{
    public function __construct(
        private readonly AttendanceCalendar $calendar,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Push a reminder to everyone with a standing check-in on $date and no
     * check-out yet. Their accounts must be active; the notification service
     * skips the rest.
     *
     * @return int|null accounts reminded, or null for a day off
     */
    public function remindCheckout(string $date): ?int
    {
        if (! $this->calendar->isWorkingDay(Carbon::parse($date))) {
            return null;
        }

        $accounts = User::query()->whereIn('employee_id', $this->stillCheckedIn($date))->pluck('id')->all();

        return $this->notifications->sendToUsers(
            $accounts,
            'تذكير بتسجيل الانصراف',
            'لقد سجلت حضورك اليوم ولم تسجل انصرافك بعد. يرجى تسجيل الانصراف.',
            type: NotificationEventCatalogue::ATTENDANCE_CHECKOUT_REMINDER,
        );
    }

    /**
     * Close $date: a standing check-in with no check-out becomes a missing
     * check-out — no invented check-out, no hours. HR corrects it later
     * (PATCH /records/{record}/checkout). Already-flagged rows are left alone,
     * so running it twice changes nothing.
     *
     * @return int|null check-ins flagged, or null for a day off
     */
    public function closeDay(string $date): ?int
    {
        if (! $this->calendar->isWorkingDay(Carbon::parse($date))) {
            return null;
        }

        return AttendanceRecord::query()
            ->checkIns()->accepted()->onDate($date)
            ->whereIn('employee_id', $this->stillCheckedIn($date))
            ->whereNull('status')
            ->update(['status' => AttendanceRecordStatus::MissingCheckout]);
    }

    /**
     * Delete the selfie FILES of records older than the retention (three
     * months by default, from the start of today) and say so on each row. The
     * records themselves stay forever. A file already gone is marked all the
     * same, so the row never points at nothing.
     *
     * @return int records purged
     */
    public function purgeSelfies(): int
    {
        $months = max(1, (int) config('attendance.selfie_retention_months', 3));
        $cutoff = Carbon::now($this->calendar->timezone())->startOfDay()->subMonthsNoOverflow($months);
        $disk = Storage::disk(SelfieStore::DISK);
        $purged = 0;

        AttendanceRecord::query()
            ->whereNotNull('selfie_path')
            ->where('recorded_at', '<', $cutoff)
            ->select(['id', 'selfie_path'])
            ->chunkById(200, function (Collection $records) use ($disk, &$purged): void {
                $disk->delete($records->pluck('selfie_path')->all());

                $purged += AttendanceRecord::query()
                    ->whereKey($records->modelKeys())
                    ->update(['selfie_path' => null, 'selfie_purged_at' => Carbon::now($this->calendar->timezone())]);
            });

        return $purged;
    }

    /**
     * Employees with a standing check-in on $date and no standing check-out.
     *
     * @return array<int, int>
     */
    private function stillCheckedIn(string $date): array
    {
        $out = AttendanceRecord::query()->checkOuts()->accepted()->onDate($date)->distinct()->pluck('employee_id');

        return AttendanceRecord::query()->checkIns()->accepted()->onDate($date)->distinct()->pluck('employee_id')
            ->diff($out)->map(fn ($id): int => (int) $id)->values()->all();
    }
}
