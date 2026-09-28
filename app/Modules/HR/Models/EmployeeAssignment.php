<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Modules\Departments\Models\Department;
use App\Modules\HR\Enums\AssignmentReason;
use App\Modules\HR\Exceptions\AssignmentConflictException;
use App\Modules\HR\Support\HireDateGuard;
use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * EmployeeAssignment Model — سجل التعيين
 *
 * One posting: the unit, title and grade a person held, from one date to
 * another. The row with `to_date IS NULL` is their CURRENT posting; the first
 * row by `from_date` is their initial one.
 *
 * This replaces the workbook's three frozen `بداية التعيين` columns and the
 * separate ملف النقل transfer sheet — see the migration for why three columns
 * could never answer "where were they in March?".
 *
 * ───────────────────────────────────────────────────────────────────────────
 * THE SYNCHRONISATION PAIR — read this with Employee::booted()
 * ───────────────────────────────────────────────────────────────────────────
 * `employees.department_id`, `.job_title_id` and `.organizational_grade` are a
 * denormalised copy of whatever the open assignment says. They are kept because
 * they sit on every hot query path; the copy is made safe by two hooks that
 * every writer crosses, one in each direction (P2):
 *
 *   HERE                 assignment saved with to_date NULL
 *                          -> write those three columns onto the employee
 *
 *   Employee::booted()   employee saved with one of them changed
 *                          -> record it as a posting change
 *
 * THE RECURSION IS BROKEN BY THE WRITE METHOD, NOT BY A FLAG. This hook updates
 * the employee through `DB::table()`, which fires no model events, so
 * Employee::saved cannot bounce the change back here. That is the same
 * technique — and the same reason — as AuditLogService writing its rows with
 * `DB::table()` so that auditing cannot recurse into auditing itself. A static
 * "I am currently syncing" flag would also work and is worse: it is invisible
 * at the call site, and it leaks across a queue worker's jobs.
 *
 * THE COST, STATED HONESTLY: because the employee row is updated without model
 * events, that particular update produces no `audit_logs` entry. It is not lost
 * history — the assignment write itself is audited, and the assignment IS the
 * event. The employee columns are a cache being refreshed, and auditing a cache
 * refresh as though it were a decision would make the trail harder to read, not
 * easier.
 */
class EmployeeAssignment extends Model
{
    use HasAuditLog;
    use SoftDeletes;

    /**
     * The employee columns that mirror the open assignment. Named once, because
     * both directions of the sync read this list and a fourth column added to
     * one side only is exactly the drift the pair exists to prevent.
     *
     * @var array<int, string>
     */
    public const SYNCED_COLUMNS = ['department_id', 'job_title_id', 'organizational_grade'];

    protected $fillable = [
        'employee_id',
        'department_id',
        'job_title_id',
        'organizational_grade',
        'from_date',
        'to_date',
        'reason',
        'legacy_workplace',
        'legacy_manager_name',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_date'            => 'date',
            'to_date'              => 'date',
            'reason'               => AssignmentReason::class,
            'organizational_grade' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $assignment): void {
            $assignment->guardDateOrder();
            $assignment->guardStartsAfterHire();
            $assignment->guardSingleOpenAssignment();
        });

        static::saved(function (self $assignment): void {
            $assignment->syncEmployeePointer();
        });
    }

    /**
     * A posting cannot end before it begins.
     */
    private function guardDateOrder(): void
    {
        if ($this->from_date !== null && $this->to_date !== null && $this->to_date->lt($this->from_date)) {
            throw new AssignmentConflictException(
                'تاريخ انتهاء التعيين لا يمكن أن يسبق تاريخ بدايته.',
            );
        }
    }

    /**
     * No posting starts before the person was hired (GAP-19, HireDateGuard).
     *
     * Only when the start date is being WRITTEN — a new row, or a moved
     * `from_date`. Closing a posting or restating its unit leaves its start
     * alone, and must not be refused over a start date that predates this rule.
     */
    private function guardStartsAfterHire(): void
    {
        if ($this->from_date === null || ($this->exists && ! $this->isDirty('from_date'))) {
            return;
        }

        $violation = (new HireDateGuard)->postingViolation(
            HireDateGuard::storedHireDate((int) $this->employee_id),
            $this->from_date,
        );

        if ($violation !== null) {
            throw new AssignmentConflictException($violation->message);
        }
    }

    /**
     * At most one CURRENT posting per employee.
     *
     * Not an index, because "unique among the rows where this column is NULL"
     * is a partial unique index and MySQL has none. The rule matters more than
     * its tidiness suggests: two open assignments give the employee's
     * denormalised pointer two sources, and the last writer wins at random.
     */
    private function guardSingleOpenAssignment(): void
    {
        if ($this->to_date !== null) {
            return;
        }

        $conflict = static::query()
            ->where('employee_id', $this->employee_id)
            ->whereNull('to_date')
            ->when($this->exists, fn (Builder $query) => $query->whereKeyNot($this->getKey()))
            ->exists();

        if ($conflict) {
            throw new AssignmentConflictException;
        }
    }

    /**
     * Push this posting onto the employee's denormalised pointer columns.
     *
     * Only the OPEN posting is the current truth — writing a closed one would
     * move an employee back to where they used to work. Uses DB::table() so no
     * model event fires and Employee::saved cannot bounce back here; see the
     * class docblock.
     */
    private function syncEmployeePointer(): void
    {
        if ($this->to_date !== null) {
            return;
        }

        DB::table('employees')
            ->where('id', $this->employee_id)
            ->update([
                'department_id'        => $this->department_id,
                'job_title_id'         => $this->job_title_id,
                'organizational_grade' => $this->organizational_grade,
                'updated_at'           => now(),
            ]);
    }

    /**
     * The person this posting belongs to.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The unit held at the time.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The title held at the time.
     */
    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class);
    }

    /**
     * Only the current posting (to_date IS NULL).
     *
     * @param  Builder<self>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('to_date');
    }

    /**
     * The postings in force on a given date — the query the three frozen
     * workbook columns could not answer, and the one payroll runs for every
     * employee every month.
     *
     * ⚠️ whereDate(), NOT a plain comparison, and this scope is the reason it
     * matters most. Laravel writes a `date` cast through the connection's
     * datetime format, so `from_date` holds "2026-06-01 00:00:00" under SQLite
     * while MySQL's DATE type truncates it to "2026-06-01". Compared as strings
     * against "2026-06-01", the SQLite value is GREATER — so a posting would be
     * treated as not yet started on its own first day, on the test driver only.
     * That is a discrepancy that makes the suite agree with a bug instead of
     * catching it. whereDate normalises both sides on both drivers.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOnDate(Builder $query, string $date): void
    {
        $query->whereDate('from_date', '<=', $date)
            ->where(function (Builder $inner) use ($date): void {
                $inner->whereNull('to_date')->orWhereDate('to_date', '>=', $date);
            });
    }
}
