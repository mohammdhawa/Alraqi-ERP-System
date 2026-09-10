<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Field-level gating of the two sensitive employee fields (C11).
 *
 * WHY: `salary` and `national_id` shipped to anyone holding hr.employees.view —
 * the permission a scheduler or a receptionist would reasonably be granted.
 * C11 names both as sensitive and requires an EXPLICIT permission check in the
 * Resource, not omission-by-habit.
 *
 * Batch 2 extends the viewSalary gate to agreed_salary, sham_cash_account and
 * the classification axes; these tests are the shape those will follow.
 */
class EmployeeSensitiveFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function anEmployee(): Employee
    {
        return Employee::create([
            'name'        => 'صاحب راتب',
            'national_id' => '1098765432',
            'salary'      => 85000,
        ]);
    }

    public function test_a_plain_viewer_sees_neither_salary_nor_national_id(): void
    {
        $employee = $this->anEmployee();
        $this->actingAsUserWithPermissions(['hr.employees.view']);

        $response = $this->getJson("/api/hr/employees/{$employee->id}")->assertOk();

        // The keys are ABSENT, not null: null would assert that this employee
        // has no national id, which is a lie about the data rather than a
        // statement about the reader.
        $response->assertJsonMissingPath('data.salary');
        $response->assertJsonMissingPath('data.national_id');

        // The non-sensitive fields are unaffected.
        $response->assertJsonPath('data.name', 'صاحب راتب');
    }

    public function test_the_two_permissions_are_independent(): void
    {
        $employee = $this->anEmployee();

        // Payroll: pay, but no identity documents.
        $this->actingAsUserWithPermissions(['hr.employees.view', 'hr.employees.viewSalary']);
        $this->getJson("/api/hr/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.salary', '85000.00')
            ->assertJsonMissingPath('data.national_id');

        // HR administration: identity documents, but no pay.
        $this->actingAsUserWithPermissions(['hr.employees.view', 'hr.employees.viewIdentity']);
        $this->getJson("/api/hr/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.national_id', '1098765432')
            ->assertJsonMissingPath('data.salary');
    }

    public function test_the_gate_applies_to_the_list_endpoint_too(): void
    {
        $this->anEmployee();
        $this->actingAsUserWithPermissions(['hr.employees.view']);

        // A field gated on show but leaking through index is the classic version
        // of this bug.
        $this->getJson('/api/hr/employees')
            ->assertOk()
            ->assertJsonMissingPath('data.0.salary')
            ->assertJsonMissingPath('data.0.national_id');
    }

    public function test_a_super_admin_sees_both(): void
    {
        $employee = $this->anEmployee();
        $this->actingAsAdmin();

        $this->getJson("/api/hr/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.salary', '85000.00')
            ->assertJsonPath('data.national_id', '1098765432');
    }

    public function test_sensitive_values_are_redacted_in_the_audit_trail(): void
    {
        // A field gated in the Resource but written verbatim to an append-only
        // audit table is not gated (C14). audit_logs cannot be scrubbed after
        // the fact, so this has to be right on the first write.
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', [
            'name'        => 'موظف جديد',
            'national_id' => '9988776655',
            'salary'      => 42000,
        ])->assertCreated();

        $recorded = \DB::table('audit_logs')
            ->where('event', 'created')
            ->where('auditable_type', 'employee')
            ->value('new_values');

        $this->assertStringNotContainsString('9988776655', (string) $recorded);
        $this->assertStringNotContainsString('42000', (string) $recorded);
        $this->assertStringContainsString('REDACTED', (string) $recorded);
    }
}
