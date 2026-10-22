<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals\Fakes;

use App\Modules\Approvals\Contracts\Approvable;
use App\Modules\Approvals\Enums\ApproverNotice;
use App\Modules\Approvals\Models\Approval;
use App\Modules\Approvals\Support\ApprovalSummary;
use App\Modules\Approvals\Support\ApproverMessage;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * A subject that exists only to be approved — so the engine is proven before
 * leave exists (design §8 step 1). Its table is created by the test, and its
 * morph alias registered for the test's duration (ApprovalFixtures).
 *
 * It records what the engine told it: the last outcome and how many times it
 * was told, so a test can assert the hook ran exactly once.
 *
 * Its summary reads a relation (`employee`), as leave's reads its type, so a
 * list that forgot to eager-load the subjects shows up as a query per row.
 */
class FakeApprovable extends Model implements Approvable
{
    public const MORPH_ALIAS = 'fake_approvable';

    /** When true, the completion hook throws — to prove it rolls the decision back. */
    public static bool $failOnDecide = false;

    protected $table = 'fake_approvables';

    protected $fillable = ['employee_id', 'outcome', 'decided_count', 'refusal'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function approvalRequester(): Employee
    {
        return Employee::withTrashed()->findOrFail($this->employee_id);
    }

    public function canBeApproved(): ?string
    {
        return $this->refusal;
    }

    public function onApprovalDecided(Approval $approval): void
    {
        if (self::$failOnDecide) {
            throw new RuntimeException('the subject failed to record the outcome');
        }

        $this->update([
            'outcome'       => $approval->status->name,
            'decided_count' => $this->decided_count + 1,
        ]);
    }

    public function describeForApprover(ApproverNotice $notice): ApproverMessage
    {
        return new ApproverMessage("عنوان {$notice->name}", "نص {$notice->name} للطلب #{$this->id}");
    }

    public function approvalSummary(): ApprovalSummary
    {
        return new ApprovalSummary('طلب تجريبي', "الطلب رقم {$this->id} من {$this->employee->employee_number}");
    }

    public static function approvalSummaryRelations(): array
    {
        return ['employee'];
    }
}
