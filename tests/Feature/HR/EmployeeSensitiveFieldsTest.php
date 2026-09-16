<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\HR\Enums\ActivityType;
use App\Modules\HR\Enums\EngineeringRank;
use App\Modules\HR\Enums\Gender;
use App\Modules\HR\Enums\SalaryMode;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Field-level gating of the sensitive employee fields (C11).
 *
 * WHY: `salary` and `national_id` once shipped to anyone holding
 * hr.employees.view — the permission a scheduler or a receptionist would
 * reasonably be granted.
 *
 * Batch 2 WIDENS the salary gate rather than merely keeping it. `salary` is
 * gone, but `agreed_salary`, `sham_cash_account` and every classification axis
 * now sit behind hr.employees.viewSalary — because the axes are the INPUTS the
 * pay is computed from, and publishing them while hiding the result is theatre.
 * These tests are what stop that widening from silently regressing to the
 * amount alone.
 */
class EmployeeSensitiveFieldsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every key the viewSalary permission is supposed to gate.
     *
     * Listed once and asserted as a set, so a pay-determining column added to
     * the resource outside the gate fails here rather than shipping.
     *
     * @var array<int, string>
     */
    private const SALARY_GATED = [
        'activity_type', 'activity_type_label',
        'organizational_grade', 'experience_level',
        'engineering_rank', 'engineering_rank_label',
        'industrial_experience_years',
        'has_hazard_pay', 'has_scarcity_pay', 'has_dual_role_pay',
        'distinction_grade',
        'salary_mode', 'salary_mode_label',
        'agreed_salary', 'sham_cash_account',
    ];

    private function anEmployee(): Employee
    {
        return Employee::factory()->named('صاحب راتب')->onAgreedSalary(1500)->create([
            'gender'               => Gender::Male,
            'national_id'          => '1098765432',
            'sham_cash_account'    => '1234567890123456',
            'activity_type'        => ActivityType::Engineering,
            'organizational_grade' => 3,
            'engineering_rank'     => EngineeringRank::Practitioner,
        ]);
    }

    public function test_a_plain_viewer_sees_neither_the_pay_block_nor_the_national_id(): void
    {
        $employee = $this->anEmployee();
        $this->actingAsUserWithPermissions(['hr.employees.view']);

        $response = $this->getJson("/api/hr/employees/{$employee->id}")->assertOk();

        // The keys are ABSENT, not null: null would assert "this employee has
        // no national id", which is a lie about the data rather than a
        // statement about the reader's access.
        $response->assertJsonMissingPath('data.national_id');

        foreach (self::SALARY_GATED as $key) {
            $response->assertJsonMissingPath("data.{$key}");
        }

        // Non-sensitive fields are unaffected.
        $response->assertJsonPath('data.full_name', 'صاحب راتب');
    }

    /**
     * An embedded person is NOT behind either gate (C31).
     *
     * A name and a staff number are not sensitive fields — they are how a
     * client renders "who manages this person" at all. Gating them would make
     * `direct_manager_id`, which the same viewer can already read, a dead end.
     *
     * This asserts the gates do not creep outward: `direct_manager` is built
     * outside `payBlock()` and outside the identity check, and must stay there.
     */
    public function test_an_embedded_person_is_visible_to_a_plain_viewer(): void
    {
        $manager  = Employee::factory()->named('سامر خليل')->create();
        $employee = Employee::factory()->named('جين دو')->create(['direct_manager_id' => $manager->id]);

        $this->actingAsUserWithPermissions(['hr.employees.view']);

        $this->getJson("/api/hr/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.national_id')
            ->assertJsonPath('data.direct_manager.id', $manager->id)
            ->assertJsonPath('data.direct_manager.full_name', 'سامر خليل')
            ->assertJsonPath('data.direct_manager.employee_number', $manager->employee_number);
    }

    public function test_the_two_permissions_are_independent(): void
    {
        $employee = $this->anEmployee();

        // Payroll: pay and its inputs, but no identity documents.
        $this->actingAsUserWithPermissions(['hr.employees.view', 'hr.employees.viewSalary']);
        $this->getJson("/api/hr/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.agreed_salary', '1500.00')
            ->assertJsonPath('data.sham_cash_account', '1234567890123456')
            ->assertJsonPath('data.organizational_grade', 3)
            ->assertJsonPath('data.engineering_rank_label', 'ممارس')
            ->assertJsonMissingPath('data.national_id');

        // HR administration: identity documents, but no pay and no axes.
        $this->actingAsUserWithPermissions(['hr.employees.view', 'hr.employees.viewIdentity']);
        $this->getJson("/api/hr/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.national_id', '1098765432')
            ->assertJsonMissingPath('data.agreed_salary')
            ->assertJsonMissingPath('data.organizational_grade');
    }

    public function test_the_gate_applies_to_the_list_endpoint_too(): void
    {
        $this->anEmployee();
        $this->actingAsUserWithPermissions(['hr.employees.view']);

        // A field gated on show but leaking through index is the classic
        // version of this bug.
        $response = $this->getJson('/api/hr/employees')->assertOk();

        $response->assertJsonMissingPath('data.0.national_id');

        foreach (self::SALARY_GATED as $key) {
            $response->assertJsonMissingPath("data.0.{$key}");
        }
    }

    public function test_a_super_admin_sees_everything(): void
    {
        $employee = $this->anEmployee();
        $this->actingAsAdmin();

        $this->getJson("/api/hr/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.national_id', '1098765432')
            ->assertJsonPath('data.agreed_salary', '1500.00')
            ->assertJsonPath('data.activity_type_label', 'هندسي');
    }

    public function test_sensitive_values_are_redacted_in_the_audit_trail(): void
    {
        // A field gated in the Resource but written verbatim to an append-only
        // audit table is not gated (C14). audit_logs cannot be scrubbed after
        // the fact, so this has to be right on the first write.
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', [
            'first_name'        => 'موظف',
            'middle_name'       => 'جديد',
            'last_name'         => 'الشامي',
            'gender'            => Gender::Male->value,
            'national_id'       => '9988776655',
            'sham_cash_account' => '9999888877776666',
            'salary_mode'       => SalaryMode::Agreed->value,
            'agreed_salary'     => 4200,
        ])->assertCreated();

        $recorded = (string) DB::table('audit_logs')
            ->where('event', 'created')
            ->where('auditable_type', 'employee')
            ->value('new_values');

        $this->assertStringNotContainsString('9988776655', $recorded);
        $this->assertStringNotContainsString('9999888877776666', $recorded);
        $this->assertStringNotContainsString('4200', $recorded);
        $this->assertStringContainsString('REDACTED', $recorded);
    }
}
