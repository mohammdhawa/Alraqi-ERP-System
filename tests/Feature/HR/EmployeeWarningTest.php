<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الإنذارات — the disciplinary record.
 *
 * The workbook held نوع الإنذار، تاريخ الإنذار، رقم الإنذار as three columns on
 * the employee row, so an employee could hold exactly one warning forever and
 * the second erased the first. These are rows, and the tests below are mostly
 * about the consequence of that: `sequence` is DERIVED, so it stays right when
 * a warning is withdrawn or a back-dated one is filed.
 */
class EmployeeWarningTest extends TestCase
{
    use RefreshDatabase;

    private function anEmployee(): Employee
    {
        return Employee::factory()->named('نور الشامي')->create();
    }

    public function test_store_issues_a_warning(): void
    {
        $this->actingAsAdmin();
        $employee = $this->anEmployee();
        $manager  = Employee::factory()->named('سامر الحلبي')->create();

        $this->postJson("/api/hr/employees/{$employee->id}/warnings", [
            'type'      => 'تأخر متكرر',
            'number'    => 'W-2026-14',
            'issued_on' => '2026-04-01',
            'issued_by' => $manager->id,
            'notes'     => 'إنذار أول.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'تأخر متكرر')
            ->assertJsonPath('data.sequence', 1);

        $this->assertDatabaseHas('employee_warnings', [
            'employee_id' => $employee->id,
            'number'      => 'W-2026-14',
        ]);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => 'employee_warning', 'event' => 'created']);
    }

    /**
     * The whole reason these are rows: an employee can hold more than one, and
     * the second does not erase the first.
     */
    public function test_an_employee_can_hold_several_warnings(): void
    {
        $this->actingAsAdmin();
        $employee = $this->anEmployee();

        foreach (['2026-01-10', '2026-03-05', '2026-06-20'] as $date) {
            $this->postJson("/api/hr/employees/{$employee->id}/warnings", [
                'type'      => 'مخالفة',
                'issued_on' => $date,
            ])->assertCreated();
        }

        $this->getJson("/api/hr/employees/{$employee->id}/warnings")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            // Newest first, and the sequence counts from the earliest.
            ->assertJsonPath('data.0.issued_on', '2026-06-20')
            ->assertJsonPath('data.0.sequence', 3)
            ->assertJsonPath('data.2.issued_on', '2026-01-10')
            ->assertJsonPath('data.2.sequence', 1);
    }

    /**
     * `sequence` is counted from the live rows, so withdrawing #2 of 3
     * renumbers the third to #2. A stored counter would be wrong from the
     * moment of the deletion and would stay wrong.
     */
    public function test_withdrawing_a_warning_renumbers_the_rest(): void
    {
        $this->actingAsAdmin();
        $employee = $this->anEmployee();

        foreach (['2026-01-10', '2026-03-05', '2026-06-20'] as $date) {
            $this->postJson("/api/hr/employees/{$employee->id}/warnings", [
                'type' => 'مخالفة', 'issued_on' => $date,
            ])->assertCreated();
        }

        // whereDate, for the storage-format reason spelled out on
        // EmployeeWarning::sequence().
        $middle = $employee->warnings()->whereDate('issued_on', '2026-03-05')->first();

        $this->deleteJson("/api/hr/warnings/{$middle->id}")->assertOk();

        // Soft-deleted: "withdrawn" and "never happened" are different claims,
        // and an audit is entitled to tell them apart.
        $this->assertSoftDeleted('employee_warnings', ['id' => $middle->id]);

        $this->getJson("/api/hr/employees/{$employee->id}/warnings")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.issued_on', '2026-06-20')
            ->assertJsonPath('data.0.sequence', 2);
    }

    /**
     * A back-dated warning takes its rightful place in the sequence rather than
     * being appended to the end.
     */
    public function test_a_back_dated_warning_takes_its_place_in_the_sequence(): void
    {
        $this->actingAsAdmin();
        $employee = $this->anEmployee();

        $this->postJson("/api/hr/employees/{$employee->id}/warnings", [
            'type' => 'مخالفة', 'issued_on' => '2026-06-20',
        ])->assertCreated();

        $this->postJson("/api/hr/employees/{$employee->id}/warnings", [
            'type' => 'مخالفة', 'issued_on' => '2026-01-10',
        ])
            ->assertCreated()
            ->assertJsonPath('data.sequence', 1);

        $this->getJson("/api/hr/employees/{$employee->id}/warnings")
            ->assertOk()
            ->assertJsonPath('data.0.issued_on', '2026-06-20')
            ->assertJsonPath('data.0.sequence', 2);
    }

    public function test_a_future_dated_warning_is_rejected(): void
    {
        $this->actingAsAdmin();
        $employee = $this->anEmployee();

        $this->postJson("/api/hr/employees/{$employee->id}/warnings", [
            'type'      => 'مخالفة',
            'issued_on' => now()->addWeek()->toDateString(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('issued_on');
    }

    public function test_a_type_and_date_are_required(): void
    {
        $this->actingAsAdmin();
        $employee = $this->anEmployee();

        $this->postJson("/api/hr/employees/{$employee->id}/warnings", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'issued_on']);
    }

    public function test_update_amends_a_warning(): void
    {
        $this->actingAsAdmin();
        $employee = $this->anEmployee();
        $warning  = $employee->warnings()->create(['type' => 'مخالفة', 'issued_on' => '2026-04-01']);

        $this->putJson("/api/hr/warnings/{$warning->id}", ['type' => 'غياب دون إذن'])
            ->assertOk()
            ->assertJsonPath('data.type', 'غياب دون إذن');
    }

    public function test_index_requires_authentication(): void
    {
        $employee = $this->anEmployee();

        $this->getJson("/api/hr/employees/{$employee->id}/warnings")->assertUnauthorized();
    }

    public function test_index_forbidden_without_permission(): void
    {
        $this->actingAsRolelessUser();
        $employee = Employee::factory()->named('نور الشامي')->create();

        $this->getJson("/api/hr/employees/{$employee->id}/warnings")->assertForbidden();
    }

    /**
     * §10 test 4.
     */
    public function test_view_only_user_cannot_write(): void
    {
        $employee = Employee::factory()->named('نور الشامي')->create();
        $warning  = $employee->warnings()->create(['type' => 'مخالفة', 'issued_on' => '2026-04-01']);

        $this->actingAsUserWithPermissions(['hr.warnings.view']);

        $this->getJson("/api/hr/employees/{$employee->id}/warnings")->assertOk();

        $this->postJson("/api/hr/employees/{$employee->id}/warnings", [
            'type' => 'أخرى', 'issued_on' => '2026-05-01',
        ])->assertForbidden();
        $this->putJson("/api/hr/warnings/{$warning->id}", ['type' => 'أخرى'])->assertForbidden();
        $this->deleteJson("/api/hr/warnings/{$warning->id}")->assertForbidden();
    }
}
