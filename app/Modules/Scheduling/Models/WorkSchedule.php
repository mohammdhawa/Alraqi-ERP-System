<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Models;

use App\Modules\Scheduling\Enums\WorkScheduleKind;
use App\Modules\Scheduling\Exceptions\SchedulingException;
use App\Modules\Scheduling\Support\ScheduleGuard;
use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Which shift falls on which day (work shifts spec §2, §3.1).
 *
 * ITS DAYS ARE WRITTEN AS A SET, through createWithDays() and replaceDays(),
 * so ScheduleGuard sees all of them at once. Once anyone is assigned the
 * schedule its days are fixed and it cannot be deleted: people's past days
 * were judged by it. A change is a new schedule, assigned from a date.
 */
class WorkSchedule extends Model
{
    use HasAuditLog;
    use SoftDeletes;

    protected $fillable = ['code', 'name', 'kind', 'cycle_days', 'is_active'];

    protected function casts(): array
    {
        return [
            'kind'       => WorkScheduleKind::class,
            'cycle_days' => 'integer',
            'is_active'  => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $schedule): void {
            if ($schedule->isDefault() && ($schedule->isDirty(['kind', 'cycle_days', 'code']) || ($schedule->isDirty('is_active') && ! $schedule->is_active))) {
                throw new SchedulingException(self::DEFAULT_FIXED);
            }
            if ($schedule->isDirty(['kind', 'cycle_days']) && $schedule->isAssigned()) {
                throw new SchedulingException(self::ASSIGNED_DAYS);
            }
        });

        static::deleting(function (self $schedule): void {
            if ($schedule->isDefault()) {
                throw new SchedulingException(self::DEFAULT_FIXED);
            }
            if ($schedule->isAssigned()) {
                throw new SchedulingException('لا يمكن حذف جدول مُسند لموظفين.');
            }
        });
    }

    public const ASSIGNED_DAYS = 'لا يمكن تعديل أيام جدول مُسند لموظفين؛ أنشئ جدولاً جديداً.';

    public const DEFAULT_FIXED = 'لا يمكن حذف الجدول الافتراضي أو إيقافه أو تعديل أيامه.';

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, int|null>  $days  position => shift id, or null for a day off
     */
    public static function createWithDays(array $attributes, array $days): self
    {
        $kind = $attributes['kind'] instanceof WorkScheduleKind ? $attributes['kind'] : WorkScheduleKind::from((int) $attributes['kind']);
        self::assertDays($kind, (int) $attributes['cycle_days'], $days);

        return DB::transaction(function () use ($attributes, $days): self {
            $schedule = self::create($attributes);
            $schedule->writeDays($days);

            return $schedule;
        });
    }

    /** @param  array<int, int|null>  $days */
    public function replaceDays(array $days): void
    {
        if ($this->isDefault()) {
            throw new SchedulingException(self::DEFAULT_FIXED);
        }
        if ($this->isAssigned()) {
            throw new SchedulingException(self::ASSIGNED_DAYS);
        }
        self::assertDays($this->kind, $this->cycle_days, $days);

        DB::transaction(function () use ($days): void {
            $this->days()->delete();
            $this->writeDays($days);
        });
    }

    /**
     * The schedule of everyone with none assigned (config
     * attendance.default_schedule): in use by everyone, though no span names
     * it, so it is never deleted, deactivated or re-drawn (final review C1).
     */
    public function isDefault(): bool
    {
        return $this->code === config('attendance.default_schedule');
    }

    public function isAssigned(): bool
    {
        return EmployeeWorkSchedule::query()->where('work_schedule_id', $this->getKey())->exists();
    }

    public function days(): HasMany
    {
        return $this->hasMany(WorkScheduleDay::class)->orderBy('position');
    }

    /** @param  array<int, int|null>  $days */
    private static function assertDays(WorkScheduleKind $kind, int $cycleDays, array $days): void
    {
        $ids = array_values(array_filter($days, static fn ($id): bool => $id !== null));
        $shifts = Shift::query()->whereKey($ids)->get()
            ->mapWithKeys(fn (Shift $shift): array => [$shift->id => $shift->guardFacts()])->all();

        $violations = ScheduleGuard::violations($kind, $cycleDays, $days, $shifts);
        if ($violations !== []) {
            throw new SchedulingException($violations[0]);
        }
    }

    /** @param  array<int, int|null>  $days */
    private function writeDays(array $days): void
    {
        ksort($days);
        foreach ($days as $position => $shiftId) {
            $this->days()->create(['position' => (int) $position, 'shift_id' => $shiftId]);
        }
    }
}
