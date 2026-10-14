<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * اللقب and مكان الراتب — needed in v1 (project owner, 2026-10-14; work order §8).
 *
 * The workbook carries both and the ERP had a column for neither. The kunya is
 * how much of the site staff is addressed, so it is identity, visible like the
 * name. The pay location is the payroll cost centre the salary is drawn from,
 * so it sits in the pay block behind hr.employees.viewSalary — read AND write,
 * which EmployeeWriteGateTest's parity check holds in step.
 */
class EmployeeNicknameAndPayrollLocationTest extends TestCase
{
    use RefreshDatabase;

    private const EDITOR = ['hr.employees.view', 'hr.employees.create', 'hr.employees.update'];

    public function test_an_editor_sets_and_reads_the_nickname(): void
    {
        $this->actingAsUserWithPermissions(self::EDITOR);
        $employee = Employee::factory()->create();

        $this->patchJson("/api/hr/employees/{$employee->id}", ['nickname' => 'أبو عبد الرحمن'])
            ->assertOk()
            ->assertJsonPath('data.nickname', 'أبو عبد الرحمن');

        $this->assertSame('أبو عبد الرحمن', $employee->fresh()->nickname);
    }

    public function test_the_nickname_is_shown_to_a_reader_without_pay_access(): void
    {
        $this->actingAsUserWithPermissions(['hr.employees.view']);
        $employee = Employee::factory()->create(['nickname' => 'أبو صهيب']);

        $this->getJson("/api/hr/employees/{$employee->id}")->assertJsonPath('data.nickname', 'أبو صهيب');
    }

    public function test_a_pay_reader_sets_and_reads_the_payroll_location(): void
    {
        $this->actingAsUserWithPermissions([...self::EDITOR, 'hr.employees.viewSalary']);
        $employee = Employee::factory()->create();

        $this->patchJson("/api/hr/employees/{$employee->id}", ['payroll_location' => 'مكتب رئيسي - هندسي'])
            ->assertOk()
            ->assertJsonPath('data.payroll_location', 'مكتب رئيسي - هندسي');
    }

    public function test_the_payroll_location_is_hidden_without_pay_access(): void
    {
        $this->actingAsUserWithPermissions(['hr.employees.view']);
        $employee = Employee::factory()->create(['payroll_location' => 'الآليات الثقيلة']);

        $this->getJson("/api/hr/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.payroll_location');
    }

    public function test_an_editor_without_pay_access_cannot_set_the_payroll_location(): void
    {
        $this->actingAsUserWithPermissions(self::EDITOR);
        $employee = Employee::factory()->create();

        $this->patchJson("/api/hr/employees/{$employee->id}", ['payroll_location' => 'الآليات الثقيلة'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payroll_location');

        $this->assertNull($employee->fresh()->payroll_location);
    }

    public function test_both_are_length_limited(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create();

        $this->patchJson("/api/hr/employees/{$employee->id}", [
            'nickname'         => str_repeat('أ', 101),
            'payroll_location' => str_repeat('م', 151),
        ])->assertUnprocessable()->assertJsonValidationErrors(['nickname', 'payroll_location']);
    }
}
