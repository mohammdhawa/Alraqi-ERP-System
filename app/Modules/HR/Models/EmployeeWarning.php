<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * EmployeeWarning Model — الإنذار
 *
 * One disciplinary warning issued to an employee.
 *
 * WHY ROWS RATHER THAN THE WORKBOOK'S THREE COLUMNS: نوع الإنذار، تاريخ الإنذار
 * and رقم الإنذار sat on the employee row, so an employee could hold exactly one
 * warning forever and the second one erased the first. Discipline is a sequence
 * by nature — the entire meaning of a third warning is that there were two
 * before it.
 *
 * `sequence` IS DERIVED, NEVER STORED. "Is this their third?" is a count of the
 * warnings issued to them on or before this one (see `sequence()`), which stays
 * correct when a mis-filed warning is deleted or a back-dated one is added. A
 * stored counter is wrong the first time someone deletes warning #2 of 3 (P1).
 */
class EmployeeWarning extends Model
{
    use HasAuditLog;
    use SoftDeletes;

    protected $fillable = [
        'employee_id',
        'type',
        'number',
        'issued_on',
        'issued_by',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
        ];
    }

    /**
     * The employee who received it.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The manager who issued it — an employee rather than a user, because a
     * warning is signed by a line manager who may well have no login.
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'issued_by');
    }

    /**
     * Which warning this is in the employee's sequence: 1 for their first.
     *
     * Counted at read time from the live rows, so deleting or back-dating a
     * warning renumbers the rest automatically. Ties on `issued_on` are broken
     * by id, so two warnings issued the same day still get distinct numbers and
     * the earlier-created one is first.
     *
     * ⚠️ whereDate(), NOT a plain comparison. Laravel writes a `date` cast
     * through the connection's datetime format, so this column holds
     * "2026-03-05 00:00:00" under SQLite while MySQL's DATE type truncates it to
     * "2026-03-05". A plain `where('issued_on', $date)` therefore MATCHES IN
     * PRODUCTION AND SILENTLY MATCHES NOTHING IN THE TEST SUITE — the worst
     * direction for a discrepancy to run, since it makes the tests agree with a
     * bug rather than catch one. whereDate normalises both sides on both
     * drivers.
     */
    public function sequence(): int
    {
        if ($this->issued_on === null) {
            return 1;
        }

        $issuedOn = $this->issued_on->toDateString();

        return static::query()
            ->where('employee_id', $this->employee_id)
            ->where(function (Builder $query) use ($issuedOn): void {
                $query->whereDate('issued_on', '<', $issuedOn)
                    ->orWhere(function (Builder $tie) use ($issuedOn): void {
                        $tie->whereDate('issued_on', '=', $issuedOn)
                            ->where('id', '<=', $this->getKey());
                    });
            })
            ->count();
    }
}
