<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Models;

use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One position of a schedule: a shift, or a day off when shift_id is null.
 * Written only through WorkSchedule::createWithDays() and replaceDays(),
 * which check the whole set (positions complete, no run of more than three).
 */
class WorkScheduleDay extends Model
{
    use HasAuditLog;

    protected $fillable = ['work_schedule_id', 'position', 'shift_id'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'shift_id' => 'integer'];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class, 'work_schedule_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class)->withTrashed();
    }
}
