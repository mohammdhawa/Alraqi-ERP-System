<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Enums\AssignmentReason;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A correction is not a move (work order §4.4).
 *
 * When HR corrects a person's unit in the source and the importer runs again,
 * the person did not transfer today — the record was wrong. An ordinary update
 * of the unit closes the open posting and opens a نقل داخلي dated today, which
 * is right for a real move and wrong here. Employee::correct() amends the open
 * posting in place instead, through the model, so the guards and the audit
 * trail still see it.
 */
class EmployeeCorrectionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{finance: Department, engineering: Department} */
    private function tree(): array
    {
        $root = Department::create(['name' => 'الإدارة العامة', 'level' => DepartmentLevel::GeneralAdministration->value]);

        return [
            'finance'     => Department::create(['name' => 'الإدارة المالية', 'parent_id' => $root->id, 'level' => DepartmentLevel::Division->value]),
            'engineering' => Department::create(['name' => 'الإدارة الهندسية', 'parent_id' => $root->id, 'level' => DepartmentLevel::Division->value]),
        ];
    }

    public function test_a_corrected_unit_amends_the_open_posting_instead_of_recording_a_transfer(): void
    {
        $tree = $this->tree();
        $employee = Employee::factory()->named('نور الشامي')->create([
            'department_id' => $tree['finance']->id,
            'hire_date'     => '2022-01-01',
        ]);

        $employee->correct(['department_id' => $tree['engineering']->id]);

        $history = $employee->refresh()->assignments()->get();
        $this->assertCount(1, $history, 'no transfer posting');
        $this->assertSame($tree['engineering']->id, $history[0]->department_id);
        $this->assertSame(AssignmentReason::Hire, $history[0]->reason);
        $this->assertSame('2022-01-01', $history[0]->from_date->toDateString(), 'still from the hire');
        $this->assertSame($tree['engineering']->id, $employee->department_id);
    }

    public function test_after_a_correction_an_ordinary_move_is_a_transfer_again(): void
    {
        $tree = $this->tree();
        $employee = Employee::factory()->named('نور الشامي')->create([
            'department_id' => $tree['finance']->id,
            'hire_date'     => '2022-01-01',
        ]);
        $employee->correct(['organizational_grade' => 3]);

        $employee->update(['department_id' => $tree['engineering']->id]);

        $this->assertCount(2, $employee->refresh()->assignments()->get());
    }

    public function test_a_correction_is_audited_like_any_other_change(): void
    {
        $tree = $this->tree();
        $employee = Employee::factory()->named('نور الشامي')->create(['department_id' => $tree['finance']->id]);

        $employee->correct(['department_id' => $tree['engineering']->id]);

        $this->assertDatabaseHas('audit_logs', [
            'event'          => 'updated',
            'auditable_type' => $employee->getMorphClass(),
            'auditable_id'   => $employee->id,
        ]);
    }

    public function test_a_correction_of_other_fields_is_an_ordinary_save(): void
    {
        $employee = Employee::factory()->named('نور الشامي')->create();

        $employee->correct(['phone' => '0999000111']);

        $this->assertSame('0999000111', $employee->refresh()->phone);
    }
}
