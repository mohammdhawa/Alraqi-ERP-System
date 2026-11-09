<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Models;

use App\Modules\Scheduling\Exceptions\SchedulingException;
use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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

        static::deleting(function (self $shift): void {
            if ($shift->isInUse()) {
                throw new SchedulingException('لا يمكن حذف وردية مستخدمة في جدول.');
            }
        });
    }

    public function isInUse(): bool
    {
        return WorkScheduleDay::query()->where('shift_id', $this->getKey())->exists();
    }

    public function endsNextDay(): bool
    {
        [$hour, $minute] = array_map('intval', explode(':', (string) $this->starts_at));

        return $hour * 60 + $minute + $this->duration_minutes > 1440;
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
