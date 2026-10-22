<?php

declare(strict_types=1);

namespace Tests\Feature\Departments;

use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Enums\Sector;
use App\Modules\Departments\Exceptions\DepartmentHierarchyException;
use App\Modules\Departments\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الجهة (sector) is declared on an إدارة and read by everything beneath it.
 *
 * The whole design rests on one claim: a unit's sector is not stored on the
 * unit, so it cannot disagree with the tree. These tests are that claim stated
 * as behaviour — the write rule that keeps the value in one place, and the read
 * path that makes storing it unnecessary.
 */
class DepartmentSectorTest extends TestCase
{
    use RefreshDatabase;

    private function root(): Department
    {
        return Department::create([
            'name'  => 'الإدارة العامة',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);
    }

    private function divisionUnder(Department $parent, string $name, ?Sector $sector): Department
    {
        return Department::create([
            'name'      => $name,
            'parent_id' => $parent->id,
            'level'     => DepartmentLevel::Division->value,
            'sector'    => $sector?->value,
        ]);
    }

    private function sectionUnder(Department $parent, string $name): Department
    {
        return Department::create([
            'name'      => $name,
            'parent_id' => $parent->id,
            'level'     => DepartmentLevel::Section->value,
        ]);
    }

    private function officeUnder(Department $parent, string $name): Department
    {
        return Department::create([
            'name'      => $name,
            'parent_id' => $parent->id,
            'level'     => DepartmentLevel::Office->value,
        ]);
    }

    // ------------------------------------------------------------ Read path

    public function test_a_division_reports_its_own_sector(): void
    {
        $finance = $this->divisionUnder($this->root(), 'الإدارة المالية', Sector::AdministrativeOffices);

        $this->assertSame(Sector::AdministrativeOffices, $finance->fresh()->sector);
        $this->assertSame('المكاتب الإدارية', $finance->sector->label());
    }

    public function test_sections_and_offices_inherit_from_the_nearest_ancestor(): void
    {
        $finance = $this->divisionUnder($this->root(), 'الإدارة المالية', Sector::AdministrativeOffices);
        $cash    = $this->sectionUnder($finance, 'قسم النقدية');
        $vault   = $this->officeUnder($cash, 'أمانة الصندوق');

        $this->assertSame(Sector::AdministrativeOffices, $cash->fresh()->sector);
        $this->assertSame(Sector::AdministrativeOffices, $vault->fresh()->sector);

        // …and they inherit it without storing it. The resolved read above and
        // the NULL below are the same fact seen from two sides.
        $this->assertNull(DB::table('departments')->where('id', $cash->id)->value('sector'));
        $this->assertNull(DB::table('departments')->where('id', $vault->id)->value('sector'));
    }

    public function test_the_root_and_governance_units_have_no_sector(): void
    {
        $root  = $this->root();
        $board = $this->divisionUnder($root, 'مجلس الإدارة', null);

        // The root spans both sectors and the board sits outside them, so
        // neither has an answer to inherit or to give.
        $this->assertNull($root->fresh()->sector);
        $this->assertNull($board->fresh()->sector);
    }

    // ------------------------------------------ The acceptance case (T2/§4.2)

    /**
     * Move a section between divisions in different sectors: its sector must
     * follow, and NOTHING may be written to the section's own row to make that
     * happen. If a `sector` column on the section were carrying the value, this
     * test is where it would be caught lying.
     */
    public function test_moving_a_section_between_sectors_changes_its_sector_with_no_write_to_its_row(): void
    {
        $this->actingAsAdmin();

        $root      = $this->root();
        $finance   = $this->divisionUnder($root, 'الإدارة المالية', Sector::AdministrativeOffices);
        $equipment = $this->divisionUnder($root, 'إدارة الآليات الثقيلة', Sector::AdministrativeBodies);

        $cash  = $this->sectionUnder($finance, 'قسم النقدية');
        $vault = $this->officeUnder($cash, 'أمانة الصندوق');

        $this->getJson("/api/departments/{$cash->id}")
            ->assertOk()
            ->assertJsonPath('data.sector', Sector::AdministrativeOffices->value)
            ->assertJsonPath('data.sector_label', 'المكاتب الإدارية');

        // The move. parent_id is the only field sent, and the only one changed.
        $this->patchJson("/api/departments/{$cash->id}", ['parent_id' => $equipment->id])
            ->assertOk();

        $this->getJson("/api/departments/{$cash->id}")
            ->assertOk()
            ->assertJsonPath('data.sector', Sector::AdministrativeBodies->value)
            ->assertJsonPath('data.sector_label', 'الأجسام الإدارية');

        // The office two tiers down followed too, without being touched at all.
        $this->getJson("/api/departments/{$vault->id}")
            ->assertOk()
            ->assertJsonPath('data.sector_label', 'الأجسام الإدارية');

        $this->assertNull(DB::table('departments')->where('id', $cash->id)->value('sector'));
        $this->assertNull(DB::table('departments')->where('id', $vault->id)->value('sector'));
    }

    // ----------------------------------------------------------- Write rule

    /**
     * The read-modify-write round trip that makes the guard necessary: the API
     * hands back a resolved sector, and a client that PUTs the object back is
     * offering to store it on a row that must not hold one.
     */
    public function test_echoing_a_resolved_sector_back_onto_a_section_is_refused(): void
    {
        $this->actingAsAdmin();

        $finance = $this->divisionUnder($this->root(), 'الإدارة المالية', Sector::AdministrativeOffices);
        $cash    = $this->sectionUnder($finance, 'قسم النقدية');

        $body = $this->getJson("/api/departments/{$cash->id}")->json('data');

        $this->putJson("/api/departments/{$cash->id}", [
            'name'      => 'قسم النقدية والصندوق',
            'level'     => $body['level'],
            'parent_id' => $body['parent_id'],
            'sector'    => $body['sector'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sector');

        $this->assertNull(DB::table('departments')->where('id', $cash->id)->value('sector'));
    }

    public function test_a_partial_update_that_omits_sector_is_untouched_by_the_rule(): void
    {
        $this->actingAsAdmin();

        $finance = $this->divisionUnder($this->root(), 'الإدارة المالية', Sector::AdministrativeOffices);
        $cash    = $this->sectionUnder($finance, 'قسم النقدية');

        // The fallback must read what the row STORES (null), not what it
        // RESOLVES to (1) — otherwise renaming a section would reject itself.
        $this->patchJson("/api/departments/{$cash->id}", ['name' => 'قسم النقدية والصندوق'])
            ->assertOk()
            ->assertJsonPath('data.name', 'قسم النقدية والصندوق')
            ->assertJsonPath('data.sector_label', 'المكاتب الإدارية');
    }

    public function test_a_division_may_declare_and_change_its_sector(): void
    {
        $this->actingAsAdmin();

        $division = $this->divisionUnder($this->root(), 'إدارة التسويق العقاري', Sector::AdministrativeOffices);

        $this->patchJson("/api/departments/{$division->id}", [
            'sector' => Sector::AdministrativeBodies->value,
        ])
            ->assertOk()
            ->assertJsonPath('data.sector_label', 'الأجسام الإدارية');

        $this->assertSame(
            Sector::AdministrativeBodies->value,
            (int) DB::table('departments')->where('id', $division->id)->value('sector'),
        );
    }

    public function test_an_undefined_sector_is_rejected(): void
    {
        $this->actingAsAdmin();
        $root = $this->root();

        $this->postJson('/api/departments', [
            'name'      => 'إدارة بجهة مجهولة',
            'parent_id' => $root->id,
            'level'     => DepartmentLevel::Division->value,
            'sector'    => 9,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sector');

        $this->assertDatabaseMissing('departments', ['name' => 'إدارة بجهة مجهولة']);
    }

    /**
     * The rule has to hold for the seeder, tinker and jobs too — T4 writes 118
     * rows through the model, and a form request would not see one of them.
     */
    public function test_the_sector_rule_holds_on_a_non_http_write(): void
    {
        $finance = $this->divisionUnder($this->root(), 'الإدارة المالية', Sector::AdministrativeOffices);

        try {
            Department::create([
                'name'      => 'قسم بجهة مخزّنة',
                'parent_id' => $finance->id,
                'level'     => DepartmentLevel::Section->value,
                'sector'    => Sector::AdministrativeOffices->value,
            ]);
            $this->fail('A section storing its own sector should have thrown.');
        } catch (DepartmentHierarchyException $exception) {
            $this->assertSame(
                'الجهة تُحدَّد على مستوى إدارة (المستوى 2) فقط؛ الوحدات الأدنى ترث جهتها من الوحدة الأعلى.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseMissing('departments', ['name' => 'قسم بجهة مخزّنة']);
    }

    /**
     * The schema stores a plain nullable int, exactly as `level` does. If the
     * sector ever grows a second column describing it, this fails.
     */
    public function test_the_database_stores_sector_as_a_nullable_int(): void
    {
        $finance = $this->divisionUnder($this->root(), 'الإدارة المالية', Sector::AdministrativeOffices);

        $raw = DB::table('departments')->where('id', $finance->id)->first();

        $this->assertSame(1, (int) $raw->sector);
        $this->assertObjectNotHasProperty('sector_label', $raw);
        $this->assertObjectNotHasProperty('sector_name', $raw);
    }
}
