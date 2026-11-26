<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Auth\Models\User;
use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Models\DocumentCounter;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/documents/numbers/next: the number a manager's register will give
 * next, or that it is cold.
 */
class NextExportNumberTest extends TestCase
{
    use RefreshDatabase;

    private Department $division;

    private Department $section;

    private User $divisionManager;

    private User $sectionManager;

    protected function setUp(): void
    {
        parent::setUp();

        $root = Department::create(['name' => 'الإدارة العامة', 'level' => 1]);
        $this->division = Department::create(['name' => 'إدارة المالية', 'level' => 2, 'parent_id' => $root->id, 'sector' => 1]);
        $this->section = Department::create(['name' => 'قسم الحسابات', 'level' => 3, 'parent_id' => $this->division->id]);

        $this->divisionManager = $this->manage($this->division);
        $this->sectionManager = $this->manage($this->section);
    }

    private function manage(Department $unit): User
    {
        $employee = Employee::factory()->create();
        $unit->update(['manager_id' => $employee->id]);

        return User::factory()->create(['employee_id' => $employee->id, 'must_change_password' => false]);
    }

    public function test_a_cold_register_has_no_next_and_needs_a_first_number(): void
    {
        Sanctum::actingAs($this->divisionManager);

        $this->getJson('/api/documents/numbers/next')
            ->assertOk()
            ->assertJsonPath('data.register.id', $this->division->id)
            ->assertJsonPath('data.register.name', 'إدارة المالية')
            ->assertJsonPath('data.next', null)
            ->assertJsonPath('data.first_required', true);
    }

    public function test_a_seeded_register_gives_the_next_number(): void
    {
        DocumentCounter::create(['unit_id' => $this->division->id, 'current_value' => 186]);
        Sanctum::actingAs($this->divisionManager);

        $this->getJson('/api/documents/numbers/next')
            ->assertOk()
            ->assertJsonPath('data.next', 187)
            ->assertJsonPath('data.first_required', false);
    }

    public function test_a_section_manager_sees_the_register_of_the_division_above(): void
    {
        DocumentCounter::create(['unit_id' => $this->division->id, 'current_value' => 186]);
        Sanctum::actingAs($this->sectionManager);

        $this->getJson('/api/documents/numbers/next')
            ->assertOk()
            ->assertJsonPath('data.register.id', $this->division->id)
            ->assertJsonPath('data.next', 187);
    }

    public function test_a_non_manager_is_refused(): void
    {
        $employee = Employee::factory()->create();
        Sanctum::actingAs(User::factory()->create(['employee_id' => $employee->id, 'must_change_password' => false]));

        $this->getJson('/api/documents/numbers/next')
            ->assertForbidden()
            ->assertJsonPath('message', 'لا يُنشئ المستندات إلا مدير وحدة تنظيمية.');
    }
}
