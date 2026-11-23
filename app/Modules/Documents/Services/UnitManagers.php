<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who manages which unit, and where a unit's documents are registered
 * (documents design §4, §8.4). A person manages at most one unit, so the unit
 * of a manager is a single row.
 */
final class UnitManagers
{
    /**
     * The non-deleted, active unit this non-deleted employee manages, or null
     * when they manage none. A deleted employee manages none, though HR's soft
     * delete leaves them as the unit's manager_id. An inactive unit has no
     * manager here, though it still counts for the one-unit rule; the root is
     * the exception, its manager is المدير العام whatever its flag (spec §7.8).
     */
    public function unitOf(Employee $employee): ?Department
    {
        if ($employee->trashed()) {
            return null;
        }

        return Department::query()
            ->where('manager_id', $employee->id)
            ->where(static fn (Builder $query) => $query
                ->where('is_active', true)
                ->orWhere('level', DepartmentLevel::GeneralAdministration->value))
            ->orderBy('id')
            ->first();
    }

    /** The manager of the root unit, or null while the post is vacant. */
    public function generalManager(): ?Employee
    {
        $root = Department::query()
            ->where('level', DepartmentLevel::GeneralAdministration->value)
            ->first();

        return $root?->manager;
    }

    /**
     * The unit that keeps the register: the unit itself at Division level or
     * above, otherwise its nearest Division ancestor.
     */
    public function registerOf(Department $unit): Department
    {
        $unit->loadAncestors();

        $register = $unit;

        while ($register->level->value > DepartmentLevel::Division->value && $register->parent !== null) {
            $register = $register->parent;
        }

        return $register;
    }
}
