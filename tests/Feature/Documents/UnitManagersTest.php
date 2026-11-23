<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Services\UnitManagers;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who manages which unit, who is the general manager, and which unit keeps
 * the register a unit's documents are numbered in (documents design §4, §8.4).
 */
class UnitManagersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{root: Department, division: Department, section: Department, office: Department}
     */
    private function chart(): array
    {
        $root = Department::create(['name' => 'الإدارة العامة', 'level' => 1]);
        $division = Department::create(['name' => 'إدارة المالية', 'level' => 2, 'parent_id' => $root->id, 'sector' => 1]);
        $section = Department::create(['name' => 'قسم الحسابات', 'level' => 3, 'parent_id' => $division->id]);
        $office = Department::create(['name' => 'مكتب الصندوق', 'level' => 4, 'parent_id' => $section->id]);

        foreach ([$root, $division, $section, $office] as $unit) {
            $unit->update(['manager_id' => Employee::factory()->create()->id]);
        }

        return compact('root', 'division', 'section', 'office');
    }

    public function test_a_manager_s_unit_is_found(): void
    {
        $chart = $this->chart();
        $service = new UnitManagers;

        $this->assertTrue($service->unitOf($chart['section']->manager)->is($chart['section']));
        $this->assertNull($service->unitOf(Employee::factory()->create()));
    }

    public function test_an_inactive_unit_is_not_managed(): void
    {
        $chart = $this->chart();
        $service = new UnitManagers;

        $chart['section']->update(['is_active' => false]);

        $this->assertNull($service->unitOf($chart['section']->manager));
        $this->assertTrue($service->unitOf($chart['office']->manager)->is($chart['office']));
    }

    public function test_a_deleted_employee_manages_no_unit(): void
    {
        $chart = $this->chart();
        $service = new UnitManagers;
        $manager = $chart['section']->manager;

        $this->assertTrue($service->unitOf($manager)->is($chart['section']));

        // HR's delete is soft and leaves departments.manager_id as it was.
        $manager->delete();

        $this->assertSame($manager->id, $chart['section']->fresh()->manager_id);
        $this->assertNull($service->unitOf($manager));
    }

    public function test_the_root_counts_for_its_manager_whatever_its_flag(): void
    {
        $chart = $this->chart();
        $service = new UnitManagers;

        $chart['root']->update(['is_active' => false]);
        $chart['section']->update(['is_active' => false]);

        $this->assertTrue($service->unitOf($chart['root']->manager)->is($chart['root']));
        $this->assertNull($service->unitOf($chart['section']->manager));
    }

    public function test_the_general_manager_is_the_root_s_manager(): void
    {
        $chart = $this->chart();
        $service = new UnitManagers;

        $this->assertTrue($service->generalManager()->is($chart['root']->manager));

        $chart['root']->update(['manager_id' => null]);

        $this->assertNull($service->generalManager());
    }

    public function test_the_register_is_the_unit_s_idara(): void
    {
        $chart = $this->chart();
        $service = new UnitManagers;

        $this->assertTrue($service->registerOf($chart['office'])->is($chart['division']));
        $this->assertTrue($service->registerOf($chart['section'])->is($chart['division']));
        $this->assertTrue($service->registerOf($chart['division'])->is($chart['division']));
        $this->assertTrue($service->registerOf($chart['root'])->is($chart['root']));
    }
}
