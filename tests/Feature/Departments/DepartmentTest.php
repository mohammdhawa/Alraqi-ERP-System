<?php

declare(strict_types=1);

namespace Tests\Feature\Departments;

use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Department resource coverage.
 *
 * Exercises the full Route -> Controller -> DepartmentService -> Model ->
 * Resource path for the /api/departments endpoints through real HTTP
 * requests, including the auth:sanctum + audit + permission middleware stack.
 *
 * Authentication uses an admin user (actingAsAdmin) because the resource is
 * guarded by permission:departments.view, which is now enforced by RBAC
 * (Package D). A separate test covers the 403 for a user without permission.
 */
class DepartmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_departments(): void
    {
        $this->actingAsAdmin();
        $root = Department::create([
            'name'  => 'الإدارة العامة',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);
        Department::create([
            'name'      => 'الإدارة الهندسية',
            'parent_id' => $root->id,
            'level'     => DepartmentLevel::Division->value,
        ]);

        $this->getJson('/api/departments')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/departments')->assertUnauthorized();
    }

    public function test_index_forbidden_without_permission(): void
    {
        // Authenticated, but no roles/permissions -> CheckPermission returns 403.
        $this->actingAsRolelessUser();

        $this->getJson('/api/departments')
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'ليس لديك الصلاحيات الكافية لتنفيذ هذا الإجراء.');
    }

    public function test_store_creates_a_department(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/departments', [
            'name'  => 'الإدارة العامة',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'الإدارة العامة')
            ->assertJsonPath('data.level', 1)
            ->assertJsonPath('data.level_label', 'الإدارة العامة');

        $this->assertDatabaseHas('departments', ['name' => 'الإدارة العامة', 'level' => 1]);
        // HasAuditLog records the creation.
        $this->assertDatabaseHas('audit_logs', ['event' => 'created']);
    }

    public function test_store_validates_name_is_required(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/departments', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_show_returns_a_department(): void
    {
        $this->actingAsAdmin();
        $department = Department::create([
            'name'  => 'Engineering',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);

        $this->getJson("/api/departments/{$department->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $department->id)
            ->assertJsonPath('data.name', 'Engineering');
    }

    /**
     * show() must not leave the ancestor walk to the Resource layer.
     *
     * DepartmentResource exposes a RESOLVED `sector`, which the model finds by
     * walking up to the first non-null ancestor — one query per hop when
     * `parent` is not loaded. The list endpoint was covered by the service's
     * withAncestors(); show() used bare route-model binding, so rendering one
     * level-4 office cost 3 queries against `departments`, two of them issued
     * from inside the Resource — the one layer whose contract forbids queries.
     *
     * ⚠️ NOTE THE COUNT GOES UP, NOT DOWN: 3 before, 4 after. Preloading a
     * nested chain costs one query PER LEVEL, while the lazy walk stopped as
     * soon as it found a stored sector. So this is not a performance fix and
     * must not be described as one — it is a LAYER fix. What it buys is that
     * the cost is now paid in the controller, is bounded by the tier count, and
     * does not vary with what a Resource happens to dereference. The place this
     * genuinely saves queries is the list endpoint, where the same walk ran once
     * per row; that was already handled by the service's withAncestors().
     *
     * Asserting the exact count still discriminates: the lazy version stops at
     * the division that stores the sector (3), the eager version walks to the
     * root (4).
     */
    public function test_show_loads_the_ancestor_chain_rather_than_walking_it_lazily(): void
    {
        $this->actingAsAdmin();

        $root     = Department::create(['name' => 'الإدارة العامة', 'level' => 1]);
        $division = Department::create(['name' => 'إدارة', 'level' => 2, 'parent_id' => $root->id, 'sector' => 1]);
        $section  = Department::create(['name' => 'قسم', 'level' => 3, 'parent_id' => $division->id]);
        $office   = Department::create(['name' => 'مكتب', 'level' => 4, 'parent_id' => $section->id]);

        $queries = [];
        \DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'from "departments"')) {
                $queries[] = $query->sql;
            }
        });

        $this->getJson("/api/departments/{$office->id}")
            ->assertOk()
            // The sector still resolves — it is preloaded now, not walked.
            ->assertJsonPath('data.sector', 1);

        // 1 binding + one per ancestor level, all issued by the controller
        // before the Resource ever runs.
        $this->assertCount(
            4,
            $queries,
            'expected the binding plus a preloaded ancestor chain; got '.count($queries)
                .' — the Resource is probably walking ancestors lazily again',
        );
    }

    /**
     * `manager_id` stays exactly as it is — it is the write contract — and
     * `manager` is the same person as an object beside it (C31), so a client
     * rendering "who runs this unit" no longer walks the Employees endpoint to
     * find out.
     */
    public function test_show_embeds_the_manager(): void
    {
        $this->actingAsAdmin();
        $manager    = Employee::factory()->named('سامر خليل')->create();
        $department = Department::create([
            'name'       => 'الإدارة العامة',
            'level'      => DepartmentLevel::GeneralAdministration->value,
            'manager_id' => $manager->id,
        ]);

        $this->getJson("/api/departments/{$department->id}")
            ->assertOk()
            ->assertJsonPath('data.manager_id', $manager->id)
            ->assertJsonPath('data.manager.id', $manager->id)
            ->assertJsonPath('data.manager.full_name', 'سامر خليل')
            // The staff number is what tells two managers with the same
            // الاسم الثلاثي apart.
            ->assertJsonPath('data.manager.employee_number', $manager->employee_number);
    }

    /**
     * ⚠️ NULL, NOT ABSENT, for a unit with no manager. An omitted key in this
     * API means "you may not see this field" (C11), so an embed that vanished
     * when there is nobody to embed would report a permission problem that does
     * not exist.
     */
    public function test_manager_is_null_rather_than_absent_when_the_unit_has_none(): void
    {
        $this->actingAsAdmin();
        $department = Department::create([
            'name'  => 'الإدارة العامة',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);

        $data = $this->getJson("/api/departments/{$department->id}")->assertOk()->json('data');

        $this->assertNull($data['manager_id']);
        $this->assertArrayHasKey('manager', $data, 'the embed must be present even with nobody to embed');
        $this->assertNull($data['manager']);
    }

    /**
     * Reassigning the manager reassigns the embedded object with it — the
     * controller reloads the relation after the write instead of echoing the
     * employee the bound model was already holding.
     */
    public function test_reassigning_the_manager_updates_the_embedded_object(): void
    {
        $this->actingAsAdmin();
        $first      = Employee::factory()->named('المدير الأول')->create();
        $second     = Employee::factory()->named('المدير الثاني')->create();
        $department = Department::create([
            'name'       => 'الإدارة العامة',
            'level'      => DepartmentLevel::GeneralAdministration->value,
            'manager_id' => $first->id,
        ]);

        $this->putJson("/api/departments/{$department->id}", ['manager_id' => $second->id])
            ->assertOk()
            ->assertJsonPath('data.manager_id', $second->id)
            ->assertJsonPath('data.manager.id', $second->id)
            ->assertJsonPath('data.manager.full_name', 'المدير الثاني');
    }

    /**
     * The list resolves every manager in ONE query, not one per unit.
     *
     * This matters more here than almost anywhere else: the seeded tree is 119
     * units and most of them have a manager, so a lazy load is ~15 extra
     * queries on every page of the departments list.
     */
    public function test_the_index_embeds_managers_without_an_n_plus_one(): void
    {
        $this->actingAsAdmin();
        $root = Department::create([
            'name'  => 'الإدارة العامة',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);

        foreach (range(1, 3) as $i) {
            Department::create([
                'name'       => "إدارة {$i}",
                'level'      => DepartmentLevel::Division->value,
                'parent_id'  => $root->id,
                'manager_id' => Employee::factory()->named("مدير {$i}")->create()->id,
            ]);
        }

        $queries = [];
        \DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'from "employees"')) {
                $queries[] = $query->sql;
            }
        });

        $this->getJson('/api/departments')->assertOk();

        $this->assertCount(
            1,
            $queries,
            'expected ONE employees query for the whole page; got '.count($queries)
                .' — managers are probably being resolved per department again',
        );
    }

    public function test_update_modifies_a_department(): void
    {
        $this->actingAsAdmin();
        $department = Department::create([
            'name'  => 'Engineering',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);

        $this->putJson("/api/departments/{$department->id}", ['name' => 'R&D'])
            ->assertOk()
            ->assertJsonPath('data.name', 'R&D');

        $this->assertDatabaseHas('departments', ['id' => $department->id, 'name' => 'R&D']);
    }

    /**
     * Departments soft-delete: the row survives with deleted_at set so employees
     * and audit entries pointing at it stay resolvable. Asserting
     * assertDatabaseMissing here would be asserting data loss.
     *
     * A non-root, childless unit (a division under the root) is targeted — the
     * root itself is undeletable, and a unit with live children is blocked.
     */
    public function test_destroy_soft_deletes_a_department(): void
    {
        $this->actingAsAdmin();
        $root = Department::create([
            'name'  => 'الإدارة العامة',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);
        $department = Department::create([
            'name'      => 'الإدارة الهندسية',
            'parent_id' => $root->id,
            'level'     => DepartmentLevel::Division->value,
        ]);

        $this->deleteJson("/api/departments/{$department->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('departments', ['id' => $department->id]);
        $this->assertNull(Department::find($department->id));
    }

    /**
     * A read-only role (only departments.view) may list/show but must NOT be
     * able to create, update, or delete. Guards against the per-action
     * permissions regressing back to a blanket `view` on every route.
     */
    public function test_view_only_user_cannot_write(): void
    {
        $this->actingAsUserWithPermissions(['departments.view']);
        $department = Department::create([
            'name'  => 'Engineering',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);

        // Reads are allowed.
        $this->getJson('/api/departments')->assertOk();
        $this->getJson("/api/departments/{$department->id}")->assertOk();

        // Writes are forbidden without the matching permission.
        $this->postJson('/api/departments', ['name' => 'New'])->assertForbidden();
        $this->putJson("/api/departments/{$department->id}", ['name' => 'R&D'])->assertForbidden();
        $this->deleteJson("/api/departments/{$department->id}")->assertForbidden();

        // Nothing was mutated.
        $this->assertDatabaseHas('departments', ['id' => $department->id, 'name' => 'Engineering']);
        $this->assertDatabaseMissing('departments', ['name' => 'New']);
    }
}
