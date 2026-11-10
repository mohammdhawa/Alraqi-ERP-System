<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Models;

use App\Modules\Approvals\Contracts\Approvable;
use App\Modules\Approvals\Enums\ApprovalStatus;
use App\Modules\Approvals\Enums\ApproverNotice;
use App\Modules\Approvals\Models\Approval;
use App\Modules\Approvals\Support\ApprovalSummary;
use App\Modules\Approvals\Support\ApproverMessage;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Enums\ShiftSwapStatus;
use App\Modules\Scheduling\Services\ShiftSwapCheck;
use App\Modules\Scheduling\Services\ShiftSwapPlan;
use App\Modules\Scheduling\Support\SwapNotices;
use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Collection;

/**
 * A guard's request to exchange one of his duties for one of another guard's
 * (work shifts piece 2, spec §3.2, §6), decided on the approvals engine by
 * his direct manager. The other guard is told, not asked (S13).
 *
 * Its status mirrors its approval, written only from onApprovalDecided();
 * HR's cancellation of an approved one comes after the approval has ended.
 */
class ShiftSwap extends Model implements Approvable
{
    use HasAuditLog;

    protected $fillable = [
        'requester_id', 'partner_id', 'requester_duty_date', 'partner_duty_date', 'reason', 'status',
        'decided_at', 'cancelled_by', 'cancelled_at', 'cancel_reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'requester_duty_date' => 'date',
            'partner_duty_date'   => 'date',
            'status'              => ShiftSwapStatus::class,
            'decided_at'          => 'datetime',
            'cancelled_at'        => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requester_id')->withTrashed();
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'partner_id')->withTrashed();
    }

    public function approval(): MorphOne
    {
        return $this->morphOne(Approval::class, 'approvable');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(ShiftChange::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** Exchanges where the employee is the requester or the partner. */
    public function scopeInvolving(Builder $query, int $employeeId): void
    {
        $query->where(fn (Builder $q) => $q->where('requester_id', $employeeId)->orWhere('partner_id', $employeeId));
    }

    /** Pending or approved: still holding their duties. */
    public function scopeLive(Builder $query): void
    {
        $query->whereIn('status', [ShiftSwapStatus::Pending, ShiftSwapStatus::Approved]);
    }

    // ── Approvable ───────────────────────────────────────────────────────

    public function approvalRequester(): Employee
    {
        return $this->requester;
    }

    /** The check again, but for the manager rule: the approver is fixed at filing (spec §6.2). */
    public function canBeApproved(): ?string
    {
        // Both guards' rows first, in ascending id order, as every writer of
        // their days does (HR's changes, leave, assignments): the re-check and
        // the rows the approval writes then see no change slipping in between
        // (final review I4). Inside the engine's transaction, after the
        // approval's own locks; nothing locks an employee and then an
        // approval, so the order cannot deadlock.
        $ids = [(int) $this->requester_id, (int) $this->partner_id];
        sort($ids);
        Employee::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();

        return app(ShiftSwapCheck::class)->violation($this, false);
    }

    public function onApprovalDecided(Approval $approval): void
    {
        $this->update([
            'status' => match ($approval->status) {
                ApprovalStatus::Approved  => ShiftSwapStatus::Approved,
                ApprovalStatus::Rejected  => ShiftSwapStatus::Rejected,
                ApprovalStatus::Cancelled => ShiftSwapStatus::Withdrawn,
                ApprovalStatus::Pending   => ShiftSwapStatus::Pending,
            },
            'decided_at' => $approval->decided_at,
        ]);

        match ($approval->status) {
            // The exchange's days, read from the duties as they stand now, in
            // the engine's transaction: they commit with the decision or not at all.
            ApprovalStatus::Approved  => [app(ShiftSwapPlan::class)->write($this), app(SwapNotices::class)->decided($this, true)],
            ApprovalStatus::Rejected  => app(SwapNotices::class)->decided($this, false),
            ApprovalStatus::Cancelled => app(SwapNotices::class)->withdrawn($this),
            ApprovalStatus::Pending   => null,
        };
    }

    public function describeForApprover(ApproverNotice $notice): ApproverMessage
    {
        $body = "{$this->requester->full_name} طلب تبديل مناوبة مع {$this->partner->full_name}.";

        return match ($notice) {
            ApproverNotice::Submitted       => new ApproverMessage('طلب تبديل مناوبة جديد', $body),
            ApproverNotice::YourTurn        => new ApproverMessage('دورك لمراجعة طلب تبديل مناوبة', $body),
            ApproverNotice::ReassignedNow,
            ApproverNotice::ReassignedAhead => new ApproverMessage('تم إسنادك لاعتماد طلب تبديل مناوبة', $body),
        };
    }

    public function approvalSummary(): ApprovalSummary
    {
        return new ApprovalSummary(
            'تبديل مناوبة',
            "{$this->requester->full_name} ↔ {$this->partner->full_name} · {$this->requester_duty_date->toDateString()} ⇄ {$this->partner_duty_date->toDateString()}",
        );
    }

    /** @return array<int, string> */
    public static function approvalSummaryRelations(): array
    {
        return ['requester', 'partner'];
    }

    public static function approverRowDetails(Collection $subjects, bool $decided): array
    {
        return [];
    }
}
