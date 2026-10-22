<?php

declare(strict_types=1);

namespace Tests\Feature\Leave\Concerns;

use App\Modules\Auth\Models\User;
use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\LeaveType;
use Laravel\Sanctum\Sanctum;

/**
 * The tree the leave tests file and approve over — the approvals engine's,
 * plus a unit B outside unit A's reach:
 *
 *   GM   الإدارة العامة               gm manages it
 *   ├── HR  إدارة الموارد البشرية      hrHead manages it; hrStaff works in it
 *   ├── A   إدارة أ                    headA manages it
 *   │   └── A1  قسم أ-1  (vacant)      staff works in it
 *   └── B   إدارة ب                    bStaff works in it
 *
 * Leave's chain over it (Q1 = B): staff → headA (past the vacant A1) → hrHead.
 * Everyone has an active account.
 */
trait LeaveFixtures
{
    /** @var array<string, Department> */
    protected array $units = [];

    /** @var array<string, Employee> */
    protected array $people = [];

    protected function buildLeaveTree(): void
    {
        $level = static fn (int $index): int => DepartmentLevel::cases()[$index]->value;

        $gm = Department::create(['name' => 'الإدارة العامة', 'code' => 'GM', 'level' => $level(0), 'parent_id' => null]);
        $hr = Department::create(['name' => 'إدارة الموارد البشرية', 'code' => 'HR', 'level' => $level(1), 'parent_id' => $gm->id]);
        $a = Department::create(['name' => 'إدارة أ', 'code' => 'A', 'level' => $level(1), 'parent_id' => $gm->id]);
        $a1 = Department::create(['name' => 'قسم أ-1', 'code' => 'A1', 'level' => $level(2), 'parent_id' => $a->id]);
        $b = Department::create(['name' => 'إدارة ب', 'code' => 'B', 'level' => $level(1), 'parent_id' => $gm->id]);
        $this->units = compact('gm', 'hr', 'a', 'a1', 'b');

        foreach (['gm' => $gm, 'hrHead' => $hr, 'hrStaff' => $hr, 'headA' => $a, 'staff' => $a1, 'bStaff' => $b] as $key => $unit) {
            $this->people[$key] = $this->person($unit);
        }

        $gm->update(['manager_id' => $this->people['gm']->id]);
        $hr->update(['manager_id' => $this->people['hrHead']->id]);
        $a->update(['manager_id' => $this->people['headA']->id]);
    }

    protected function person(?Department $unit = null, bool $withAccount = true): Employee
    {
        $employee = Employee::factory()->create(['department_id' => ($unit ?? $this->units['a1'])->id]);

        if ($withAccount) {
            User::factory()->create(['employee_id' => $employee->id]);
        }

        return $employee;
    }

    protected function accountOf(Employee $employee): User
    {
        return User::where('employee_id', $employee->id)->sole();
    }

    protected function actingAsPerson(Employee $employee): User
    {
        $user = $this->accountOf($employee);
        Sanctum::actingAs($user);

        return $user;
    }

    protected function type(string $code): LeaveType
    {
        return LeaveType::withTrashed()->where('code', $code)->sole();
    }
}
