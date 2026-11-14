<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Models;

use App\Modules\Attendance\Support\ShiftRules;
use App\Modules\Scheduling\Exceptions\SchedulingException;
use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

/**
 * A start time and a length (work shifts spec §2, §3.1): DAY is 09:00 for 420
 * minutes, GUARD 08:00 for 1440. One past midnight ends the next day.
 *
 * FIXED ONCE USED. A schedule that uses this shift has dated people's days
 * by it, so its start and length cannot change, and it cannot be deleted —
 * a new time is a new shift. Its name and is_active always can.
 */
class Shift extends Model
{
    use HasAuditLog;
    use SoftDeletes;

    protected $fillable = ['code', 'name', 'starts_at', 'duration_minutes', 'is_active'];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'is_active'        => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $shift): void {
            if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $shift->starts_at)) {
                throw new SchedulingException('وقت بدء الوردية يجب أن يكون بالصيغة HH:MM.');
            }
            if ($shift->duration_minutes < 1 || $shift->duration_minutes > 1440) {
                throw new SchedulingException('مدة الوردية من دقيقة واحدة إلى 24 ساعة.');
            }
        });

        static::updating(function (self $shift): void {
            if ($shift->isDirty(['starts_at', 'duration_minutes']) && $shift->isInUse()) {
                throw new SchedulingException('لا يمكن تعديل وقت وردية مستخدمة في جدول؛ أنشئ وردية جديدة.');
            }
        });

        // An unused shift's length may still change (a used one's cannot, above):
        // its versions' required minutes follow it — "the whole shift" stays the
        // whole shift, and none may exceed the new length (spec §2.3).
        static::updated(function (self $shift): void {
            if (! $shift->wasChanged('duration_minutes')) {
                return;
            }
            $old = (int) $shift->getOriginal('duration_minutes');
            $new = (int) $shift->duration_minutes;
            $shift->rules()->where('required_minutes', $old)->update(['required_minutes' => $new]);
            $shift->rules()->where('required_minutes', '>', $new)->update(['required_minutes' => $new]);
            $shift->unsetRelation('rules');
        });

        // Every shift has rules from its first day (per-shift rules spec §2.2).
        // The 2026-11-10 data migration creates DAY and GUARD before the
        // table exists; the 2026-11-12 backfill gives those theirs.
        static::created(function (self $shift): void {
            if (! Schema::hasTable('shift_rules')) {
                return;
            }
            $shift->rules()->create(ShiftRules::defaults((int) $shift->duration_minutes)->toArray() + [
                'effective_from' => ShiftRules::FIRST_DAY,
            ]);
        });

        static::deleting(function (self $shift): void {
            if ($shift->isInUse()) {
                throw new SchedulingException('لا يمكن حذف وردية مستخدمة في جدول.');
            }
        });
    }

    public function isInUse(): bool
    {
        return WorkScheduleDay::query()->where('shift_id', $this->getKey())->exists()
            || ShiftChange::query()->where('shift_id', $this->getKey())->exists();
    }

    public function endsNextDay(): bool
    {
        [$hour, $minute] = array_map('intval', explode(':', (string) $this->starts_at));

        return $hour * 60 + $minute + $this->duration_minutes > 1440;
    }

    /** The shift's rule versions, oldest first. */
    public function rules(): HasMany
    {
        return $this->hasMany(ShiftRule::class)->orderBy('effective_from');
    }

    /**
     * The rules in force on a date: the latest version on or before it. Reads
     * the loaded versions when the relation is loaded (no query per day); a
     * shift with none — one built in memory — gets the company defaults.
     */
    public function rulesOn(string $date): ShiftRules
    {
        // Loaded once and kept: a shift shared by many schedule days or changes
        // costs one query, not one per read (final review I2).
        if (! $this->relationLoaded('rules')) {
            $this->load('rules');
        }
        $versions = $this->rules;

        $inForce = $versions
            ->filter(fn (ShiftRule $rule): bool => $rule->effective_from->toDateString() <= $date)
            ->sortBy(fn (ShiftRule $rule): string => $rule->effective_from->toDateString())
            ->last();

        return $inForce?->toValue() ?? ShiftRules::defaults((int) $this->duration_minutes);
    }

    /** @return array{starts_at: string, duration_minutes: int, is_active: bool} */
    public function guardFacts(): array
    {
        return [
            'starts_at'        => (string) $this->starts_at,
            'duration_minutes' => (int) $this->duration_minutes,
            'is_active'        => (bool) $this->is_active,
        ];
    }
}
