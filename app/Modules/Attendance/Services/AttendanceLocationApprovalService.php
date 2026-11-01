<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\LocationReviewStatus;
use App\Modules\Attendance\Exceptions\AttendanceException;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A manager approving location reviews, one at a time or in bulk.
 *
 * Every record is approved in its own short transaction under the employee
 * lock that recording and refusal take, after its own scope check. So a bulk
 * run holds no more than one employee lock at a time, an out-of-scope or
 * refused row is skipped without failing the rest, and a refusal that commits
 * first always wins. Approval changes only the review; the event's counting is
 * untouched.
 */
final class AttendanceLocationApprovalService
{
    public function __construct(
        private readonly AttendanceCalendar $calendar,
        private readonly AttendanceRecordQuery $query,
    ) {}

    public function approve(AttendanceRecord $record, User $manager, ?string $note): AttendanceRecord
    {
        $result = $this->tryApprove((int) $record->getKey(), $manager, $note);
        if ($result !== null && $result !== 'already_approved') {
            throw new AttendanceException(match ($result) {
                'rejected' => 'لا يمكن اعتماد موقع سجل مرفوض.',
                'not_assessed' => 'لا توجد مراجعة موقع لهذا السجل.',
                default => 'لا يمكن اعتماد موقع هذا السجل.',
            });
        }

        return $record->fresh()->load(AttendanceRecordQuery::RENDER_RELATIONS);
    }

    /** @param array<int, int>|null $ids  @param array<string, mixed>|null $matching  @return array<string, mixed> */
    public function bulk(User $manager, ?array $ids, ?array $matching, ?string $note): array
    {
        $byFilter = $matching !== null;
        $ids ??= $this->query->matchingApprovalIds($manager, $matching ?? [], 200);
        $approved = $skipped = [];

        foreach ($ids as $id) {
            $reason = $this->tryApprove((int) $id, $manager, $note);
            if ($reason === null) {
                $approved[] = (int) $id;
            } else {
                $skipped[] = ['id' => (int) $id, 'reason' => $reason];
            }
        }

        return [
            'approved' => $approved,
            'skipped' => $skipped,
            'remaining' => $byFilter ? $this->query->matchingApprovalCount($manager, $matching ?? []) : null,
        ];
    }

    /** Null means approved; other values are stable skip reasons. */
    private function tryApprove(int $id, User $manager, ?string $note): ?string
    {
        return DB::transaction(function () use ($id, $manager, $note): ?string {
            $employeeId = AttendanceRecord::whereKey($id)->value('employee_id');
            if ($employeeId === null) {
                return 'not_found';
            }

            // The same lock order as recording and refusal: employee, record,
            // then review. A refused row cannot become newly approved.
            Employee::withTrashed()->whereKey($employeeId)->lockForUpdate()->firstOrFail();
            $record = AttendanceRecord::with('employee')->find($id);
            if ($record === null) {
                return 'not_found';
            }
            if (! Gate::forUser($manager)->allows('approveLocation', $record)) {
                return 'out_of_scope';
            }
            if ($record->isRejected()) {
                return 'rejected';
            }

            $review = $record->locationReview()->lockForUpdate()->first();
            if ($review === null) {
                return 'not_assessed';
            }
            if ($review->status === LocationReviewStatus::Approved) {
                return 'already_approved';
            }

            $review->update([
                'status' => LocationReviewStatus::Approved,
                'approved_by' => $manager->getKey(),
                'approved_at' => now($this->calendar->timezone()),
                'note' => $note,
            ]);

            return null;
        });
    }
}
