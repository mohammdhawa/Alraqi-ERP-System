<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\NotificationService;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Enums\LeaveOrigin;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Exceptions\LeaveException;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Shared\Services\AttachmentStore;
use App\Shared\Support\NotificationEventCatalogue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * HR's excuses — legacy's LeaveRequestController::excuse() (LEAVE_BUILD.md
 * §6). Approved when filed, with no chain (Q5): an excuse records an absence
 * that already happened, so an approved row for a past date reclassifies
 * that day across every attendance report at once.
 *
 * THERE HAS TO BE AN ABSENCE. A day the employee checked in on is already
 * accounted for; excusing it would charge the balance for a day worked and
 * make the reports contradict themselves (legacy's argument). A REFUSED
 * check-in is not attendance, so that day can be excused. `force` never
 * overrides this — only the balance.
 *
 * One transaction with the employee's row locked, as filing; files stored in
 * it are deleted again if it fails.
 */
class LeaveExcuseService
{
    public function __construct(
        private readonly AttendanceCalendar $calendar,
        private readonly LeaveBalances $balances,
        private readonly AttachmentStore $attachments,
        private readonly LeaveRequestService $requests,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array{leave_type_id: int|string, start_date: string, end_date: string, reason?: ?string}  $data
     * @param  array<int, UploadedFile>  $files
     *
     * @throws LeaveException
     */
    public function file(Employee $employee, User $by, array $data, array $files, bool $force): LeaveRequest
    {
        $type = LeaveType::query()->findOrFail($data['leave_type_id']);
        $start = Carbon::parse($data['start_date'])->toDateString();
        $end = Carbon::parse($data['end_date'])->toDateString();
        $days = $this->calendar->workingDaysBetween($start, $end);

        if ($days < 1) {
            throw new LeaveException('الفترة المحددة لا تتضمن أيام عمل.');
        }

        $stored = [];

        try {
            $excuse = DB::transaction(function () use ($employee, $by, $data, $files, $force, $type, $start, $end, $days, &$stored): LeaveRequest {
                Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

                $this->requests->ensureNoOverlap((int) $employee->id, $start, $end);
                $this->ensureAbsent((int) $employee->id, $start, $end);

                if ($type->deducts_balance && ! $force) {
                    $remaining = $this->balances->remaining((int) $employee->id, Carbon::parse($start)->year);

                    if ($days > $remaining) {
                        throw new LeaveException("العذر يتجاوز رصيد الإجازات المتبقي للموظف (المتبقي {$remaining} يوم). أرسل force لتسجيله رغم ذلك.");
                    }
                }

                $excuse = LeaveRequest::create([
                    'employee_id'     => $employee->id,
                    'leave_type_id'   => $type->id,
                    'origin'          => LeaveOrigin::HrExcuse,
                    'deducts_balance' => $type->deducts_balance,
                    'reason'          => $this->clean($data['reason'] ?? null),
                    'start_date'      => $start,
                    'end_date'        => $end,
                    'requested_days'  => $days,
                    'status'          => LeaveStatus::Approved,
                    'decided_at'      => now(),
                    'created_by'      => $by->getKey(),
                ]);

                foreach ($files as $file) {
                    $stored[] = $this->attachments->store($file, $excuse, $by)->path;
                }

                $this->tell($employee, $excuse, $type);

                return $excuse;
            });
        } catch (Throwable $failure) {
            $this->attachments->deleteFiles($stored);

            throw $failure;
        }

        return $excuse;
    }

    /**
     * Undo an excuse filed in error — legacy's cancelExcuse(). The row is
     * kept, cancelled, with who undid it and why: the trail behind a balance
     * day that came back or an absence that reappeared. Locked before its
     * status is read, so two clicks cannot both pass and notify twice.
     *
     * An employee's own request is a different decision with different
     * parties — it is decided through its chain — and is refused here.
     *
     * @throws LeaveException
     */
    public function undo(LeaveRequest $excuse, User $by, ?string $reason): LeaveRequest
    {
        $reason = $this->clean($reason);

        return DB::transaction(function () use ($excuse, $by, $reason): LeaveRequest {
            /** @var LeaveRequest $excuse */
            $excuse = LeaveRequest::query()->whereKey($excuse->id)->lockForUpdate()->firstOrFail();

            if (! $excuse->isExcuse()) {
                throw new LeaveException('يمكن التراجع هنا عن الأعذار التي سجّلتها الموارد البشرية فقط؛ طلب الموظف يُقرَّر عبر مسار اعتماده.');
            }

            if ($excuse->status !== LeaveStatus::Approved) {
                throw new LeaveException($excuse->status === LeaveStatus::Cancelled ? 'تم التراجع عن هذا العذر مسبقاً.' : 'يمكن التراجع عن عذر قائم فقط.');
            }

            $excuse->update([
                'status'              => LeaveStatus::Cancelled,
                'cancelled_at'        => now(),
                'cancelled_by'        => $by->getKey(),
                'cancellation_reason' => $reason,
            ]);

            // News the employee must act on: their absence is no longer
            // justified, and they may need to bring the one that fits.
            $body = "تم التراجع عن ({$excuse->type->label()}) المسجّلة من {$excuse->start_date->toDateString()} إلى {$excuse->end_date->toDateString()}، وعاد الغياب كما كان."
                .($excuse->deducts_balance ? ' وقد أُعيد يوم الرصيد المخصوم.' : '')
                .($reason !== null ? " السبب: {$reason}" : '');

            $this->toEmployee($excuse->employee, 'تم التراجع عن عذر غيابك', $body, NotificationEventCatalogue::LEAVE_EXCUSE_CANCELLED, $excuse);

            return $excuse;
        });
    }

    /**
     * @throws LeaveException
     */
    private function ensureAbsent(int $employeeId, string $start, string $end): void
    {
        $attended = AttendanceRecord::query()
            ->forEmployee($employeeId)
            ->checkIns()
            ->accepted()
            ->inDateRange($start, $end)
            ->pluck('recorded_at')
            ->map(static fn (mixed $at): string => Carbon::parse($at)->toDateString())
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($attended !== []) {
            throw new LeaveException('للموظف حضور مسجّل في بعض هذه الأيام، فلا يوجد غياب لتبريره: '.implode('، ', $attended).'.');
        }
    }

    private function tell(Employee $employee, LeaveRequest $excuse, LeaveType $type): void
    {
        $note = $excuse->deducts_balance ? 'وقد تم خصمها من رصيد إجازاتك.' : 'ولا تُخصم من رصيد إجازاتك.';
        $body = "سجّلت إدارة الموارد البشرية ({$type->label()}) من {$excuse->start_date->toDateString()} إلى {$excuse->end_date->toDateString()}، {$note}";

        $this->toEmployee($employee, 'تم تسجيل عذر عن غيابك', $body, NotificationEventCatalogue::LEAVE_EXCUSE_FILED, $excuse);
    }

    private function toEmployee(Employee $employee, string $title, string $body, string $type, LeaveRequest $reference): void
    {
        $account = User::query()->where('employee_id', $employee->id)->value('id');

        if ($account !== null) {
            $this->notifications->sendToUser((int) $account, $title, $body, type: $type, reference: $reference);
        }
    }

    private function clean(?string $text): ?string
    {
        return $text === null || trim($text) === '' ? null : trim($text);
    }
}
