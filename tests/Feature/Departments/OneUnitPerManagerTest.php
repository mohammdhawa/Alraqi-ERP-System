<?php

declare(strict_types=1);

namespace Tests\Feature\Departments;

use App\Modules\Departments\Exceptions\ManagerOfAnotherUnitException;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * One person manages one unit at most (documents round D27.1; design §13):
 * a manager's stamp is always their one unit's (D21.1). Checked when a
 * manager is named or a unit restored, on every write path, as the hierarchy
 * guard is. Decision 15 and ManagementChain are not touched (D28).
 */
class OneUnitPerManagerTest extends TestCase
{
    use RefreshDatabase;

    private Department $root;

    private Department $a;

    private Department $b;

    private Employee $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();

        $this->root = Department::create(['name' => 'الإدارة العامة', 'level' => 1]);
        $this->a = Department::create(['name' => 'إدارة المالية', 'level' => 2, 'parent_id' => $this->root->id, 'sector' => 1]);
        $this->b = Department::create(['name' => 'إدارة المشتريات', 'level' => 2, 'parent_id' => $this->root->id, 'sector' => 1]);
        $this->manager = Employee::factory()->create();
    }

    private function message(string $unit): string
    {
        return "هذا الموظف مدير لوحدة «{$unit}» بالفعل، ولا يدير الموظف أكثر من وحدة واحدة.";
    }

    public function test_naming_the_manager_of_another_unit_is_refused(): void
    {
        $this->a->update(['manager_id' => $this->manager->id]);

        $this->patchJson("/api/departments/{$this->b->id}", ['manager_id' => $this->manager->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', $this->message('إدارة المالية'));

        $this->assertNull($this->b->fresh()->manager_id);
    }

    public function test_creating_a_unit_with_such_a_manager_is_refused(): void
    {
        $this->a->update(['manager_id' => $this->manager->id]);

        $this->postJson('/api/departments', [
            'name'       => 'قسم جديد',
            'level'      => 3,
            'parent_id'  => $this->b->id,
            'manager_id' => $this->manager->id,
        ])->assertUnprocessable()->assertJsonPath('message', $this->message('إدارة المالية'));

        $this->assertSame(0, Department::where('name', 'قسم جديد')->count());
    }

    public function test_an_inactive_unit_still_counts(): void
    {
        $this->a->update(['manager_id' => $this->manager->id, 'is_active' => false]);

        $this->patchJson("/api/departments/{$this->b->id}", ['manager_id' => $this->manager->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', $this->message('إدارة المالية'));
    }

    public function test_moving_a_manager_works_once_the_old_post_is_cleared(): void
    {
        $this->a->update(['manager_id' => $this->manager->id]);

        $this->patchJson("/api/departments/{$this->a->id}", ['manager_id' => null])->assertOk();
        $this->patchJson("/api/departments/{$this->b->id}", ['manager_id' => $this->manager->id])->assertOk();

        $this->assertSame($this->manager->id, $this->b->fresh()->manager_id);
    }

    public function test_the_same_manager_on_the_same_unit_is_fine(): void
    {
        $this->a->update(['manager_id' => $this->manager->id]);

        $this->patchJson("/api/departments/{$this->a->id}", ['manager_id' => $this->manager->id, 'name' => 'الإدارة المالية'])
            ->assertOk();
    }

    public function test_an_existing_violation_does_not_block_unrelated_edits(): void
    {
        // Data from before the guard: written past it, straight to the table.
        DB::table('departments')->whereIn('id', [$this->a->id, $this->b->id])->update(['manager_id' => $this->manager->id]);

        $this->patchJson("/api/departments/{$this->b->id}", ['name' => 'إدارة المشتريات والتوريد'])->assertOk();
        $this->patchJson("/api/departments/{$this->b->id}", ['manager_id' => $this->manager->id])->assertOk();

        $this->assertSame('إدارة المشتريات والتوريد', $this->b->fresh()->name);
    }

    public function test_a_deleted_unit_does_not_count(): void
    {
        $office = Department::create(['name' => 'مكتب', 'level' => 3, 'parent_id' => $this->a->id, 'manager_id' => $this->manager->id]);
        $office->delete();

        $this->patchJson("/api/departments/{$this->b->id}", ['manager_id' => $this->manager->id])->assertOk();
    }

    public function test_restoring_a_unit_whose_manager_now_manages_another_is_refused(): void
    {
        $section = Department::create(['name' => 'قسم الرواتب', 'level' => 3, 'parent_id' => $this->a->id, 'manager_id' => $this->manager->id]);
        $section->delete();
        $this->b->update(['manager_id' => $this->manager->id]);

        try {
            $section->restore();
            $this->fail('the restore must be refused');
        } catch (ManagerOfAnotherUnitException $e) {
            $this->assertSame($this->message('إدارة المشتريات'), $e->getMessage());
            $this->assertSame(422, $e->statusCode());
        }

        $this->assertTrue(Department::withTrashed()->findOrFail($section->id)->trashed());
    }

    public function test_the_command_lists_people_managing_several_units(): void
    {
        $this->artisan('departments:managers-of-several')
            ->expectsOutputToContain('لا يوجد موظف يدير أكثر من وحدة.')
            ->assertExitCode(0);

        DB::table('departments')->whereIn('id', [$this->a->id, $this->b->id])->update(['manager_id' => $this->manager->id]);

        // Read whole: the name and both units share one table row, and one
        // written line cannot satisfy several output expectations.
        $exit = Artisan::call('departments:managers-of-several');
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString($this->manager->full_name, $output);
        $this->assertStringContainsString('إدارة المالية', $output);
        $this->assertStringContainsString('إدارة المشتريات', $output);
    }
}
