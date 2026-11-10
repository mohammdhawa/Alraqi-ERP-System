<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Models;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Enums\ShiftChangeOrigin;
use App\Modules\Scheduling\Exceptions\SchedulingException;
use App\Modules\Scheduling\Support\ShiftChangeGuard;
use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person's shift on one date, overriding their schedule (work shifts piece
 * 2, spec §3.1): a shift, or null for a day off. The resolver reads it
 * before any assignment (spec §4.1).
 *
 * ONE PER PERSON PER DATE. HR replacing a change updates the row, so the
 * audit log holds the old and the new; removing one deletes the row.
 * ShiftChangeGuard runs on every save, so seeders and tinker are bound too.
 */
class ShiftChange extends Model
{
    use HasAuditLog;

    protected $fillable = ['employee_id', 'date', 'shift_id', 'origin', 'shift_swap_id', 'reason', 'created_by'];

    protected function casts(): array
    {
        return [
            'date'   => 'date',
            'origin' => ShiftChangeOrigin::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $change): void {
            $checksShift = $change->shift_id !== null && (! $change->exists || $change->isDirty('shift_id'));
            $active = $checksShift ? (bool) Shift::query()->whereKey($change->shift_id)->value('is_active') : null;

            $violations = ShiftChangeGuard::violations(
                $change->origin,
                $change->shift_swap_id === null ? null : (int) $change->shift_swap_id,
                $change->reason,
                $active,
            );

            if ($violations !== []) {
                throw new SchedulingException($violations[0]);
            }
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class)->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param  array<int, int>  $ids */
    public function scopeForEmployees(Builder $query, array $ids): void
    {
        $query->whereIn('employee_id', $ids);
    }

    public function scopeBetween(Builder $query, string $from, string $to): void
    {
        $query->whereDate('date', '>=', $from)->whereDate('date', '<=', $to);
    }
}
