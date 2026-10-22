<?php

declare(strict_types=1);

namespace App\Modules\Me\Services;

use App\Modules\Approvals\Services\ApprovalInbox;
use App\Modules\Attendance\Enums\AttendanceDayState;
use App\Modules\Attendance\Services\AttendanceReportService;
use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\NotificationService;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Resources\EmployeeStub;
use App\Modules\HR\Services\EmployeeService;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Services\LeaveBalances;
use Carbon\Carbon;

/**
 * The home screen (requirements §1 area 1, §6.2; work order §4.8) — one
 * object of cards over five modules, each read from the module that owns it,
 * each `null` until its module can answer. Today that is `salary` alone,
 * until Stage 4 payroll.
 *
 * Every card is the CALLER'S: the employee is resolved from the account, and
 * each module is asked about that one person — never a scope, never an id
 * from the request (C7).
 *
 * TODAY, BEFORE ANYTHING IS RECORDED (the owner's answer of 2026-10-19): the
 * grid would call it absent, but the day is not over and the check-in window
 * is open all day (Q9). Today's card says `status: null`, and the month's
 * figures leave that day out of the expected and the absent days alike, so
 * the two cards never disagree.
 */
final class MySummary
{
    public function __construct(
        private readonly EmployeeService $employees,
        private readonly AttendanceReportService $attendance,
        private readonly AttendanceCalendar $calendar,
        private readonly LeaveBalances $balances,
        private readonly NotificationService $notifications,
        private readonly ApprovalInbox $inbox,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user, Employee $employee): array
    {
        $today = $this->calendar->today();
        [$day, $month] = $this->attendance($employee, $today);

        return [
            'employee'         => $this->employeeCard($employee),
            'attendance_today' => $day,
            'attendance_month' => $month,
            'leave_balance'    => $this->balances->for((int) $employee->getKey(), Carbon::parse($today)->year)->toArray(),
            'notifications'    => ['unread_count' => $this->notifications->unreadCount($user)],
            'approvals'        => ['pending_count' => $this->inbox->pendingFor($user)->count()],
            'leave_requests'   => ['pending_count' => LeaveRequest::query()
                ->where('employee_id', $employee->getKey())
                ->where('status', LeaveStatus::Pending)
                ->count()],
            // Stage 4: no payroll exists to read from.
            'salary'           => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function employeeCard(Employee $employee): array
    {
        $employee = $this->employees->prepareForResource($employee);
        $employee->load('personalPhoto');

        return [
            ...EmployeeStub::from($employee),
            'job_title'  => $employee->jobTitle === null ? null : ['id' => $employee->jobTitle->id, 'name' => $employee->jobTitle->name],
            'department' => $employee->department === null ? null : ['id' => $employee->department->id, 'name' => $employee->department->name],
            'workplace'  => $employee->workplace,
            'manager'    => EmployeeStub::from($employee->resolvedManager()),
            'has_photo'  => $employee->personalPhoto !== null,
        ];
    }

    /**
     * Today's day and the month to today, from the attendance grid — the
     * same days and the same arithmetic HR's grid shows for this person.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function attendance(Employee $employee, string $today): array
    {
        $from = Carbon::parse($today)->startOfMonth()->toDateString();
        $grid = $this->attendance->days($employee, $from, $today);

        $row = collect($grid['days'])->firstWhere('date', $today);
        // The grid already leaves a today with nothing yet out of its
        // expected and absent days (parity audit S-1); the card says null.
        $notYet = $row['status'] === AttendanceDayState::NotYet->value;
        $summary = $grid['summary'];

        $day = [
            'date'                 => $today,
            'status'               => $notYet ? null : $row['status'],
            'status_label'         => $notYet ? 'لم يُسجَّل بعد' : $row['status_label'],
            'check_in_time'        => $row['check_in_time'],
            'check_out_time'       => $row['check_out_time'],
            'work_hours'           => $row['work_hours'],
            'work_hours_formatted' => $row['work_hours_formatted'],
            'leave_type'           => $row['leave_type'],
        ];

        $month = [
            'from'                       => $from,
            'to'                         => $today,
            'expected_days'              => $summary['expected_days'],
            'present_days'               => $summary['present_days'],
            'on_leave_days'              => $summary['on_leave_days'],
            'excused_days'               => $summary['excused_days'],
            'absent_days'                => $summary['absent_days'],
            'total_work_hours'           => $summary['total_work_hours'],
            'total_work_hours_formatted' => $summary['total_work_hours_formatted'],
        ];

        return [$day, $month];
    }
}
