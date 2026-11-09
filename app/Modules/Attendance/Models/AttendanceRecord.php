<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Models;

use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Enums\AttendanceRecordStatus;
use App\Modules\Attendance\Enums\AttendanceSource;
use App\Modules\Attendance\Enums\RejectionReason;
use App\Modules\Attendance\Policies\AttendanceRecordPolicy;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Shared\Traits\HasAuditLog;
use Database\Factories\AttendanceRecordFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One check-in or check-out (work order §4.5; legacy's AttendanceRecord).
 *
 * An event log: the day's state is derived from these rows, never stored, so
 * the scopes below are the vocabulary every rule and report shares. The most
 * important is `accepted()` — a row HR refused is kept as evidence but must
 * behave everywhere as if it had never been recorded.
 *
 * The rules that govern WRITING a row (one check-in a day, a check-out after
 * it, the gap, leave) are in AttendanceService, not here: they read other
 * rows under a lock, which a model hook cannot hold.
 *
 * @property AttendanceEventType $type
 * @property AttendanceRecordStatus|null $status
 * @property AttendanceSource $source
 * @property RejectionReason|null $rejection_reason
 * @property Carbon $recorded_at
 * @property float|null $work_hours
 */
#[UsePolicy(AttendanceRecordPolicy::class)]
class AttendanceRecord extends Model
{
    use HasAuditLog;

    /** @use HasFactory<AttendanceRecordFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'employee_id',
        'type',
        'status',
        'source',
        'recorded_at',
        'shift_date',
        'outside_schedule',
        'checkout_reminded_at',
        'work_hours',
        'latitude',
        'longitude',
        'selfie_path',
        'selfie_purged_at',
        'client_uuid',
        'corrected_by',
        'corrected_at',
        'correction_note',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
        'rejection_note',
        'rejection_batch',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type'             => AttendanceEventType::class,
            'status'           => AttendanceRecordStatus::class,
            'source'           => AttendanceSource::class,
            'rejection_reason' => RejectionReason::class,
            'recorded_at'      => 'datetime',
            'shift_date'       => 'date',
            'outside_schedule' => 'boolean',
            'checkout_reminded_at' => 'datetime',
            'work_hours'       => 'float',
            'latitude'         => 'float',
            'longitude'        => 'float',
            'selfie_purged_at' => 'datetime',
            'corrected_at'     => 'datetime',
            'rejected_at'      => 'datetime',
        ];
    }

    /**
     * The factory lives in database/factories, not beside the model; without
     * this Laravel would look for Database\Factories\Modules\…\AttendanceRecordFactory.
     */
    protected static function newFactory(): Factory
    {
        return AttendanceRecordFactory::new();
    }

    /**
     * A record written without a shift date belongs to the date it was
     * recorded on, read in the attendance timezone. AttendanceService always
     * sets it; this is the default for everything else that writes rows
     * (factories, seeders, tinker), and it is today's grouping exactly.
     */
    protected static function booted(): void
    {
        static::creating(function (AttendanceRecord $record): void {
            if ($record->shift_date === null && $record->recorded_at !== null) {
                $timezone = (string) (config('attendance.timezone') ?: config('app.timezone'));
                $record->shift_date = $record->recorded_at->copy()->setTimezone($timezone)->toDateString();
            }
        });
    }

    /**
     * Whose day this is — trashed employees included: a departed person's
     * attendance is still payroll history, and still reviewable.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function isRejected(): bool
    {
        return $this->rejected_at !== null;
    }

    /** Legacy's "8h 30m", or null before a check-out has hours. */
    public function workHoursFormatted(): ?string
    {
        if ($this->work_hours === null) {
            return null;
        }

        $whole = (int) floor($this->work_hours);
        $minutes = (int) round(($this->work_hours - $whole) * 60);

        return "{$whole}h {$minutes}m";
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    public function scopeForEmployee(Builder $query, int $employeeId): Builder
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeCheckIns(Builder $query): Builder
    {
        return $query->where('type', AttendanceEventType::CheckIn);
    }

    public function scopeCheckOuts(Builder $query): Builder
    {
        return $query->where('type', AttendanceEventType::CheckOut);
    }

    /**
     * The events that still count. Every rule, report and hours total goes
     * through this: a refused event must behave as if it was never recorded.
     */
    public function scopeAccepted(Builder $query): Builder
    {
        return $query->whereNull('rejected_at');
    }

    /**
     * Records of the shift dated $date — the shift's date, not the calendar
     * date of the tap: a night shift's check-out belongs to the evening it
     * started (work shifts spec §2).
     */
    public function scopeOnDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('shift_date', $date);
    }

    public function scopeInDateRange(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from !== null, fn (Builder $q) => $q->whereDate('shift_date', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->whereDate('shift_date', '<=', $to));
    }

    public function scopeInMonth(Builder $query, int $month, int $year): Builder
    {
        return $query->whereYear('recorded_at', $year)->whereMonth('recorded_at', $month);
    }

    public function scopeInYear(Builder $query, int $year): Builder
    {
        return $query->whereYear('recorded_at', $year);
    }

    public function scopeMissingCheckout(Builder $query, bool $missing = true): Builder
    {
        return $missing
            ? $query->where('status', AttendanceRecordStatus::MissingCheckout)
            : $query->whereNull('status');
    }

    public function scopeRejected(Builder $query, bool $rejected = true): Builder
    {
        return $rejected ? $query->whereNotNull('rejected_at') : $query->whereNull('rejected_at');
    }

    public function scopeCorrected(Builder $query, bool $corrected = true): Builder
    {
        return $corrected ? $query->whereNotNull('corrected_by') : $query->whereNull('corrected_by');
    }

    public function scopeWorkHoursBetween(Builder $query, ?float $min, ?float $max): Builder
    {
        return $query
            ->when($min !== null, fn (Builder $q) => $q->where('work_hours', '>=', $min))
            ->when($max !== null, fn (Builder $q) => $q->where('work_hours', '<=', $max));
    }
}
