<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Services;

use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Exceptions\SchedulingException;
use App\Modules\Scheduling\Models\EmployeeWorkSchedule;
use App\Modules\Scheduling\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The one writer of people's schedule spans (work shifts spec §3.1, §9.2).
 *
 * Assigning closes the person's open span on the day before the new one
 * starts; removing a span that has not started reopens the span it closed,
 * so taking back a mistaken assignment leaves the person as they were.
 * Every rule on a span itself is EmployeeWorkSchedule's (AssignmentGuard).
 */
class ScheduleAssigner
{
    public function __construct(private readonly AttendanceCalendar $calendar) {}

    /** @throws SchedulingException */
    public function assign(Employee $employee, WorkSchedule $schedule, string $from, ?string $cycleStart, User $by): EmployeeWorkSchedule
    {
        return DB::transaction(function () use ($employee, $schedule, $from, $cycleStart, $by): EmployeeWorkSchedule {
            Employee::whereKey($employee->getKey())->lockForUpdate()->first();

            $open = EmployeeWorkSchedule::query()->where('employee_id', $employee->getKey())->whereNull('to_date')->first();
            if ($open !== null && $open->from_date->toDateString() < $from) {
                $open->update(['to_date' => CarbonImmutable::parse($from)->subDay()->toDateString()]);
            }

            return EmployeeWorkSchedule::create([
                'employee_id'      => $employee->getKey(),
                'work_schedule_id' => $schedule->getKey(),
                'from_date'        => $from,
                'cycle_start_date' => $cycleStart,
                'created_by'       => $by->getKey(),
            ]);
        });
    }

    /**
     * Each person in their own transaction: one refusal never undoes the rest.
     *
     * @param  list<int>  $employeeIds
     * @return list<array{employee_id: int, assigned: bool, reason: ?string}>
     */
    public function bulk(WorkSchedule $schedule, string $from, ?string $cycleStart, array $employeeIds, User $by): array
    {
        $people = Employee::query()->whereKey($employeeIds)->get()->keyBy('id');

        return array_map(function (int $id) use ($people, $schedule, $from, $cycleStart, $by): array {
            $employee = $people->get($id);
            if ($employee === null) {
                return ['employee_id' => $id, 'assigned' => false, 'reason' => 'الموظف غير موجود.'];
            }

            try {
                $this->assign($employee, $schedule, $from, $cycleStart, $by);

                return ['employee_id' => $id, 'assigned' => true, 'reason' => null];
            } catch (SchedulingException $refused) {
                return ['employee_id' => $id, 'assigned' => false, 'reason' => $refused->getMessage()];
            }
        }, array_values(array_map('intval', $employeeIds)));
    }

    /** End a span on $to (today or later — the request checks; the model allows yesterday). */
    public function end(EmployeeWorkSchedule $span, string $to): EmployeeWorkSchedule
    {
        $span->update(['to_date' => $to]);

        return $span->fresh();
    }

    /** @throws SchedulingException */
    public function remove(EmployeeWorkSchedule $span): void
    {
        if ($span->from_date->toDateString() <= $this->calendar->today()) {
            throw new SchedulingException('لا يمكن حذف جدول بدأ العمل به؛ يمكن إنهاؤه فقط.');
        }

        DB::transaction(function () use ($span): void {
            $employeeId = (int) $span->employee_id;
            $dayBefore = CarbonImmutable::parse($span->from_date->toDateString())->subDay()->toDateString();
            $span->delete();

            $later = EmployeeWorkSchedule::query()->where('employee_id', $employeeId)->whereDate('from_date', '>', $dayBefore)->exists();
            $closed = EmployeeWorkSchedule::query()->where('employee_id', $employeeId)->whereDate('to_date', $dayBefore)->first();
            if ($closed !== null && ! $later) {
                $closed->update(['to_date' => null]);
            }
        });
    }
}
