<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Modules\Approvals\Services\ApprovalService;
use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Enums\LeaveOrigin;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Exceptions\LeaveException;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Shared\Services\AttachmentStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Filing an employee's own leave — legacy's LeaveRequestController::store(),
 * on the resolved chain (LEAVE_BUILD.md §4).
 *
 * THE CHECKS, in legacy's order after the form's: the span holds a working
 * day; a deducting type fits the remaining balance; nothing pending or
 * approved overlaps it. They run with the employee's row locked — legacy
 * locked the user row — so two filings at once cannot both pass the overlap
 * check.
 *
 * ONE TRANSACTION, NOTHING LEFT BEHIND. The request, its files' rows and the
 * engine's submission commit together; a chain the engine cannot resolve (a
 * vacant HR post, an approver without an account — §9 Q2, Q8) rolls all of it
 * back, and the files already written to disk are deleted, since a file is
 * not in the transaction.
 */
class LeaveRequestService
{
    public function __construct(
        private readonly AttendanceCalendar $calendar,
        private readonly LeaveBalances $balances,
        private readonly AttachmentStore $attachments,
        private readonly ApprovalService $engine,
    ) {}

    /**
     * @param  array{leave_type_id: int|string, start_date: string, end_date: string, reason?: ?string}  $data
     * @param  array<int, UploadedFile>  $files
     *
     * @throws LeaveException
     */
    public function file(Employee $employee, User $by, array $data, array $files): LeaveRequest
    {
        LeaveException::unlessOnDuty($employee);

        $type = LeaveType::query()->findOrFail($data['leave_type_id']);
        $start = Carbon::parse($data['start_date'])->toDateString();
        $end = Carbon::parse($data['end_date'])->toDateString();
        $days = $this->calendar->workingDaysBetween($start, $end);

        if ($days < 1) {
            throw new LeaveException('الفترة المحددة لا تتضمن أيام عمل.');
        }

        $stored = [];

        try {
            return DB::transaction(function () use ($employee, $by, $data, $files, $type, $start, $end, $days, &$stored): LeaveRequest {
                Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

                if ($type->deducts_balance) {
                    $remaining = $this->balances->remaining((int) $employee->id, Carbon::parse($start)->year);

                    if ($days > $remaining) {
                        throw new LeaveException("طلب الإجازة يتجاوز رصيد الإجازات المتبقي (المتبقي {$remaining} يوم).");
                    }
                }

                $this->ensureNoOverlap((int) $employee->id, $start, $end);

                $leave = LeaveRequest::create([
                    'employee_id'     => $employee->id,
                    'leave_type_id'   => $type->id,
                    'origin'          => LeaveOrigin::Request,
                    'deducts_balance' => $type->deducts_balance,
                    'reason'          => $this->clean($data['reason'] ?? null),
                    'start_date'      => $start,
                    'end_date'        => $end,
                    'requested_days'  => $days,
                    'status'          => LeaveStatus::Pending,
                ]);

                foreach ($files as $file) {
                    $stored[] = $this->attachments->store($file, $leave, $by)->path;
                }

                $this->engine->submit($leave, LeaveApprovalRoute::make());

                return $leave;
            });
        } catch (Throwable $failure) {
            $this->attachments->deleteFiles($stored);

            throw $failure;
        }
    }

    /**
     * A pending or approved leave — request or excuse — over any of these
     * days. Shared with HR's excuses (slice 3).
     *
     * @throws LeaveException
     */
    public function ensureNoOverlap(int $employeeId, string $start, string $end): void
    {
        $overlaps = LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->blocking()
            ->overlapping($start, $end)
            ->exists();

        if ($overlaps) {
            throw new LeaveException('توجد إجازة قيد الاعتماد أو معتمدة تتداخل مع هذه الفترة.');
        }
    }

    private function clean(?string $text): ?string
    {
        return $text === null || trim($text) === '' ? null : trim($text);
    }
}
