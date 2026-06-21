<?php

declare(strict_types=1);

namespace App\Modules\Departments\Models;

use App\Modules\HR\Models\Employee;
use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Department Model
 *
 * A department groups employees under an organizational unit and optionally
 * points at the employee who manages it.
 *
 * Departments are their own module (not folded into HR) so the aggregate owns
 * its routes, schema, and lifecycle independently.
 *
 * WHY HasAuditLog:
 * - Departments are low-frequency, high-importance records. Who renamed a
 *   department or reassigned its manager is exactly the kind of change the
 *   audit trail exists to capture (architecture report §16.6, §16.14).
 *
 * RELATIONS:
 * - employees(): the staff that belong to this department.
 * - manager(): the employee in charge of this department.
 *
 * NOTE ON Employee: the Employee model and its table live in the HR module
 * (App\Modules\HR\Models\Employee). The relation is a deliberate cross-module
 * reference — Departments is its own aggregate, HR owns the people.
 */
class Department extends Model
{
    use HasAuditLog;

    protected $fillable = [
        'name',
        'manager_id',
    ];

    /**
     * Employees that belong to this department.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * The employee who manages this department.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }
}
