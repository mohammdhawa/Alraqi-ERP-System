<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Departments\Models\Department;
use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
 * RELATIONS:
 * - department(): the department this employee belongs to.
 * - user(): the login account linked to this employee (inverse of
 *   User::employee()). hasOne because a user holds the employee_id FK.
 */
class Employee extends Model
{
    use HasAuditLog;

    protected $fillable = [
        'name',
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
