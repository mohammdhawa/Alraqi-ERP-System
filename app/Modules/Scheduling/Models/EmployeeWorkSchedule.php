<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Models;

use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Exceptions\SchedulingException;
use App\Modules\Scheduling\Support\AssignmentGuard;
use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person's schedule over a dated span (work shifts spec §3.1), in the
 * pattern of employee_assignments. AssignmentGuard runs on every save, so the
 * rules — today or later, no overlap, a cycle's start date — bind seeders and
 * tinker too. Closing the open span when a new one starts is
 * ScheduleAssigner's job, the one writer of new spans.
 */
class EmployeeWorkSchedule extends Model
{
    use HasAuditLog;

    protected $fillable = ['employee_id', 'work_schedule_id', 'from_date', 'to_date', 'cycle_start_date', 'created_by'];

    protected function casts(): array
    {
        return [
            'from_date'        => 'date',
            'to_date'          => 'date',
            'cycle_start_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $span): void {
            $schedule = WorkSchedule::withTrashed()->find($span->work_schedule_id)
                ?? throw new SchedulingException('الجدول المحدد غير موجود.');

            $others = static::query()
                ->where('employee_id', $span->employee_id)
                ->when($span->exists, fn (Builder $q) => $q->whereKeyNot($span->getKey()))
                ->get(['from_date', 'to_date'])
                ->map(fn (self $other): array => ['from' => $other->from_date->toDateString(), 'to' => $other->to_date?->toDateString()])
                ->all();

            $violations = AssignmentGuard::violations(
                creating: ! $span->exists,
                kind: $schedule->kind,
                scheduleActive: $schedule->is_active && ! $schedule->trashed(),
                from: $span->from_date->toDateString(),
                to: $span->to_date?->toDateString(),
                cycleStart: $span->cycle_start_date?->toDateString(),
                today: app(AttendanceCalendar::class)->today(),
                otherSpans: $others,
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

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class, 'work_schedule_id')->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The spans in force on $date. */
    public function scopeInForceOn(Builder $query, string $date): void
    {
        $query->whereDate('from_date', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('to_date')->orWhereDate('to_date', '>=', $date));
    }
}
