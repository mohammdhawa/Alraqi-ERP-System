<?php

declare(strict_types=1);

namespace App\Modules\Leave\Models;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's allocation for one year, set by HR — legacy's LeaveBalance.
 * No row means the company default applies (LeaveBalances).
 *
 * Not soft-deleted: a balance is corrected in place, and the audit trail keeps
 * every earlier value.
 */
class LeaveBalance extends Model
{
    use HasAuditLog;

    protected $fillable = [
        'employee_id',
        'year',
        'allocated_days',
        'adjustment_days',
        'note',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year'            => 'integer',
            'allocated_days'  => 'integer',
            'adjustment_days' => 'integer',
        ];
    }

    /** Allocation plus carry-over or penalty — what may actually be taken. */
    public function totalDays(): int
    {
        return max(0, $this->allocated_days + $this->adjustment_days);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
