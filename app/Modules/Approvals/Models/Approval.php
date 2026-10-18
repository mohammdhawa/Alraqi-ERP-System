<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Models;

use App\Modules\Approvals\Enums\ApprovalStatus;
use App\Modules\Approvals\Enums\RejectionPolicy;
use App\Modules\Approvals\Enums\StepStatus;
use App\Modules\Approvals\Policies\ApprovalPolicy;
use App\Modules\HR\Models\Employee;
use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One subject's approval (design §3) — a leave request now, a document in
 * November.
 *
 * Written only by ApprovalService. The current step is not stored: it is the
 * lowest `step_order` holding a pending step (`currentOrder()`), so it can
 * never disagree with the steps.
 *
 * @property ApprovalStatus $status
 * @property RejectionPolicy $rejection_policy
 * @property Carbon|null $decided_at
 */
#[UsePolicy(ApprovalPolicy::class)]
class Approval extends Model
{
    use HasAuditLog;
    use SoftDeletes;

    protected $fillable = [
        'approvable_type',
        'approvable_id',
        'requested_by',
        'status',
        'rejection_policy',
        'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status'           => ApprovalStatus::class,
            'rejection_policy' => RejectionPolicy::class,
            'decided_at'       => 'datetime',
        ];
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Whose request it is — trashed employees included: a departed person's
     * request is still a record of who decided what.
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by')->withTrashed();
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class)->orderBy('step_order')->orderBy('id');
    }

    public function reassignments(): HasMany
    {
        return $this->hasMany(ApprovalReassignment::class)->orderBy('id');
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalStatus::Pending;
    }

    /**
     * The order whose pending steps may act now, or null when none is left.
     * Reads the loaded `steps`.
     */
    public function currentOrder(): ?int
    {
        $order = $this->steps
            ->filter(static fn (ApprovalStep $step): bool => $step->status === StepStatus::Pending)
            ->min('step_order');

        return $order === null ? null : (int) $order;
    }
}
