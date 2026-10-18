<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Models;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One hand-off of a pending step — legacy's leave_approval_reassignments,
 * generalised (design §6). A table, not a pair of columns on the step, so
 * every hop survives.
 */
class ApprovalReassignment extends Model
{
    use HasAuditLog;
    use SoftDeletes;

    protected $fillable = [
        'approval_id',
        'approval_step_id',
        'step_order',
        'from_employee_id',
        'to_employee_id',
        'performed_by',
        'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'step_order' => 'integer',
        ];
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(ApprovalStep::class, 'approval_step_id');
    }

    public function fromEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_employee_id')->withTrashed();
    }

    public function toEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_employee_id')->withTrashed();
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
