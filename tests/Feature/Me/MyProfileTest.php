<?php

declare(strict_types=1);

namespace Tests\Feature\Me;

use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Models\User;
use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Enums\ActivityType;
use App\Modules\HR\Enums\EngineeringRank;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\JobTitle;
use App\Modules\HR\Resources\EmployeeResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/me/profile — the caller's own employee record (work order §4.8).
 *
 * THE GATE CONFLICT (requirements §6.1) is what these tests are for. The owner
 * sees their own pay block and national id (decision 17; D10 = B and the
 * national id, both answered 2026-10-19) WITHOUT holding
 * hr.employees.viewSalary or viewIdentity — so the self rule must reach the
 * caller's own row through /api/me and nothing else: not a colleague's row,
 * and not the HR path, which stays decided by the permission alone.
 */
class MyProfileTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every key behind viewSalary — the same set EmployeeSensitiveFieldsTest
     * guards on the HR path.
     *
     * @var array<int, string>
     */
    private const PAY_BLOCK = [
        'activity_type', 'activity_type_label',
        'organizational_grade', 'experience_level',
        'engineering_rank', 'engineering_rank_label',
        'industrial_experience_years',
        'has_hazard_pay', 'has_scarcity_pay', 'has_dual_role_pay',
        'distinction_grade',
        'salary_mode', 'salary_mode_label',
        'agreed_salary', 'sham_cash_account', 'payroll_location',
    ];

    private int $made = 0;

    /**
     * The first one made carries the identifiers the assertions name; later
     * ones differ, as both columns are unique.
     */
    private function aPaidEmployee(string $name, ?int $departmentId = null): Employee
    {
        $n = $this->made++;

        return Employee::factory()->named($name)->onAgreedSalary(1500)->create([
            'department_id'        => $departmentId,
            'national_id'          => (string) (10987654321 + $n),
            'sham_cash_account'    => (string) (1234567890123456 + $n),
            'payroll_location'     => 'دمشق',
            'activity_type'        => ActivityType::Engineering,
            'organizational_grade' => 3,
            'engineering_rank'     => EngineeringRank::Practitioner,
        ]);
    }

    private function actAs(Employee $employee): User
    {
        $user = User::factory()->create(['employee_id' => $employee->id, 'must_change_password' => false]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_the_owner_sees_their_own_pay_block_and_national_id_without_either_permission(): void
    {
        $me = $this->aPaidEmployee('سامي يوسف');
        $this->actAs($me);

        $response = $this->getJson('/api/me/profile')->assertOk()
            ->assertJsonPath('data.id', $me->id)
            ->assertJsonPath('data.full_name', 'سامي يوسف')
            ->assertJsonPath('data.national_id', '10987654321')
            ->assertJsonPath('data.agreed_salary', '1500.00')
            ->assertJsonPath('data.sham_cash_account', '1234567890123456')
            ->assertJsonPath('data.payroll_location', 'دمشق')
            ->assertJsonPath('data.organizational_grade', 3)
            ->assertJsonPath('data.engineering_rank_label', 'ممارس');

        foreach (self::PAY_BLOCK as $key) {
            $this->assertArrayHasKey($key, $response->json('data'), "{$key} is the owner's to read");
        }
    }

    public function test_a_self_viewer_still_cannot_read_a_colleagues_pay_or_national_id(): void
    {
        $me = $this->aPaidEmployee('سامي يوسف');
        $colleague = $this->aPaidEmployee('رامي حداد');

        // Allowed to read colleagues at all, but holds neither field permission.
        $this->actingAsScopedUser(['hr.employees.view'], ScopeType::Company, employeeId: $me->id);

        $response = $this->getJson("/api/hr/employees/{$colleague->id}")->assertOk();

        $response->assertJsonMissingPath('data.national_id');
        foreach (self::PAY_BLOCK as $key) {
            $response->assertJsonMissingPath("data.{$key}");
        }
    }

    public function test_the_hr_path_stays_decided_by_the_permission_even_for_the_callers_own_row(): void
    {
        $me = $this->aPaidEmployee('سامي يوسف');
        $this->actingAsScopedUser(['hr.employees.view'], ScopeType::Company, employeeId: $me->id);

        $response = $this->getJson("/api/hr/employees/{$me->id}")->assertOk();

        $response->assertJsonMissingPath('data.national_id');
        foreach (self::PAY_BLOCK as $key) {
            $response->assertJsonMissingPath("data.{$key}");
        }
    }

    public function test_the_self_rendering_of_someone_elses_row_shows_neither_block(): void
    {
        $me = $this->aPaidEmployee('سامي يوسف');
        $colleague = $this->aPaidEmployee('رامي حداد');
        $user = User::factory()->create(['employee_id' => $me->id]);

        $request = Request::create('/api/me/profile');
        $request->setUserResolver(static fn (): User => $user);

        $data = EmployeeResource::forSelf($colleague)->resolve($request);

        $this->assertArrayNotHasKey('national_id', $data);
        foreach (self::PAY_BLOCK as $key) {
            $this->assertArrayNotHasKey($key, $data, "{$key} is not the caller's");
        }
    }

    public function test_the_profile_carries_the_unit_the_title_and_the_resolved_manager(): void
    {
        $unit = Department::create(['name' => 'الإدارة العامة', 'level' => DepartmentLevel::GeneralAdministration->value]);
        $boss = Employee::factory()->named('مدير عام')->create(['department_id' => $unit->id]);
        $unit->update(['manager_id' => $boss->id]);
        $title = JobTitle::create(['name' => 'محاسب']);

        $me = $this->aPaidEmployee('سامي يوسف', $unit->id);
        $me->update(['job_title_id' => $title->id]);
        $this->actAs($me);

        $this->getJson('/api/me/profile')->assertOk()
            ->assertJsonPath('data.department.id', $unit->id)
            ->assertJsonPath('data.department.name', 'الإدارة العامة')
            ->assertJsonPath('data.job_title.name', 'محاسب')
            ->assertJsonPath('data.manager.id', $boss->id)
            ->assertJsonPath('data.photo', null);
    }

    public function test_it_is_always_the_callers_own_record(): void
    {
        $me = $this->aPaidEmployee('سامي يوسف');
        $colleague = $this->aPaidEmployee('رامي حداد');
        $this->actAs($me);

        // No id is taken from the request, so none can be smuggled in.
        $this->getJson("/api/me/profile?employee_id={$colleague->id}")->assertOk()
            ->assertJsonPath('data.id', $me->id);
    }

    public function test_an_account_with_no_employee_is_refused(): void
    {
        $this->actingAsRolelessUser();

        $this->getJson('/api/me/profile')->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/me/profile')->assertUnauthorized();
    }
}
