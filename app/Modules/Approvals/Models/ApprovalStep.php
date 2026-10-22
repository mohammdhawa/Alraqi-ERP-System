<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Models;

use App\Modules\Approvals\Enums\SkipReason;
use App\Modules\Approvals\Enums\StepResolver;
use App\Modules\Approvals\Enums\StepStatus;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One approver's step (design §3). Steps sharing a `step_order` are parallel;
 * ascending orders are sequential.
 *
 * `approver_id` is a person, not an account — units name employees as
 * managers. `acted_by` is the account that pressed the button.
 *
 * @property StepResolver $resolver
 * @property StepStatus $status
 * @property SkipReason|null $skip_reason
 * @property bool $decisive
 * @property Carbon|null $acted_at
 */
class ApprovalStep extends Model
{
    use HasAuditLog;
    use SoftDeletes;

    protected $fillable = [
        'approval_id',
        'step_order',
        'resolver',
        'resolver_ref',
        'approver_id',
        'decisive',
        'status',
        'skip_reason',
        'acted_by',
        'acted_at',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'step_order'  => 'integer',
            'resolver'    => StepResolver::class,
            'decisive'    => 'boolean',
            'status'      => StepStatus::class,
            'skip_reason' => SkipReason::class,
            'acted_at'    => 'datetime',
        ];
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approver_id')->withTrashed();
    }

    public function actedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }

    public function reassignments(): HasMany
    {
        return $this->hasMany(ApprovalReassignment::class)->orderBy('id');
    }

    public function isPending(): bool
    {
        return $this->status === StepStatus::Pending;
    }
}
