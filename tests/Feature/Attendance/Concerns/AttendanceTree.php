<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance\Concerns;

use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Auth\Models\User;
use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Models\Employee;

/**
 * The small tree the oversight tests reach across — the same shape
 * EmployeeScopeTest uses:
 *
 *   GM  الإدارة العامة
 *   ├── A   إدارة أ          headA manages it
 *   │   └── A1  قسم أ-1
 *   └── B   إدارة ب
 *
 * One employee in each of A, A1 and B, each with an account so a refusal can
 * notify them.
 */
trait AttendanceTree
{
    /** @var array<string, Department> */
    protected array $units = [];

    /** @var array<string, Employee> */
    protected array $people = [];

    protected function buildTree(): void
    {
        $level = static fn (int $index): int => DepartmentLevel::cases()[$index]->value;

        $gm = Department::create(['name' => 'الإدارة العامة', 'code' => 'GM', 'level' => $level(0), 'parent_id' => null]);
        $a = Department::create(['name' => 'إدارة أ', 'code' => 'A', 'level' => $level(1), 'parent_id' => $gm->id]);
        $a1 = Department::create(['name' => 'قسم أ-1', 'code' => 'A1', 'level' => $level(2), 'parent_id' => $a->id]);
        $b = Department::create(['name' => 'إدارة ب', 'code' => 'B', 'level' => $level(1), 'parent_id' => $gm->id]);
        $this->units = compact('gm', 'a', 'a1', 'b');

        foreach (['a', 'a1', 'b'] as $key) {
            $this->people[$key] = Employee::factory()->create(['department_id' => $this->units[$key]->id]);
            User::factory()->create(['employee_id' => $this->people[$key]->id]);
        }

        $this->people['headA'] = Employee::factory()->create(['department_id' => $a->id]);
        $a->update(['manager_id' => $this->people['headA']->id]);
    }

    protected function checkIn(Employee $employee, string $at = '2026-06-21 08:00:00', array $attributes = []): AttendanceRecord
    {
        return AttendanceRecord::factory()->checkIn()->create(['employee_id' => $employee->id, 'recorded_at' => $at] + $attributes);
    }

    protected function checkOut(Employee $employee, string $at = '2026-06-21 16:00:00', array $attributes = []): AttendanceRecord
    {
        return AttendanceRecord::factory()->checkOut()->create(['employee_id' => $employee->id, 'recorded_at' => $at] + $attributes);
    }

    protected function accountOf(Employee $employee): User
    {
        return User::where('employee_id', $employee->id)->sole();
    }
}
