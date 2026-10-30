<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Services\WorkSiteAssignmentService;
use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WorkSiteManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_creates_a_site_and_assigns_one_employee(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create();

        $site = $this->postJson('/api/attendance/work-sites', [
            'name' => 'Main plant',
            'latitude' => 33.5000000,
            'longitude' => 36.3000000,
            'radius_m' => 120,
        ])->assertCreated()->json('data');

        $this->postJson('/api/attendance/work-site-assignments', [
            'employee_id' => $employee->id,
            'work_site_id' => $site['id'],
        ])->assertCreated();

        $resolved = app(WorkSiteAssignmentService::class)->siteFor($employee, Carbon::now());
        $this->assertSame('Main plant', $resolved->site->name);
        $this->assertSame(120.0, (float) $resolved->version->radius_m);
    }

    public function test_site_input_and_management_permission_are_checked(): void
    {
        $this->actingAsRolelessUser();
        $this->postJson('/api/attendance/work-sites', ['name' => 'X'])->assertForbidden();

        $this->actingAsAdmin();
        $this->postJson('/api/attendance/work-sites', [
            'name' => 'Bad', 'latitude' => 91, 'longitude' => 181, 'radius_m' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude', 'radius_m']);
    }

    public function test_site_versions_and_assignments_are_selected_at_event_time(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create();
        $first = $this->postJson('/api/attendance/work-sites', [
            'name' => 'First', 'latitude' => 33.5, 'longitude' => 36.3, 'radius_m' => 100,
        ])->assertCreated()->json('data.id');
        $second = $this->postJson('/api/attendance/work-sites', [
            'name' => 'Second', 'latitude' => 34.0, 'longitude' => 37.0, 'radius_m' => 80,
        ])->assertCreated()->json('data.id');
        $assignment = $this->postJson('/api/attendance/work-site-assignments', [
            'employee_id' => $employee->id, 'work_site_id' => $first,
        ])->assertCreated()->json('data.id');

        Carbon::setTestNow('2026-10-30 13:00:00');
        $this->patchJson("/api/attendance/work-sites/{$first}", [
            'name' => 'First revised', 'latitude' => 33.6,
            'effective_from' => '2026-10-30T13:00:00+03:00',
        ])->assertOk()->assertJsonCount(2, 'data.versions');

        $service = app(WorkSiteAssignmentService::class);
        $old = $service->siteFor($employee, Carbon::parse('2026-10-30 12:30:00'));
        $this->assertSame('First', $old->version->name);
        $this->assertSame(33.5, $old->version->latitude);
        $new = $service->siteFor($employee, Carbon::parse('2026-10-30 13:00:00'));
        $this->assertSame('First revised', $new->version->name);
        $this->assertSame(33.6, $new->version->latitude);

        Carbon::setTestNow('2026-10-30 14:00:00');
        $this->patchJson("/api/attendance/work-site-assignments/{$assignment}", [
            'effective_to' => '2026-10-30T14:00:00+03:00',
        ])->assertOk();
        $this->postJson('/api/attendance/work-site-assignments', [
            'employee_id' => $employee->id, 'work_site_id' => $second,
        ])->assertCreated();
        $this->assertSame($first, $service->siteFor($employee, Carbon::parse('2026-10-30 13:59:59'))->site->id);
        $this->assertSame($second, $service->siteFor($employee, Carbon::parse('2026-10-30 14:00:00'))->site->id);
    }

    public function test_overlap_backdating_and_archiving_live_assignments_are_refused(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create();
        $site = $this->postJson('/api/attendance/work-sites', [
            'name' => 'Plant', 'latitude' => 33.5, 'longitude' => 36.3, 'radius_m' => 100,
        ])->assertCreated()->json('data.id');
        $assignment = $this->postJson('/api/attendance/work-site-assignments', [
            'employee_id' => $employee->id, 'work_site_id' => $site,
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/attendance/work-site-assignments', [
            'employee_id' => $employee->id, 'work_site_id' => $site,
        ])->assertUnprocessable()->assertJsonValidationErrors('effective_from');
        $this->patchJson("/api/attendance/work-sites/{$site}", [
            'radius_m' => 200, 'effective_from' => '2026-10-30T11:00:00+03:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('effective_from');
        $archive = $this->patchJson("/api/attendance/work-sites/{$site}/archive")
            ->assertUnprocessable()->assertJsonValidationErrors('assignments')
            ->json('errors.assignments.0');
        $this->assertStringContainsString($employee->full_name, $archive);

        Carbon::setTestNow('2026-10-30 13:00:00');
        $this->patchJson("/api/attendance/work-site-assignments/{$assignment}", [
            'effective_to' => '2026-10-30T13:00:00+03:00',
        ])->assertOk();
        $this->patchJson("/api/attendance/work-sites/{$site}/archive")
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson('/api/attendance/work-site-assignments', [
            'employee_id' => $employee->id, 'work_site_id' => $site,
        ])->assertUnprocessable()->assertJsonValidationErrors('work_site_id');
    }

    public function test_a_future_assignment_prevents_archiving_until_its_end_has_passed(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create();
        $site = $this->postJson('/api/attendance/work-sites', [
            'name' => 'Future site', 'latitude' => 33.5, 'longitude' => 36.3, 'radius_m' => 100,
        ])->assertCreated()->json('data.id');
        $assignment = $this->postJson('/api/attendance/work-site-assignments', [
            'employee_id' => $employee->id, 'work_site_id' => $site,
            'effective_from' => '2026-11-01T08:00:00+03:00',
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/attendance/work-sites/{$site}/archive")
            ->assertUnprocessable()->assertJsonValidationErrors('assignments');
        $this->patchJson("/api/attendance/work-site-assignments/{$assignment}", [
            'effective_to' => '2026-11-02T08:00:00+03:00',
        ])->assertOk();
        $this->patchJson("/api/attendance/work-sites/{$site}/archive")
            ->assertUnprocessable()->assertJsonValidationErrors('assignments');

        Carbon::setTestNow('2026-11-02 08:00:00');
        $this->patchJson("/api/attendance/work-sites/{$site}/archive")
            ->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_assignment_cannot_be_backdated_and_empty_site_edit_is_rejected(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create();
        $site = $this->postJson('/api/attendance/work-sites', [
            'name' => 'No backdate', 'latitude' => 33.5, 'longitude' => 36.3, 'radius_m' => 100,
        ])->assertCreated()->json('data.id');

        Carbon::setTestNow('2026-10-30 13:00:00');
        $this->postJson('/api/attendance/work-site-assignments', [
            'employee_id' => $employee->id, 'work_site_id' => $site,
            'effective_from' => '2026-10-30T12:30:00+03:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('effective_from');
        $this->patchJson("/api/attendance/work-sites/{$site}", [
            'effective_from' => '2026-10-30T13:00:00+03:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('work_site');
    }

    public function test_management_requires_a_company_wide_grant(): void
    {
        $unit = Department::create([
            'name' => 'Site unit', 'code' => 'SITE', 'level' => DepartmentLevel::cases()[0]->value,
        ]);
        $this->actingAsScopedUser(['attendance.work_sites.view', 'attendance.work_sites.manage'], ScopeType::Unit, $unit->id);
        $this->getJson('/api/attendance/work-sites')->assertForbidden();

        $this->actingAsScopedUser(['attendance.work_sites.view', 'attendance.work_sites.manage'], ScopeType::Company);
        $this->getJson('/api/attendance/work-sites')->assertOk();
    }
}
