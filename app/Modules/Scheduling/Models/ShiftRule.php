<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Models;

use App\Modules\Attendance\Support\ShiftRules;
use App\Modules\Auth\Models\User;
use App\Shared\Traits\HasAuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dated version of a shift's attendance rules (per-shift rules spec §2).
 * In force from effective_from until the next version; a past day keeps the
 * version it had.
 */
class ShiftRule extends Model
{
    use HasAuditLog;

    protected $fillable = [
        'shift_id', 'effective_from',
        'late_grace_minutes', 'early_leave_grace_minutes',
        'check_in_opens_minutes', 'check_out_closes_minutes',
        'overtime_enabled', 'overtime_min_minutes', 'required_minutes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from'            => 'date',
            'late_grace_minutes'        => 'integer',
            'early_leave_grace_minutes' => 'integer',
            'check_in_opens_minutes'    => 'integer',
            'check_out_closes_minutes'  => 'integer',
            'overtime_enabled'          => 'boolean',
            'overtime_min_minutes'      => 'integer',
            'required_minutes'          => 'integer',
        ];
    }

    /**
     * Stored as a plain Y-m-d date, the same as the backfill migration writes,
     * so the (shift_id, effective_from) unique index compares like with like on
     * every database (SQLite would otherwise hold "Y-m-d H:i:s" beside "Y-m-d").
     */
    protected function effectiveFrom(): Attribute
    {
        return Attribute::make(
            set: fn (mixed $value): string => CarbonImmutable::parse($value)->toDateString(),
        );
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function toValue(): ShiftRules
    {
        return new ShiftRules(
            lateGraceMinutes: $this->late_grace_minutes,
            earlyLeaveGraceMinutes: $this->early_leave_grace_minutes,
            checkInOpensMinutes: $this->check_in_opens_minutes,
            checkOutClosesMinutes: $this->check_out_closes_minutes,
            overtimeEnabled: $this->overtime_enabled,
            overtimeMinMinutes: $this->overtime_min_minutes,
            requiredMinutes: $this->required_minutes,
        );
    }
}
