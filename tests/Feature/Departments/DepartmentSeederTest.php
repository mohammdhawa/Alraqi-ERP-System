<?php

declare(strict_types=1);

namespace Tests\Feature\Departments;

use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Enums\Sector;
use App\Modules\Departments\Models\Department;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DepartmentSeeder produces the real company, not a demo tree.
 *
 * The counts below are the point of the file. They come from
 * Program_HR_2_2026.xlsx (sheet دليل الهيكل والمسميات الوظيفية) after the
 * exclusion rules in the seeder's docblock, and they are asserted exactly rather
 * than as "greater than zero" because the failure this guards against is a
 * silent one: a mistyped `code` collapses two rows into one, or a missing
 * `children` key drops a whole branch, and the tree still looks plausible.
 */
class DepartmentSeederTest extends TestCase
{
    use RefreshDatabase;

    private const DIVISIONS = 13;

    private const SECTIONS = 53;

    private const OFFICES = 50;

    /**
     * Root + مجلس الإدارة + مكتب المدير العام + the tiers above.
     */
    private const TOTAL = 1 + 2 + self::DIVISIONS + self::SECTIONS + self::OFFICES;

    public function test_it_seeds_the_company_tree(): void
    {
        $this->seed(DepartmentSeeder::class);

        $this->assertSame(self::TOTAL, Department::count());
        $this->assertSame(self::SECTIONS, Department::atLevel(DepartmentLevel::Section)->count());
        $this->assertSame(self::OFFICES, Department::atLevel(DepartmentLevel::Office)->count());

        // 13 divisions carry a sector; مجلس الإدارة and مكتب المدير العام are
        // the two level-2 rows that do not, which is how they are told apart
        // from a division without adding a column that says so.
        $this->assertSame(self::DIVISIONS + 2, Department::atLevel(DepartmentLevel::Division)->count());
        $this->assertSame(self::DIVISIONS, Department::whereNotNull('sector')->count());
    }

    /**
     * The root is the administration itself, not the company as a legal entity.
     * Asserting that its name equals its tier's label is the cheapest way to
     * catch a regression to the invented `شركة الراقي للإنشاءات` row: the tier
     * has always been called الإدارة العامة, and the one row at it should be it.
     */
    public function test_the_tree_has_exactly_one_root(): void
    {
        $this->seed(DepartmentSeeder::class);

        $this->assertSame(1, Department::whereNull('parent_id')->count());

        $root = Department::whereNull('parent_id')->firstOrFail();
        $this->assertSame(DepartmentLevel::GeneralAdministration, $root->level);
        $this->assertSame('الإدارة العامة', $root->name);
        $this->assertSame($root->level->label(), $root->name);
        $this->assertNull($root->sector);
    }

    /**
     * مجلس الإدارة is a container for people, not a node anything reports to.
     * Both halves matter: it must exist (its members are employees and need a
     * department_id), and it must stay childless (no unit reports to the board).
     * If a subtree ever appears under it, the compromise recorded in the
     * seeder's docblock has expired and the tree shape needs revisiting.
     */
    public function test_the_board_is_a_childless_container_beside_the_divisions(): void
    {
        $this->seed(DepartmentSeeder::class);

        $board = Department::where('code', 'BOD')->firstOrFail();
        $root = Department::whereNull('parent_id')->firstOrFail();

        $this->assertSame(DepartmentLevel::Division, $board->level);
        $this->assertSame($root->id, $board->parent_id);
        $this->assertSame(0, $board->children()->count());
        $this->assertNull($board->sector);
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $this->seed(DepartmentSeeder::class);
        $fingerprint = $this->fingerprint();

        $this->seed(DepartmentSeeder::class);

        // Not just the row count: names, parents, levels and sectors too, so a
        // second run that silently re-parented a branch would still fail.
        $this->assertSame($fingerprint, $this->fingerprint());
        $this->assertSame(self::TOTAL, Department::count());
        $this->assertSame(1, Department::whereNull('parent_id')->count());
    }

    /**
     * Every field a second run could quietly change, ordered by the stable key.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fingerprint(): array
    {
        return DB::table('departments')
            ->orderBy('code')
            ->get(['code', 'name', 'parent_id', 'level', 'sector', 'is_active'])
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    /**
     * The sector rule holds across the seeded tree: 13 rows declare one, and
     * every one of them is an إدارة. If the seeder ever wrote a sector onto a
     * section the guard would have thrown, but this states the invariant as data
     * rather than trusting the exception to have been raised.
     */
    public function test_only_divisions_store_a_sector(): void
    {
        $this->seed(DepartmentSeeder::class);

        $levels = Department::whereNotNull('sector')
            ->pluck('level')
            ->map(fn (DepartmentLevel $level): int => $level->value)
            ->unique()
            ->values()
            ->all();

        $this->assertSame([DepartmentLevel::Division->value], $levels);
    }

    public function test_sections_and_offices_resolve_their_sector_by_walking_up(): void
    {
        $this->seed(DepartmentSeeder::class);

        $vault = Department::where('code', 'FIN-CASH-VAULT')->firstOrFail();
        $heavyVault = Department::where('code', 'HEQ-FIN-VAULT')->firstOrFail();

        // Same name, three tiers down, opposite sectors — which is exactly why
        // the employee import will key on `code` and not on `name`.
        $this->assertSame('أمانة الصندوق', $vault->name);
        $this->assertSame('أمانة الصندوق', $heavyVault->name);
        $this->assertSame(Sector::AdministrativeOffices, $vault->sector);
        $this->assertSame(Sector::AdministrativeBodies, $heavyVault->sector);

        $this->assertNull(DB::table('departments')->where('code', 'FIN-CASH-VAULT')->value('sector'));
        $this->assertNull(DB::table('departments')->where('code', 'HEQ-FIN-VAULT')->value('sector'));
    }

    public function test_the_pending_sales_office_is_seeded_inactive(): void
    {
        $this->seed(DepartmentSeeder::class);

        $sales = Department::where('code', 'SUP-PUR-SALE')->firstOrFail();

        // "معلق لحين الاجتماع" in both sources: the unit exists and is out of
        // the selection lists, so activating it is a data change.
        $this->assertSame('المبيعات', $sales->name);
        $this->assertFalse($sales->is_active);
        $this->assertSame(1, Department::where('is_active', false)->count());
    }

    /**
     * The exclusion rules (ratified decision #6) are the reason the tree stops
     * at four tiers. Asserting the excluded rows are absent is what stops
     * someone re-adding them from the raw sheet later.
     */
    public function test_the_excluded_rows_are_absent(): void
    {
        $this->seed(DepartmentSeeder::class);

        // Equipment classes under قسم السائقين, which therefore has no children.
        $drivers = Department::where('code', 'HEQ-DRV')->firstOrFail();
        $this->assertSame(0, $drivers->children()->count());

        foreach (['باكر', 'بلدوزر', 'تركس', 'لودر', 'رافعة', 'صهريج', 'مدحلة', 'كليدر'] as $machine) {
            $this->assertDatabaseMissing('departments', ['name' => $machine]);
        }

        // Workshops are job titles, not units.
        $this->assertSame(0, Department::where('name', 'like', 'ورشة%')->count());

        // The duplicate قسم الصيانة that appeared as an office inside
        // قسم الصيانة (رحبة الصيانة).
        $heavyMaintenance = Department::where('code', 'HEQ-MNT')->firstOrFail();
        $this->assertNotContains('قسم الصيانة', $heavyMaintenance->children()->pluck('name')->all());
    }

    /**
     * The tree follows the sources even where they look wrong to a reader.
     *
     * قسم الشؤون القانونية exists under إدارة التسويق العقاري AND as a division
     * of its own. That is what both source artefacts show, so both are seeded.
     * This is also the strongest case for `code` being the import key: these two
     * units are indistinguishable by name and sit in different sectors.
     */
    public function test_the_duplicated_legal_unit_is_kept_and_distinguished_by_code(): void
    {
        $this->seed(DepartmentSeeder::class);

        $division = Department::where('code', 'LEG')->firstOrFail();
        $section = Department::where('code', 'REM-LEG')->firstOrFail();

        $this->assertSame('إدارة الشؤون القانونية', $division->name);
        $this->assertSame('قسم الشؤون القانونية', $section->name);

        $this->assertSame(DepartmentLevel::Division, $division->level);
        $this->assertSame(DepartmentLevel::Section, $section->level);

        $this->assertSame(Sector::AdministrativeOffices, $division->sector);
        $this->assertSame(Sector::AdministrativeBodies, $section->sector);
    }

    public function test_no_unit_name_was_normalized_away_or_left_untidy(): void
    {
        $this->seed(DepartmentSeeder::class);

        foreach (Department::pluck('name') as $name) {
            $this->assertSame($name, trim($name), "Name has stray whitespace: [{$name}]");
        }

        // The four source misspellings, corrected.
        $this->assertDatabaseHas('departments', ['name' => 'الاستعلامات']);
        $this->assertDatabaseHas('departments', ['name' => 'قسم العقود والاتفاقيات']);
        $this->assertDatabaseHas('departments', ['name' => 'الكاميرات']);
        $this->assertDatabaseHas('departments', ['name' => 'قسم اللوجستيك والمستودعات']);

        $this->assertDatabaseMissing('departments', ['name' => 'الإستعلامات']);
        $this->assertDatabaseMissing('departments', ['name' => 'قسم العقود والإتفاقيات']);
        $this->assertDatabaseMissing('departments', ['name' => 'الكميرات']);
    }

    /**
     * Codes are the import key, so a duplicate would silently merge two units.
     */
    public function test_every_unit_has_a_unique_code(): void
    {
        $this->seed(DepartmentSeeder::class);

        $codes = Department::pluck('code');

        $this->assertCount(self::TOTAL, $codes);
        $this->assertSame($codes->count(), $codes->unique()->count());
        $this->assertSame(0, Department::whereNull('code')->count());
    }
}
