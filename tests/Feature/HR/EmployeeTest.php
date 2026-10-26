<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\User;
use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\Gender;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\JobTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Employee resource coverage, through the full
 * Route -> Controller -> EmployeeService -> Model -> Resource path with the
 * auth:sanctum + audit + permission middleware stack.
 *
 * Rewritten for the Batch 2 shape: `name` is three columns, `job_title` is an
 * FK, `salary` is gone, and `status` is an int-backed enum.
 */
class EmployeeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A complete, valid create payload. Tests that care about ONE field
     * override it, so a test's diff from this array is exactly what it is
     * about.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'employee_number' => $this->nextKoboNumber(),
            'first_name'  => 'جين',
            'middle_name' => 'أحمد',
            'last_name'   => 'دو',
            'gender'      => Gender::Female->value,
        ], $overrides);
    }

    public function test_index_lists_employees(): void
    {
        $this->actingAsAdmin();
        Employee::factory()->named('Jane Doe')->create();
        Employee::factory()->named('John Roe')->create();

        $this->getJson('/api/hr/employees')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/hr/employees')->assertUnauthorized();
    }

    public function test_index_forbidden_without_permission(): void
    {
        $this->actingAsRolelessUser();

        $this->getJson('/api/hr/employees')
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'ليس لديك الصلاحيات الكافية لتنفيذ هذا الإجراء.');
    }

    // ── The number is the Kobo id (KOBO_EMPLOYEE_NUMBER_DESIGN.md §3, §7) ──

    public function test_store_requires_the_kobo_id(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', $this->payload(['employee_number' => null]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.employee_number.0', 'رقم كوبو مطلوب.');
    }

    public function test_store_takes_digits_only(): void
    {
        $this->actingAsAdmin();

        // A leading zero too: 0582974643 and the import's 582974643 would be
        // two people (Task 2's ruling).
        foreach (['EMP-00042', '0582974643', str_repeat('1', 21)] as $number) {
            $this->postJson('/api/hr/employees', $this->payload(['employee_number' => $number]))
                ->assertUnprocessable()
                ->assertJsonPath('errors.employee_number.0', 'رقم كوبو يتكوّن من أرقام فقط.');
        }

        $this->postJson('/api/hr/employees', $this->payload(['employee_number' => ' 582974643 ']))
            ->assertCreated()
            ->assertJsonPath('data.employee_number', '582974643');
    }

    public function test_store_refuses_a_number_another_employee_holds(): void
    {
        $this->actingAsAdmin();
        Employee::factory()->create(['employee_number' => '582974643']);

        $this->postJson('/api/hr/employees', $this->payload(['employee_number' => '582974643']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.employee_number.0', 'رقم كوبو مستخدم لموظف آخر.');
    }

    public function test_a_wrong_number_is_fixed_by_deleting_and_adding_again(): void
    {
        // K2: the number never changes, so a mistake is deleted and re-added —
        // and the deleted record must not keep the right number from its owner.
        $this->actingAsAdmin();
        $wrong = Employee::factory()->create(['employee_number' => '582974643']);

        $this->deleteJson("/api/hr/employees/{$wrong->id}")->assertSuccessful();

        $this->postJson('/api/hr/employees', $this->payload(['employee_number' => '582974643']))
            ->assertCreated()
            ->assertJsonPath('data.employee_number', '582974643');
    }

    public function test_update_refuses_a_new_number_and_accepts_the_same(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create(['employee_number' => '582974643']);

        $this->patchJson("/api/hr/employees/{$employee->id}", ['employee_number' => '582974644'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.employee_number.0', 'لا يمكن تغيير الرقم الوظيفي بعد إنشائه.');

        $this->patchJson("/api/hr/employees/{$employee->id}", ['employee_number' => '582974643', 'phone' => '0933111222'])
            ->assertOk()
            ->assertJsonPath('data.phone', '0933111222');
        $this->assertSame('582974643', $employee->fresh()->employee_number);
    }

    public function test_store_creates_an_employee_with_a_split_name(): void
    {
        $this->actingAsAdmin();
        $department = Department::create([
            'name'  => 'الإدارة العامة',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);
        $title = JobTitle::create(['name' => 'مهندس']);

        $this->postJson('/api/hr/employees', $this->payload([
            'email'         => 'jane@example.com',
            'department_id' => $department->id,
            'job_title_id'  => $title->id,
            'hire_date'     => '2026-01-15',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.first_name', 'جين')
            ->assertJsonPath('data.last_name', 'دو')
            // The joined accessor ships alongside the parts, so a client
            // rendering a header never re-joins them itself.
            ->assertJsonPath('data.full_name', 'جين أحمد دو')
            ->assertJsonPath('data.department_id', $department->id)
            ->assertJsonPath('data.job_title_id', $title->id)
            ->assertJsonPath('data.status', EmployeeStatus::Active->value)
            ->assertJsonPath('data.status_label', 'نشط');

        $this->assertDatabaseHas('employees', [
            'first_name'    => 'جين',
            'last_name'     => 'دو',
            'department_id' => $department->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'created']);
    }

    /**
     * `name` is gone. A client still posting it must not appear to succeed —
     * the name parts are required, so the request fails on those rather than
     * silently creating a nameless employee.
     */
    public function test_store_rejects_the_old_name_field(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', ['name' => 'Jane Doe'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'middle_name', 'last_name', 'gender']);

        $this->assertDatabaseCount('employees', 0);
    }

    public function test_store_rejects_unknown_department(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', $this->payload(['department_id' => 999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('department_id');
    }

    public function test_store_rejects_a_soft_deleted_department(): void
    {
        $this->actingAsAdmin();
        $root = Department::create([
            'name'  => 'الإدارة العامة',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);
        $division = Department::create([
            'name'      => 'الإدارة الهندسية',
            'parent_id' => $root->id,
            'level'     => DepartmentLevel::Division->value,
        ]);
        $division->delete();

        $this->postJson('/api/hr/employees', $this->payload(['department_id' => $division->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('department_id');
    }

    public function test_store_validates_the_status_enum(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', $this->payload(['status' => 99]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_update_modifies_an_employee(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->named('Jane Doe')->create();

        $this->putJson("/api/hr/employees/{$employee->id}", [
            'status' => EmployeeStatus::Terminated->value,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', EmployeeStatus::Terminated->value)
            ->assertJsonPath('data.status_label', 'منتهي الخدمة');

        $this->assertDatabaseHas('employees', [
            'id'     => $employee->id,
            'status' => EmployeeStatus::Terminated->value,
        ]);
    }

    public function test_destroy_soft_deletes_an_employee(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->named('Jane Doe')->create();

        $this->deleteJson("/api/hr/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        // Employees are never hard-deleted: users, departments (manager_id) and
        // history keep pointing at them.
        $this->assertSoftDeleted('employees', ['id' => $employee->id]);
        $this->assertNull(Employee::find($employee->id));
    }

    /**
     * §10 test 4 — the one people skip, and the one that catches a write route
     * guarded by `.view`.
     */
    public function test_view_only_user_cannot_write(): void
    {
        $this->actingAsUserWithPermissions(['hr.employees.view']);
        $employee = Employee::factory()->named('Jane Doe')->create();

        $this->getJson('/api/hr/employees')->assertOk();
        $this->getJson("/api/hr/employees/{$employee->id}")->assertOk();

        $this->postJson('/api/hr/employees', $this->payload())->assertForbidden();
        $this->putJson("/api/hr/employees/{$employee->id}", ['first_name' => 'Renamed'])->assertForbidden();
        $this->deleteJson("/api/hr/employees/{$employee->id}")->assertForbidden();

        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'first_name' => 'Jane']);
        $this->assertDatabaseMissing('employees', ['first_name' => 'جين']);
    }

    // ── Filtering, search and sorting (C30) ──────────────────────────────

    public function test_index_filters_by_status(): void
    {
        $this->actingAsAdmin();
        Employee::factory()->named('Active Person')->create();
        Employee::factory()->named('Gone Person')->create(['status' => EmployeeStatus::Terminated]);

        $this->getJson('/api/hr/employees?status='.EmployeeStatus::Terminated->value)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Gone');
    }

    /**
     * Searching the PARTS is what makes a father's name findable — the middle
     * name is spoken as part of the name here, so people are looked up by it.
     */
    public function test_index_searches_every_name_part_and_the_staff_number(): void
    {
        $this->actingAsAdmin();
        Employee::factory()->named('سامر خالد الحلبي')->create(['employee_number' => '582974643']);
        Employee::factory()->named('نور علي الشامي')->create();

        $this->getJson('/api/hr/employees?search=خالد')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'سامر خالد الحلبي');

        $this->getJson('/api/hr/employees?search=582974643')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    /**
     * A wildcard in the term is a character the user typed, not an instruction.
     *
     * The OR-ed multi-column form is the one this asserts, because escaping has
     * to survive being applied four times inside a group — and because HR is
     * where the term is likeliest to be pasted from paper. The numbers below are
     * hand-supplied ones, which the `creating` hook leaves alone: continuing the
     * company's own `M024333` series by hand is ratified behaviour, so an
     * underscore in a staff number is a real value and not a contrived fixture.
     */
    public function test_search_treats_wildcards_as_literal_characters(): void
    {
        $this->actingAsAdmin();
        // Numbers are digits now (Kobo ids), so the wildcard rides in the
        // search alone: unescaped, `_` matches any one digit and this would
        // find 582974643.
        Employee::factory()->named('سامر الحلبي')->create(['employee_number' => '582974643']);
        Employee::factory()->named('نور الشامي')->create(['employee_number' => '512974643']);

        $this->getJson('/api/hr/employees?search='.urlencode('5_2974643'))
            ->assertOk()->assertJsonCount(0, 'data');

        // Unescaped, `%` matches everyone.
        $this->getJson('/api/hr/employees?search='.urlencode('%'))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    /**
     * A search must not escape an active filter and turn
     * `status=X&search=Y` into "status X OR anyone called Y".
     */
    public function test_search_is_grouped_so_it_cannot_escape_a_filter(): void
    {
        $this->actingAsAdmin();
        Employee::factory()->named('سامر الحلبي')->create(['status' => EmployeeStatus::Terminated]);
        Employee::factory()->named('سامر الشامي')->create();

        $this->getJson('/api/hr/employees?status='.EmployeeStatus::Active->value.'&search=سامر')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'سامر الشامي');
    }

    /**
     * An unknown sort is a 422, never a silent fallback: quietly ignoring it
     * returns a correct-looking page in the wrong order and the caller cannot
     * tell (C30, P3).
     */
    public function test_an_unknown_sort_is_rejected_rather_than_ignored(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/hr/employees?sort=salary')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sort');

        $this->getJson('/api/hr/employees?sort=-last_name')->assertOk();
    }

    // ── Relationships ────────────────────────────────────────────────────

    public function test_user_links_to_employee_and_back(): void
    {
        $department = Department::create([
            'name'  => 'الإدارة العامة',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);
        $employee = Employee::factory()->named('Jane Doe')->create(['department_id' => $department->id]);
        $user     = User::factory()->create(['employee_id' => $employee->id]);

        $this->assertTrue($user->employee->is($employee));
        $this->assertTrue($employee->refresh()->user->is($user));
        $this->assertTrue($employee->department->is($department));
    }

    public function test_department_manager_and_members_resolve(): void
    {
        $department = Department::create([
            'name'  => 'الإدارة العامة',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);
        $manager = Employee::factory()->named('Sara')->create(['department_id' => $department->id]);
        $member  = Employee::factory()->named('Omar')->create(['department_id' => $department->id]);
        $department->update(['manager_id' => $manager->id]);

        $department->refresh();

        $this->assertTrue($department->manager->is($manager));
        $this->assertCount(2, $department->employees);
        $this->assertTrue($member->department->manager->is($manager));
    }

    /**
     * ⚙️ FOUR TESTS STOOD HERE UNTIL 2026-09-24 and were deleted with the column
     * they covered — `direct_manager_id`, the exception to the org tree. The
     * project owner ratified that the company has no such exception, so there is
     * nothing left to write, nothing to reject as a self-reference, and no
     * embedded object that mirrors a stored id.
     *
     * The contract they protected has one surviving half: an embedded person is
     * **null, never absent**. It is asserted below for `manager`, and the
     * resolution rules themselves are covered in `EmployeeManagerTest`.
     *
     * ⚠️ NULL, NOT ABSENT. A missing key in this API means "you may not see this
     * field" (C11 gates `national_id` and the pay block exactly that way), so an
     * embed that disappeared when there is nobody to embed would be reporting a
     * permission problem that does not exist.
     */
    public function test_manager_is_null_rather_than_absent_when_there_is_none(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->named('جين دو')->create(['department_id' => null]);

        $data = $this->getJson("/api/hr/employees/{$employee->id}")->assertOk()->json('data');

        $this->assertArrayHasKey('manager', $data, 'the embed must be present even with nobody to embed');
        $this->assertNull($data['manager']);
    }

    /**
     * Moving an employee to another unit changes who manages them, because the
     * unit is now the only thing that decides it.
     *
     * This is the stale-relation risk the manager column used to absorb: the
     * response must name the manager of the department the employee has just
     * been moved TO, not the one they were in when the request arrived.
     */
    public function test_moving_an_employee_re_resolves_their_manager(): void
    {
        $this->actingAsAdmin();

        // One root with two divisions under it — two roots is what the tree
        // refuses, and this test has no business testing that.
        $root = Department::create([
            'name'  => 'الإدارة العامة',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);

        $from = Department::create([
            'name'      => 'إدارة أولى',
            'level'     => DepartmentLevel::Division->value,
            'parent_id' => $root->id,
        ]);

        $to = Department::create([
            'name'      => 'إدارة ثانية',
            'level'     => DepartmentLevel::Division->value,
            'parent_id' => $root->id,
        ]);

        $oldHead = Employee::factory()->named('الرئيس السابق')->create(['department_id' => $from->id]);
        $newHead = Employee::factory()->named('الرئيس الجديد')->create(['department_id' => $to->id]);

        $from->update(['manager_id' => $oldHead->id]);
        $to->update(['manager_id' => $newHead->id]);

        $employee = Employee::factory()->named('جين دو')->create(['department_id' => $from->id]);

        $this->putJson("/api/hr/employees/{$employee->id}", ['department_id' => $to->id])
            ->assertOk()
            ->assertJsonPath('data.manager.id', $newHead->id)
            ->assertJsonPath('data.manager.full_name', 'الرئيس الجديد');
    }

    /**
     * The list carries the resolved manager too, for a fixed cost.
     *
     * ⚙️ REWRITTEN 2026-09-23, when the index gained the RESOLVED manager and
     * the fixed cost went from three employee queries to four; the fixture was
     * rebuilt on 2026-09-24 around units rather than the dropped
     * `direct_manager_id`.
     *
     * It used to assert an exact number, which made it a test of the current
     * implementation rather than of the property that matters — and the number
     * is the part most likely to change for an honest reason. **The property is
     * that the cost does not grow with the page.** So this measures the same
     * request against three pairs and then against nine, and asserts the two
     * counts are identical: an N+1 cannot survive that, and a legitimate extra
     * query does not fail it.
     *
     * The upper bound is kept as a second, looser assertion, because "constant"
     * is satisfied by a constant that is absurdly large.
     */
    public function test_the_index_resolves_managers_without_an_n_plus_one(): void
    {
        $this->actingAsAdmin();

        $queries = [];

        \DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'from "employees"')) {
                $queries[] = $query->sql;
            }
        });

        $root = Department::create([
            'name'  => 'الإدارة العامة',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);

        // Each pair is its own unit with its own head, so every row on the page
        // resolves a DIFFERENT manager — which is what makes a per-row walk show
        // up as a growing query count instead of hiding behind a shared answer.
        $addPairs = function (int $count, int $offset) use ($root): void {
            foreach (range(1, $count) as $i) {
                $n = $offset + $i;

                $unit = Department::create([
                    'name'      => "إدارة {$n}",
                    'level'     => DepartmentLevel::Division->value,
                    'parent_id' => $root->id,
                ]);

                $manager = Employee::factory()->named("مدير {$n}")->create(['department_id' => $unit->id]);
                $unit->update(['manager_id' => $manager->id]);

                Employee::factory()->named("تابع {$n}")->create(['department_id' => $unit->id]);
            }
        };

        $addPairs(3, 0);
        $queries = [];
        $this->getJson('/api/hr/employees')->assertOk();
        $small = count($queries);

        $addPairs(6, 3);
        $queries = [];
        $this->getJson('/api/hr/employees')->assertOk();
        $large = count($queries);

        $this->assertSame(
            $small,
            $large,
            "The employees query count grew from {$small} to {$large} when the page did. "
                .'Managers are being resolved per row again.',
        );

        $this->assertLessThanOrEqual(
            5,
            $small,
            "A constant {$small} employee queries per index request is constant but not cheap.",
        );
    }

    /**
     * Sons and daughters are the stored facts; the total is derived, so it
     * cannot disagree with them.
     */
    public function test_children_count_is_derived_from_sons_and_daughters(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', $this->payload([
            'sons_count'      => 2,
            'daughters_count' => 3,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.children_count', 5);

        // Derived means derived: there is no column holding the total, so it
        // cannot go stale against the two parts (P1).
        $this->assertFalse(Schema::hasColumn('employees', 'children_count'));
    }
}
