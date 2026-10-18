<?php

declare(strict_types=1);

namespace App\Modules\Leave\Models;

use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A kind of leave HR has defined — legacy's LeaveType, plus
 * `requires_attachment` (LEAVE_BUILD.md §3).
 *
 * Types split in two, as legacy's: balance-consuming (annual, emergency) and
 * justifying but free (sick, unpaid, a mission). A request snapshots
 * `deducts_balance` when it is filed, so editing a type never rewrites the
 * balance of leave already taken under the old policy.
 *
 * @property bool $deducts_balance
 * @property bool $for_requests
 * @property bool $for_excuses
 * @property bool $requires_reason
 * @property bool $requires_attachment
 * @property bool $is_active
 */
class LeaveType extends Model
{
    use HasAuditLog;
    use SoftDeletes;

    protected $fillable = [
        'code',
        'name_ar',
        'name_en',
        'deducts_balance',
        'for_requests',
        'for_excuses',
        'requires_reason',
        'requires_attachment',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deducts_balance'     => 'boolean',
            'for_requests'        => 'boolean',
            'for_excuses'         => 'boolean',
            'requires_reason'     => 'boolean',
            'requires_attachment' => 'boolean',
            'is_active'           => 'boolean',
        ];
    }

    /**
     * Every leave filed under this type — what makes a delete a deactivation
     * instead (legacy).
     */
    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /**
     * Whether this type may be picked on a form: retired types are off every
     * picker, and a type may be offered to HR but not to employees — an
     * official mission is recorded, not requested (legacy).
     */
    public function isAvailableFor(bool $forExcuse): bool
    {
        return $this->is_active && ($forExcuse ? $this->for_excuses : $this->for_requests);
    }

    /** The name shown on a request, in reports and in notifications. */
    public function label(): string
    {
        return $this->name_ar;
    }

    /**
     * @param  Builder<LeaveType>  $query
     * @return Builder<LeaveType>
     */
    public function scopeActive(Builder $query, bool $active = true): Builder
    {
        return $query->where('is_active', $active);
    }

    /**
     * @param  Builder<LeaveType>  $query
     * @return Builder<LeaveType>
     */
    public function scopeForRequests(Builder $query): Builder
    {
        return $query->where('for_requests', true);
    }

    /**
     * @param  Builder<LeaveType>  $query
     * @return Builder<LeaveType>
     */
    public function scopeForExcuses(Builder $query): Builder
    {
        return $query->where('for_excuses', true);
    }

    /**
     * @param  Builder<LeaveType>  $query
     * @return Builder<LeaveType>
     */
    public function scopeDeducting(Builder $query, bool $deducts = true): Builder
    {
        return $query->where('deducts_balance', $deducts);
    }

    /**
     * The order every picker uses — the order the types were created in
     * (legacy: no `sort_order` column to drift).
     *
     * @param  Builder<LeaveType>  $query
     * @return Builder<LeaveType>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('id');
    }
}
