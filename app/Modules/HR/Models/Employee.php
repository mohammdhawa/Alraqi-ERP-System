<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Departments\Models\Department;
use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Employee Model
 *
 * The HR profile of a person: contact details, department, role, pay, and
 * employment status. An employee may or may not have a matching User login.
 *
 * WHY HasAuditLog:
 * - Employee records carry sensitive, change-worthy data (salary, status,
 *   department). The audit trail must capture who changed what (§16.6, §16.14).
 *
 * WHY SoftDeletes:
 * - A person is FK'd to by users (employee_id), departments (manager_id), and
 *   later modules (payroll, attendance, projects). Hard-deleting them would null
 *   those links and destroy history payroll/audit still need. Deletion sets
 *   deleted_at; the row survives and every reference stays resolvable.
 *
 * IDENTITY:
 * - employee_number is the stable, unique staff identifier. It is auto-generated
 *   (EMP-00001, …) by the creating hook when a writer omits it, so EVERY path —
 *   API, seeder, factory, tinker — produces one; a caller may still supply an
 *   externally-assigned number, which is kept as-is.
 *
 * RELATIONS:
 * - department(): the department this employee belongs to.
 * - user(): the login account linked to this employee (inverse of
 *   User::employee()). hasOne because a user holds the employee_id FK.
 */
class Employee extends Model
{
    use HasAuditLog;
    use SoftDeletes;

    protected $fillable = [
        'employee_number',
        'name',
        'national_id',
        'phone',
        'email',
        'address',
        'department_id',
        'job_title',
        'hire_date',
        'salary',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'salary'    => 'decimal:2',
        ];
    }

    /**
     * Auto-assign employee_number on any create that omits it, so the number is
     * present on every write path (not just the service/API). Lives in the model
     * — the one layer all writers cross — mirroring how the Department hierarchy
     * guard is enforced in a model hook rather than only in the form request.
     *
     * A supplied number is respected. The generated form is EMP- + the next id,
     * zero-padded; withTrashed() so a soft-deleted employee's number is never
     * reissued. The UNIQUE index is the final backstop against a concurrent
     * collision (the loser's insert fails rather than duplicating a number).
     */
    protected static function booted(): void
    {
        static::creating(function (self $employee): void {
            if (blank($employee->employee_number)) {
                $employee->employee_number = self::nextEmployeeNumber();
            }
        });
    }

    /**
     * The next sequential staff number, e.g. "EMP-00001".
     */
    private static function nextEmployeeNumber(): string
    {
        $next = (int) (self::withTrashed()->max('id') ?? 0) + 1;

        return 'EMP-' . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    /**
     * The department this employee belongs to.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The login account linked to this employee, if any.
     *
     * The FK (employee_id) lives on the users table, so this is a hasOne.
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }
}
