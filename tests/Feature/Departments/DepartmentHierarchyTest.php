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
use ValueError;

/**
 * The organizational hierarchy contract:
 *
 *   الإدارة العامة (1, the single root) -> إدارة (2) -> قسم (3) -> مكتب (4).
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

    private function officeUnder(Department $parent, string $name = 'المكتب المعماري'): Department
    {
        return Department::create([
            'name'      => $name,
            'parent_id' => $parent->id,
            'level'     => DepartmentLevel::Office->value,
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

    public function test_an_office_under_a_section_is_accepted(): void
    {
        $this->actingAsAdmin();
        $section = $this->sectionUnder($this->divisionUnder($this->root()));

        $this->postJson('/api/departments', [
            'name'      => 'مكتب الطبوغرافيا',
            'parent_id' => $section->id,
            'level'     => DepartmentLevel::Office->value,
        ])
            ->assertCreated()
            ->assertJsonPath('data.parent_id', $section->id)
            ->assertJsonPath('data.level', 4)
            ->assertJsonPath('data.level_label', 'مكتب');
    }

    public function test_an_office_cannot_skip_the_section_tier(): void
    {
        $this->actingAsAdmin();
        $division = $this->divisionUnder($this->root());

        $this->postJson('/api/departments', [
            'name'      => 'مكتب تحت إدارة',
            'parent_id' => $division->id,
            'level'     => DepartmentLevel::Office->value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('level');

        $this->assertDatabaseMissing('departments', ['name' => 'مكتب تحت إدارة']);
    }

    public function test_nesting_under_the_deepest_tier_is_rejected(): void
    {
        $this->actingAsAdmin();
        $office = $this->officeUnder($this->sectionUnder($this->divisionUnder($this->root())));

        // The ceiling is the enum's case list, not a constant: one past the
        // deepest tier maps to no case, so nothing may nest under مكتب.
        $this->postJson('/api/departments', [
            'name'      => 'وحدة تحت مكتب',
            'parent_id' => $office->id,
            'level'     => DepartmentLevel::Office->value + 1,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('level');
    }

    /**
     * The model-layer path for the same ceiling — and the two refusals a
     * non-HTTP writer actually gets, which are NOT the same mechanism.
     *
     * A level-5 row never reaches the guard: the enum cast rejects it inside
     * setAttribute, before the save begins. That is the depth registry doing its
     * job one layer earlier than expected, and it is why the guard's
     * defined-level branch exists for raw-attribute writers rather than for this.
     *
     * A level-4 row under a level-4 parent is a different failure entirely: the
     * level is perfectly valid, so the cast is happy and the guard is what
     * refuses it — مكتب has no tier beneath it.
     */
    public function test_the_depth_ceiling_holds_on_a_non_http_write(): void
    {
        $office = $this->officeUnder($this->sectionUnder($this->divisionUnder($this->root())));

        try {
            Department::create([
                'name'      => 'طبقة خامسة',
                'parent_id' => $office->id,
                'level'     => DepartmentLevel::Office->value + 1,
            ]);
            $this->fail('A level-5 unit written straight through the model should have thrown.');
        } catch (ValueError $exception) {
            $this->assertStringContainsString('not a valid backing value', $exception->getMessage());
        }

        try {
            Department::create([
                'name'      => 'مكتب تحت مكتب',
                'parent_id' => $office->id,
                'level'     => DepartmentLevel::Office->value,
            ]);
            $this->fail('An office nested under an office should have thrown.');
        } catch (DepartmentHierarchyException $exception) {
            $this->assertSame(
                'لا يمكن إضافة وحدات فرعية تحت مكتب لأنها أدنى مستوى في هيكل الشركة.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseMissing('departments', ['name' => 'طبقة خامسة']);
        $this->assertDatabaseMissing('departments', ['name' => 'مكتب تحت مكتب']);
    }

    /**
     * The raw-attribute path the cast cannot intercept — the one the guard's
     * defined-level branch is actually there for.
     */
    public function test_an_undefined_level_written_past_the_cast_is_rejected_by_the_guard(): void
    {
        $orphan = new Department;
        $orphan->setRawAttributes(['name' => 'مستوى خارج السجل', 'level' => 9]);

        $this->expectException(DepartmentHierarchyException::class);
        $this->expectExceptionMessage('المستوى المحدد غير معرّف في هيكل الشركة.');

        $orphan->save();
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

        $violations = (new DepartmentHierarchyGuard)->violations(
            DepartmentLevel::Division->value,
            $section->id,
            $division->id,
            null,
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
        $violations = (new DepartmentHierarchyGuard)->violations(
            DepartmentLevel::Section->value,
            $finance->id,
            $section->id,
            null,
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

    /**
     * The guard reads children(), not a tier, so the new bottom tier is covered
     * by the same rule that always covered the others: a section holding
     * offices strands them exactly as a division holding sections would.
     */
    public function test_a_section_holding_offices_cannot_be_soft_deleted(): void
    {
        $this->actingAsAdmin();
        $section = $this->sectionUnder($this->divisionUnder($this->root()));
        $office  = $this->officeUnder($section);

        $this->deleteJson("/api/departments/{$section->id}")
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                'لا يمكن حذف وحدة تنظيمية تحتوي على وحدات فرعية. الرجاء نقل أو حذف الوحدات الفرعية أولًا.',
            );

        $this->assertNotSoftDeleted('departments', ['id' => $section->id]);

        // The 409 above is that exception surfacing through the handler; the
        // rule itself is on the model, so a non-HTTP caller meets it directly.
        try {
            $section->delete();
            $this->fail('Soft-deleting a section that still holds offices should have thrown.');
        } catch (DepartmentHasChildrenException) {
            // expected
        }

        // And once the office is gone, the section may follow.
        $office->delete();
        $this->deleteJson("/api/departments/{$section->id}")->assertOk();
        $this->assertSoftDeleted('departments', ['id' => $section->id]);
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

    /**
     * The full seeding path — RBAC, the real tree, and the development
     * fixtures layered on top of it — still yields one tree with every tier
     * populated. The exact company shape is DepartmentSeederTest's job; what
     * matters here is that adding the dev fixtures does not fork the tree.
     */
    public function test_the_seeded_tree_populates_every_tier_under_a_single_root(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, Department::whereNull('parent_id')->count());

        $root = Department::whereNull('parent_id')->firstOrFail();
        $this->assertSame(DepartmentLevel::GeneralAdministration, $root->level);

        foreach (DepartmentLevel::cases() as $tier) {
            $this->assertGreaterThan(
                0,
                Department::atLevel($tier)->count(),
                "No unit was seeded at tier {$tier->label()}.",
            );
        }
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
    }

    /**
     * next()/previous() are the only definition of "what sits under what", and
     * the case list is the only definition of how deep the tree goes. Naming a
     * tier as the leaf — `assertNull(Section->next())` — is what this assertion
     * used to do, and it is why adding مكتب broke a test that was supposed to be
     * checking the enum's derivation rather than its contents.
     */
    public function test_the_tier_chain_is_derived_from_the_enum_case_list(): void
    {
        $tiers = DepartmentLevel::cases();

        foreach ($tiers as $index => $tier) {
            $this->assertSame($tiers[$index + 1] ?? null, $tier->next());
            $this->assertSame($tiers[$index - 1] ?? null, $tier->previous());
        }

        $this->assertSame(DepartmentLevel::root(), $tiers[0]);
        $this->assertNull(end($tiers)->next());
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
