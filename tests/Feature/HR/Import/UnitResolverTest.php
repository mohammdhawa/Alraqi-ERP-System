<?php

declare(strict_types=1);

namespace Tests\Feature\HR\Import;

use App\Modules\Departments\Models\Department;
use App\Modules\HR\Import\UnitResolver;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Placing a workbook row in the org tree (work order §4.4).
 *
 * Run against the REAL seeded tree, because the rules exist for the real
 * spellings in the October workbook: hamza and ta-marbuta variants, placeholder
 * rows, the drivers' equipment classes, the workshops, and a division the tree
 * does not have yet.
 */
class UnitResolverTest extends TestCase
{
    use RefreshDatabase;

    private UnitResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DepartmentSeeder::class);
        $this->resolver = UnitResolver::fromDatabase();
    }

    private function code(?int $departmentId): ?string
    {
        return $departmentId === null ? null : Department::find($departmentId)->code;
    }

    public function test_a_full_path_lands_on_the_office(): void
    {
        $r = $this->resolver->resolve('الإدارة المالية', 'قسم النقدية', 'أمانة الصندوق');

        $this->assertSame('FIN-CASH-VAULT', $this->code($r->departmentId));
        $this->assertSame([], $r->issues);
    }

    public function test_spelling_variants_still_match(): void
    {
        $r = $this->resolver->resolve('الاداره الماليه', 'قسم  النقديه', 'امانة الصندوق ');

        $this->assertSame('FIN-CASH-VAULT', $this->code($r->departmentId));
    }

    public function test_an_office_name_used_in_several_sections_resolves_by_its_path(): void
    {
        $engineering = $this->resolver->resolve('الإدارة الهندسية', 'القسم المالي الهندسي', 'الحسابات');
        $production = $this->resolver->resolve('إدارة المشاريع الإنتاجية', 'القسم المالي الإنتاجي', 'الحسابات');

        $this->assertSame('ENG-FIN-ACC', $this->code($engineering->departmentId));
        $this->assertSame('PRD-FIN-ACC', $this->code($production->departmentId));
    }

    public function test_placeholder_and_blank_offices_land_on_the_section(): void
    {
        $this->assertSame('FIN-BUD', $this->code($this->resolver->resolve('الإدارة المالية', 'قسم الموازنات', 'مدير قسم الموازنات')->departmentId));
        $this->assertSame('FIN-BUD', $this->code($this->resolver->resolve('الإدارة المالية', 'قسم الموازنات', null)->departmentId));
        $this->assertSame('FIN', $this->code($this->resolver->resolve('الإدارة المالية', 'مدير الإدارة المالية', null)->departmentId));

        $head = $this->resolver->resolve('الإدارة المالية', 'قسم الموازنات', 'رئيس قسم الموازنات');
        $this->assertSame('FIN-BUD', $this->code($head->departmentId));
        $this->assertSame([], $head->issues, 'a «رئيس …» row is the section itself, not an unknown office');
    }

    public function test_a_drivers_equipment_class_lands_on_the_section_and_is_noted(): void
    {
        $r = $this->resolver->resolve('إدارة الآليات الثقيلة', 'قسم السائقين', 'باكر');

        $this->assertSame('HEQ-DRV', $this->code($r->departmentId));
        $this->assertSame('info', $r->issues[0]['severity']);
        $this->assertStringContainsString('باكر', $r->issues[0]['message']);
    }

    public function test_a_workshop_lands_on_the_section_and_is_noted(): void
    {
        $r = $this->resolver->resolve('الإدارة الهندسية', 'القسم التنفيذي الهندسي', 'ورشة التشغيل');

        $this->assertSame('ENG-EXEC', $this->code($r->departmentId));
        $this->assertSame('info', $r->issues[0]['severity']);
    }

    public function test_an_unknown_office_falls_back_to_the_section_with_a_warning(): void
    {
        $r = $this->resolver->resolve('إدارة العلاقات العامة', 'قسم العلاقات الخارجية', 'مكتب العقود والمعاملات');

        $this->assertSame('PR-EXT', $this->code($r->departmentId));
        $this->assertSame('warning', $r->issues[0]['severity']);
    }

    public function test_an_unknown_section_falls_back_to_the_division_with_a_warning(): void
    {
        $r = $this->resolver->resolve('إدارة الإمداد والتوريد', 'قسم المحاسبة', null);

        $this->assertSame('SUP', $this->code($r->departmentId));
        $this->assertSame('warning', $r->issues[0]['severity']);
    }

    public function test_an_unknown_division_cannot_be_placed(): void
    {
        $r = $this->resolver->resolve('إدارة الجودة والسلامة والمهنية', null, null);

        $this->assertNull($r->departmentId);
        $this->assertSame('error', $r->issues[0]['severity']);
    }

    public function test_known_aliases_resolve(): void
    {
        $this->assertSame('MKT', $this->code($this->resolver->resolve('إدارة التسويق والIT', null, null)->departmentId));
        $this->assertSame('MKT-DIG', $this->code($this->resolver->resolve('إدارة التسويق والIT', 'قسم التسويق الرقمي', null)->departmentId));
        $this->assertSame('GM', $this->code($this->resolver->resolve('المدير العام', null, null)->departmentId));
        $this->assertSame('SEC-PROT-CAM', $this->code($this->resolver->resolve('إدارة الأمن والحماية', 'قسم الحماية', 'الكميرات')->departmentId));
        $this->assertSame('HEQ-MNT', $this->code($this->resolver->resolve('إدارة الآليات الثقيلة', 'قسم الصيانة ( رحبة الصيانة )', 'قسم الصيانة')->departmentId));
    }

    public function test_a_blank_division_cannot_be_placed(): void
    {
        $r = $this->resolver->resolve(null, null, null);

        $this->assertNull($r->departmentId);
        $this->assertSame('error', $r->issues[0]['severity']);
    }
}
