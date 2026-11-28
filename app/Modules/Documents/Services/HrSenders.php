<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Departments\Models\Department;
use App\Modules\HR\Models\Employee;

/**
 * Who may send a document to an employee: the manager of the HR unit or of any
 * unit under it. With no HR unit, nobody sends.
 *
 * Membership follows the unit tree as stored: an inactive unit between the
 * manager's unit and HR still counts, and only the manager's OWN unit must be
 * active (`UnitManagers::unitOf` ensures it). A soft-deleted unit between them
 * cannot occur, since the Department model refuses to soft-delete a unit that
 * has live children, so the chain never breaks there.
 */
final class HrSenders
{
    public function __construct(private readonly UnitManagers $managers = new UnitManagers) {}

    /** The live unit whose code is the HR unit's, or null when there is none. */
    public function hrUnit(): ?Department
    {
        return Department::query()
            ->where('code', (string) config('leave.hr_unit_code'))
            ->orderBy('id')
            ->first();
    }

    public function isSender(Employee $employee): bool
    {
        return $this->senderUnit($employee) !== null;
    }

    /** The unit the employee manages when it is HR or under it, else null: not a sender. */
    public function senderUnit(Employee $employee): ?Department
    {
        $hr = $this->hrUnit();

        if ($hr === null) {
            return null;
        }

        $unit = $this->managers->unitOf($employee);

        if ($unit === null) {
            return null;
        }

        if ($unit->is($hr)) {
            return $unit;
        }

        $unit->loadAncestors();

        for ($parent = $unit->parent; $parent !== null; $parent = $parent->parent) {
            if ($parent->is($hr)) {
                return $unit;
            }
        }

        return null;
    }
}
