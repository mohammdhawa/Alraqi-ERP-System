<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\HR\Enums\ActivityType;
use App\Modules\HR\Enums\EngineeringRank;
use App\Modules\HR\Enums\Gender;
use App\Modules\HR\Enums\MaritalStatus;
use App\Modules\HR\Enums\SalaryMode;
use App\Modules\HR\Exceptions\EmployeeClassificationException;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EmployeeClassificationGuard, asserted at BOTH layers it is enforced in.
 *
 * ⚠️ THE MODEL-LEVEL HALF IS THE POINT OF THIS FILE. A rule that is only
 * proven through HTTP is only proven for HTTP, and the writers that matter most
 * here — the Batch 4 importer bringing in 465 real rows, plus seeders and
 * tinker — never cross a FormRequest. This project has already paid for that
 * lesson once: hierarchy rules that lived only in DepartmentRequest let the
 * seeder create four roots.
 *
 * So every invariant is asserted twice: once as a 422 with a field error (the
 * friendly front door) and once as an exception from `Employee::create()` (the
 * backstop). Deleting the model hook must break this file.
 */
class EmployeeClassificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'employee_number' => $this->nextKoboNumber(),
            'first_name'  => 'سامر',
            'middle_name' => 'خالد',
            'last_name'   => 'الحلبي',
            'gender'      => Gender::Male->value,
        ], $overrides);
    }

    // ── Axis exclusivity ─────────────────────────────────────────────────

    public function test_an_engineering_rank_is_refused_on_a_non_engineering_post(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', $this->payload([
            'activity_type'    => ActivityType::Administrative->value,
            'engineering_rank' => EngineeringRank::Consultant->value,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('engineering_rank');
    }

    public function test_the_engineering_rank_rule_binds_non_http_writers_too(): void
    {
        $this->expectException(EmployeeClassificationException::class);

        Employee::factory()->named('سامر الحلبي')->create([
            'activity_type'    => ActivityType::Administrative,
            'engineering_rank' => EngineeringRank::Consultant,
        ]);
    }

    public function test_an_engineering_rank_is_accepted_on_an_engineering_post(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', $this->payload([
            'activity_type'    => ActivityType::Engineering->value,
            'engineering_rank' => EngineeringRank::Consultant->value,
        ]))->assertCreated();
    }

    public function test_industrial_experience_is_refused_outside_industrial_work(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', $this->payload([
            'activity_type'               => ActivityType::Engineering->value,
            'industrial_experience_years' => 7,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('industrial_experience_years');
    }

    /**
     * ⚠️ The guard deliberately does NOT make `experience_level` exclusive to
     * the administrative group, despite one reading of the Phase 1.5 spec. The
     * company's own pay matrix carries two experience rate tables — one keyed to
     * إداري/مالي/أمني and one to هندسي/صناعي — so an engineering employee
     * legitimately holds an experience level AND a rank. Enforcing the stricter
     * reading would reject real rows at import time.
     *
     * This test pins that decision down so it cannot be "tidied up" later
     * without someone reading the reasoning in the ActivityType docblock first.
     */
    public function test_an_engineer_may_hold_both_an_experience_level_and_a_rank(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', $this->payload([
            'activity_type'    => ActivityType::Engineering->value,
            'experience_level' => 3,
            'engineering_rank' => EngineeringRank::Practitioner->value,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.experience_level', 3)
            ->assertJsonPath('data.engineering_rank', EngineeringRank::Practitioner->value);
    }

    // ── The pay-track pairing ────────────────────────────────────────────

    public function test_agreed_track_requires_an_amount(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', $this->payload([
            'salary_mode' => SalaryMode::Agreed->value,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('agreed_salary');
    }

    /**
     * The forbidding half, which is the one that looks pedantic and is not: a
     * نظام رواتب employee carrying a stored amount is a cached derived value
     * with no invalidation strategy — the exact thing `employees.salary` was
     * dropped for — and the first calculator written as
     * `agreed_salary ?? calculate()` would pay it.
     */
    public function test_scale_track_forbids_an_amount(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', $this->payload([
            'salary_mode'   => SalaryMode::Scale->value,
            'agreed_salary' => 1500,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('agreed_salary');
    }

    /**
     * The default is نظام رواتب, and it is applied by the MODEL rather than
     * only by the column default — otherwise the guard would see no salary_mode
     * at all on a create and the pairing rule would never run.
     */
    public function test_an_amount_without_a_mode_is_still_refused(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', $this->payload([
            'agreed_salary' => 1500,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('agreed_salary');
    }

    public function test_the_pay_track_rule_binds_non_http_writers_too(): void
    {
        $this->expectException(EmployeeClassificationException::class);

        Employee::factory()->named('سامر الحلبي')->create(['agreed_salary' => 1500]);
    }

    // ── Grade ranges, account shape, family coherence, date order ────────

    public function test_the_organizational_grade_scale_is_bounded(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', $this->payload(['organizational_grade' => 9]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('organizational_grade');
    }

    public function test_a_sham_cash_account_must_be_sixteen_digits(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', $this->payload(['sham_cash_account' => '12345']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sham_cash_account');

        $this->postJson('/api/hr/employees', $this->payload(['sham_cash_account' => '1234567890123456']))
            ->assertCreated();
    }

    /**
     * A stale wives_count left behind by a change from متزوج to مطلق is money,
     * because the count feeds family allowances.
     */
    public function test_a_wives_count_requires_a_married_employee(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', $this->payload([
            'marital_status' => MaritalStatus::Single->value,
            'wives_count'    => 1,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('wives_count');

        $this->postJson('/api/hr/employees', $this->payload([
            'marital_status' => MaritalStatus::Married->value,
            'wives_count'    => 1,
        ]))->assertCreated();
    }

    public function test_a_person_cannot_leave_before_they_arrived(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', $this->payload([
            'hire_date'        => '2026-05-01',
            'termination_date' => '2026-04-01',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('termination_date');
    }

    /**
     * The merge in effectiveAttributes() is what makes a PATCH correct.
     * Submitting only `activity_type` has to be judged against the rank the row
     * ALREADY holds — otherwise this passes validation, having seen no rank at
     * all, and is then refused by the model hook as a 422 with no field
     * attached to it.
     */
    public function test_a_partial_update_is_judged_against_the_stored_row(): void
    {
        $this->actingAsAdmin();

        $engineer = Employee::factory()->named('سامر الحلبي')->create([
            'activity_type'    => ActivityType::Engineering,
            'engineering_rank' => EngineeringRank::Consultant,
        ]);

        $this->putJson("/api/hr/employees/{$engineer->id}", [
            'activity_type' => ActivityType::Administrative->value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('engineering_rank');

        // Clearing the rank in the same request is the legitimate way to do it.
        $this->putJson("/api/hr/employees/{$engineer->id}", [
            'activity_type'    => ActivityType::Administrative->value,
            'engineering_rank' => null,
        ])->assertOk();
    }
}
