<?php

declare(strict_types=1);

namespace Tests\Feature\Departments;

use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Exceptions\DepartmentHasChildrenException;
use App\Modules\Departments\Exceptions\DepartmentHierarchyException;
use App\Modules\Departments\Exceptions\DepartmentIsRootException;
use App\Modules\Departments\Models\Department;
use App\Modules\Departments\Support\DepartmentHierarchyGuard;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The organizational hierarchy contract:
 *
 *   الإدارة العامة (1, the single root) -> إدارة (2) -> قسم (3) -> [future tiers].
 *
 * The FK on parent_id only proves the parent row exists. Everything asserted
 * here is a rule the schema cannot express, enforced ONCE in
 * DepartmentHierarchyGuard and reached from every write path — the HTTP front
 * door (form requests, 422) AND the model's saving/deleting hooks (domain
 * exceptions). These tests are the actual specification of the tree's shape.
 */
class DepartmentHierarchyTest extends TestCase
{
    use RefreshDatabase;

    // Fixtures write straight through the model, so they also exercise the
    // model-layer guard (a bad fixture would throw, not silently persist).

    private function root(string $name = 'الإدارة العامة'): Department
    {
        return Department::create([
            'name'  => $name,
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);
    }

    private function divisionUnder(Department $parent, string $name = 'الإدارة الهندسية'): Department
    {
        return Department::create([
            'name'      => $name,
            'parent_id' => $parent->id,
            'level'     => DepartmentLevel::Division->value,
        ]);
    }

    private function sectionUnder(Department $parent, string $name = 'قسم التصميم'): Department
    {
        return Department::create([
            'name'      => $name,
            'parent_id' => $parent->id,
            'level'     => DepartmentLevel::Section->value,
        ]);
    }

    // ------------------------------------------------- Root shape / numbering

    public function test_the_root_is_the_general_administration_at_level_one(): void
    {
        $root = $this->root();

        $this->assertSame(DepartmentLevel::GeneralAdministration, $root->fresh()->level);
        $this->assertSame(1, $root->level->value);
        $this->assertSame('الإدارة العامة', $root->level->label());
        $this->assertNull($root->parent_id);
    }

    public function test_a_division_is_never_a_root_it_sits_under_the_general_administration(): void
    {
        $root = $this->root();
        $division = $this->divisionUnder($root);

        $this->assertSame($root->id, $division->parent_id);
        $this->assertSame(DepartmentLevel::Division, $division->fresh()->level);

        // Only the general administration is parentless.
        $this->assertSame(1, Department::whereNull('parent_id')->count());
        $this->assertTrue(Department::whereNull('parent_id')->first()->is($root));
    }

    // ---------------------------------------------------------------- Rule 1

    public function test_a_root_must_be_the_general_administration(): void
    {
        $this->actingAsAdmin();

        // A parentless division is not allowed: the root tier is level 1.
        $this->postJson('/api/departments', [
            'name'      => 'إدارة بلا أصل',
            'parent_id' => null,
            'level'     => DepartmentLevel::Division->value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('level');

        $this->assertDatabaseMissing('departments', ['name' => 'إدارة بلا أصل']);
    }

    public function test_the_general_administration_root_is_accepted(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/departments', [
            'name'  => 'الإدارة العامة',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ])
            ->assertCreated()
            ->assertJsonPath('data.level', 1)
            ->assertJsonPath('data.level_label', 'الإدارة العامة');
    }

    // ------------------------------------------------------- Singleton root

    public function test_a_second_root_is_rejected_on_create(): void
    {
        $this->actingAsAdmin();
        $this->root();

        $this->postJson('/api/departments', [
            'name'  => 'جذر ثانٍ',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');

        $this->assertSame(1, Department::whereNull('parent_id')->count());
    }

    public function test_a_unit_cannot_be_turned_into_a_second_root_on_update(): void
    {
        $this->actingAsAdmin();
        $root = $this->root();
        $division = $this->divisionUnder($root);

        $this->putJson("/api/departments/{$division->id}", [
            'parent_id' => null,
            'level'     => DepartmentLevel::GeneralAdministration->value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');

        $this->assertDatabaseHas('departments', ['id' => $division->id, 'parent_id' => $root->id]);
    }

    // ---------------------------------------------------------------- Rule 2

    public function test_a_division_under_the_root_is_accepted(): void
    {
        $this->actingAsAdmin();
        $root = $this->root();

        $this->postJson('/api/departments', [
            'name'      => 'الإدارة الهندسية',
            'parent_id' => $root->id,
            'level'     => DepartmentLevel::Division->value,
        ])
            ->assertCreated()
            ->assertJsonPath('data.parent_id', $root->id)
            ->assertJsonPath('data.level_label', 'إدارة');
    }

    public function test_a_section_under_a_division_is_accepted(): void
    {
        $this->actingAsAdmin();
        $division = $this->divisionUnder($this->root());

        $this->postJson('/api/departments', [
            'name'      => 'قسم التصميم',
            'parent_id' => $division->id,
            'level'     => DepartmentLevel::Section->value,
        ])
            ->assertCreated()
            ->assertJsonPath('data.parent_id', $division->id)
            ->assertJsonPath('data.level_label', 'قسم');
    }

    public function test_a_child_must_sit_exactly_one_tier_below_its_parent(): void
    {
        $this->actingAsAdmin();
        $division = $this->divisionUnder($this->root());

        // A division nested under a division: same tier, not parent.level + 1.
        $this->postJson('/api/departments', [
            'name'      => 'إدارة داخل إدارة',
            'parent_id' => $division->id,
            'level'     => DepartmentLevel::Division->value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('level');
    }

    public function test_a_child_of_the_root_must_be_a_division_not_a_section(): void
    {
        $this->actingAsAdmin();
        $root = $this->root();

        // Skipping the division tier: a section directly under the root.
        $this->postJson('/api/departments', [
            'name'      => 'قسم تحت الإدارة العامة',
            'parent_id' => $root->id,
            'level'     => DepartmentLevel::Section->value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('level');
    }

    public function test_nesting_under_the_deepest_tier_is_rejected(): void
    {
        $this->actingAsAdmin();
        $section = $this->sectionUnder($this->divisionUnder($this->root()));

        // Section is currently the deepest defined tier; level 4 maps to no case.
        $this->postJson('/api/departments', [
            'name'      => 'وحدة تحت قسم',
            'parent_id' => $section->id,
            'level'     => 4,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('level');
    }

    // ---------------------------------------------------------------- Rule 3

    public function test_an_undefined_level_is_rejected(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/departments', ['name' => 'مستوى مجهول', 'level' => 9])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('level');

        $this->assertDatabaseMissing('departments', ['name' => 'مستوى مجهول']);
    }

    public function test_level_is_required_on_create(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/departments', ['name' => 'بلا مستوى'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('level');
    }

    // ---------------------------------------------------------------- Rule 4

    public function test_a_missing_parent_is_rejected(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/departments', [
            'name'      => 'يتيم',
            'parent_id' => 9999,
            'level'     => DepartmentLevel::Division->value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_a_soft_deleted_parent_is_rejected(): void
    {
        $this->actingAsAdmin();
        $division = $this->divisionUnder($this->root());
        $division->delete(); // a childless, non-root unit soft-deletes fine.

        $this->postJson('/api/departments', [
            'name'      => 'قسم تحت إدارة محذوفة',
            'parent_id' => $division->id,
            'level'     => DepartmentLevel::Section->value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    }

    // ---------------------------------------------------------------- Rule 5

    public function test_a_unit_cannot_be_its_own_parent(): void
    {
        $this->actingAsAdmin();
        $division = $this->divisionUnder($this->root());

        $this->putJson("/api/departments/{$division->id}", ['parent_id' => $division->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    }

    // ---------------------------------------------------------------- Rule 6

    public function test_a_unit_cannot_be_moved_under_its_own_descendant(): void
    {
        $this->actingAsAdmin();
        $root     = $this->root();
        $division = $this->divisionUnder($root);
        $section  = $this->sectionUnder($division);

        // Move the division under its own section child — a loop.
        $this->putJson("/api/departments/{$division->id}", ['parent_id' => $section->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');

        $this->assertDatabaseHas('departments', ['id' => $division->id, 'parent_id' => $root->id]);
    }

    /**
     * Exercises the cycle walk directly on the guard. With three real tiers a
     * loop is now expressible without any raw future-tier fixture: moving a
     * division under its own section is a cycle, and the guard catches it before
     * it ever consults the parent's level.
     */
    public function test_the_cycle_guard_rejects_a_descendant_as_parent(): void
    {
        $root     = $this->root();
        $division = $this->divisionUnder($root);
        $section  = $this->sectionUnder($division);

        $violations = (new DepartmentHierarchyGuard())->violations(
            DepartmentLevel::Division->value,
            $section->id,
            $division->id,
        );

        $this->assertCount(1, $violations);
        $this->assertSame('parent_id', $violations[0]->field);
        $this->assertSame(
            'لا يمكن نقل الوحدة التنظيمية لتصبح تابعة لإحدى الوحدات الفرعية التابعة لها.',
            $violations[0]->message,
        );
    }

    /**
     * The mirror: a parent OUTSIDE the subject's subtree must survive the walk.
     * Without this, a guard that simply always errored would look identical.
     */
    public function test_the_cycle_guard_allows_a_parent_outside_the_subtree(): void
    {
        $root        = $this->root();
        $engineering = $this->divisionUnder($root, 'الإدارة الهندسية');
        $finance     = $this->divisionUnder($root, 'الإدارة المالية');
        $section     = $this->sectionUnder($engineering, 'قسم التصميم');

        // Re-parent the section from Engineering to Finance: a legitimate move.
        $violations = (new DepartmentHierarchyGuard())->violations(
            DepartmentLevel::Section->value,
            $finance->id,
            $section->id,
        );

        $this->assertSame([], $violations);
    }

    // ------------------------------------------------- Partial (PATCH) update

    public function test_renaming_without_resending_level_stays_valid(): void
    {
        $this->actingAsAdmin();
        $section = $this->sectionUnder($this->divisionUnder($this->root()));

        // level/parent_id omitted: the effective values fall back to the row's
        // own, so the child rule still holds rather than mis-firing.
        $this->patchJson("/api/departments/{$section->id}", ['name' => 'قسم الإنشاءات'])
            ->assertOk()
            ->assertJsonPath('data.name', 'قسم الإنشاءات')
            ->assertJsonPath('data.level', 3);
    }

    // -------------------------------------------------- Soft-delete children

    public function test_deleting_a_unit_with_children_is_blocked(): void
    {
        $this->actingAsAdmin();
        $division = $this->divisionUnder($this->root());
        $this->sectionUnder($division);

        // The division (not the root) is targeted, so this is the CHILDREN
        // guard firing, not the root guard.
        $this->deleteJson("/api/departments/{$division->id}")
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'لا يمكن حذف وحدة تنظيمية تحتوي على وحدات فرعية. الرجاء نقل أو حذف الوحدات الفرعية أولًا.',
            );

        $this->assertNotSoftDeleted('departments', ['id' => $division->id]);
    }

    public function test_deleting_a_unit_whose_children_are_trashed_is_allowed(): void
    {
        $this->actingAsAdmin();
        $division = $this->divisionUnder($this->root());
        $section  = $this->sectionUnder($division);

        $section->delete();

        // The subtree is empty of live rows, so the division may now go.
        $this->deleteJson("/api/departments/{$division->id}")->assertOk();

        $this->assertSoftDeleted('departments', ['id' => $division->id]);
    }

    /**
     * The children guard is on the model, not the controller, so it holds for
     * callers that never touch HTTP — seeders, jobs, tinker, other modules.
     */
    public function test_the_children_guard_holds_outside_the_http_layer(): void
    {
        $division = $this->divisionUnder($this->root());
        $this->sectionUnder($division);

        $this->expectException(DepartmentHasChildrenException::class);

        $division->delete();
    }

    // ------------------------------------------------------- Undeletable root

    public function test_the_root_cannot_be_soft_deleted_via_http_even_with_no_children(): void
    {
        $this->actingAsAdmin();
        $root = $this->root(); // a leaf root: no children to block it.

        $this->deleteJson("/api/departments/{$root->id}")
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'لا يمكن حذف الإدارة العامة، فهي الوحدة الجذر لهيكل الشركة.');

        $this->assertNotSoftDeleted('departments', ['id' => $root->id]);
    }

    public function test_the_root_cannot_be_deleted_outside_the_http_layer(): void
    {
        $root = $this->root();

        $this->expectException(DepartmentIsRootException::class);

        $root->delete();
    }

    public function test_the_root_cannot_be_force_deleted(): void
    {
        $root = $this->root();

        $this->expectException(DepartmentIsRootException::class);

        $root->forceDelete();
    }

    // --------------------------------------- Invariants on a non-HTTP write

    /**
     * The regression test for the architectural fix (batch item 3): the tier
     * invariants must hold when a row is written straight through the model,
     * bypassing the form request entirely. Without the model-layer guard this
     * would silently persist a second root — exactly what let the old seeder
     * build a forest.
     */
    public function test_the_hierarchy_guard_holds_on_a_non_http_write(): void
    {
        $this->root();

        try {
            Department::create([
                'name'  => 'جذر ثانٍ',
                'level' => DepartmentLevel::GeneralAdministration->value,
            ]);
            $this->fail('A second root written straight through the model should have thrown.');
        } catch (DepartmentHierarchyException) {
            // expected
        }

        // The guard fired BEFORE the insert: nothing was written.
        $this->assertDatabaseMissing('departments', ['name' => 'جذر ثانٍ']);
        $this->assertSame(1, Department::whereNull('parent_id')->count());
    }

    public function test_a_mis_tiered_child_is_rejected_on_a_non_http_write(): void
    {
        $division = $this->divisionUnder($this->root());

        $this->expectException(DepartmentHierarchyException::class);

        // A division nested under a division, straight through the model.
        Department::create([
            'name'      => 'إدارة داخل إدارة',
            'parent_id' => $division->id,
            'level'     => DepartmentLevel::Division->value,
        ]);
    }

    // --------------------------------------------------------- Seeded tree

    public function test_the_seeded_tree_has_all_three_tiers_and_a_single_root(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, Department::whereNull('parent_id')->count());

        $root = Department::whereNull('parent_id')->firstOrFail();
        $this->assertSame(DepartmentLevel::GeneralAdministration, $root->level);

        $this->assertGreaterThan(0, Department::atLevel(DepartmentLevel::Division)->count());
        $this->assertGreaterThan(0, Department::atLevel(DepartmentLevel::Section)->count());
    }

    // ------------------------------------------------------- Model / enum

    public function test_scopes_and_relations_walk_the_tree(): void
    {
        $root     = $this->root();
        $division = $this->divisionUnder($root);
        $section  = $this->sectionUnder($division);

        $this->assertTrue($division->children->contains($section));
        $this->assertTrue($section->parent->is($division));
        $this->assertTrue($division->parent->is($root));
        $this->assertSame(1, Department::atLevel(DepartmentLevel::GeneralAdministration)->count());
        $this->assertSame(1, Department::divisions()->count());
        $this->assertSame(1, Department::sections()->count());
    }

    public function test_level_is_cast_to_the_enum(): void
    {
        $root     = $this->root();
        $division = $this->divisionUnder($root);

        $this->assertSame(DepartmentLevel::GeneralAdministration, $root->fresh()->level);
        $this->assertSame(DepartmentLevel::Division, $division->fresh()->level);
        $this->assertSame('إدارة', $division->level->label());
        $this->assertSame(DepartmentLevel::Division, DepartmentLevel::GeneralAdministration->next());
        $this->assertSame(DepartmentLevel::Section, DepartmentLevel::Division->next());
        $this->assertNull(DepartmentLevel::Section->next());
    }

    /**
     * The schema stores a plain int; the enum is only a PHP-side lens. If a
     * `type`/`tier` column ever appears alongside it, this fails.
     */
    public function test_the_database_stores_level_as_an_int(): void
    {
        $root = $this->root();

        $raw = DB::table('departments')->where('id', $root->id)->first();

        $this->assertSame(1, (int) $raw->level);
        $this->assertObjectNotHasProperty('type', $raw);
        $this->assertObjectNotHasProperty('tier', $raw);
    }
}
