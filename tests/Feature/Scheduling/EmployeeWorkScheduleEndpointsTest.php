<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Models\EmployeeWorkSchedule;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Modules\Scheduling\Services\ScheduleAssigner;
use Database\Seeders\SchedulingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Work shifts plan Task 5: /api/hr/employees/{employee}/work-schedules (spec §9.2). */
class EmployeeWorkScheduleEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-14 10:00:00');
        $this->seed(SchedulingSeeder::class);
        $this->employee = Employee::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function url(string $suffix = ''): string
    {
        return "/api/hr/employees/{$this->employee->id}/work-schedules{$suffix}";
    }

    private function scheduleId(string $code): int
    {
        return (int) WorkSchedule::where('code', $code)->value('id');
    }

    public function test_a_schedule_is_assigned_and_read_back(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage', 'scheduling.view']);

        $this->postJson($this->url(), ['work_schedule_id' => $this->scheduleId('GUARD'), 'from_date' => '2026-11-14', 'cycle_start_date' => '2026-11-14'])
            ->assertCreated()
            ->assertJsonPath('data.work_schedule.code', 'GUARD')
            ->assertJsonPath('data.from_date', '2026-11-14')
            ->assertJsonPath('data.to_date', null)
            ->assertJsonPath('data.cycle_start_date', '2026-11-14');

        $this->getJson($this->url())->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_new_assignment_closes_the_open_one_the_day_before(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);
        $this->postJson($this->url(), ['work_schedule_id' => $this->scheduleId('OFFICE'), 'from_date' => '2026-11-14'])->assertCreated();

        $this->postJson($this->url(), ['work_schedule_id' => $this->scheduleId('GUARD'), 'from_date' => '2026-11-20', 'cycle_start_date' => '2026-11-20'])
            ->assertCreated();

        $this->assertSame(['2026-11-19', null], EmployeeWorkSchedule::orderBy('from_date')->get()
            ->map(fn (EmployeeWorkSchedule $s): ?string => $s->to_date?->toDateString())->all());
    }

    public function test_a_past_start_is_refused(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);

        $this->postJson($this->url(), ['work_schedule_id' => $this->scheduleId('OFFICE'), 'from_date' => '2026-11-13'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'لا يمكن أن يبدأ الجدول قبل اليوم.');
    }

    public function test_patch_only_ends_an_assignment(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);
        $span = app(ScheduleAssigner::class)->assign($this->employee, WorkSchedule::where('code', 'OFFICE')->sole(), '2026-11-14', null, User::factory()->create());

        $this->patchJson($this->url("/{$span->id}"), ['to_date' => '2026-11-30', 'from_date' => '2026-12-01'])->assertOk()
            ->assertJsonPath('data.to_date', '2026-11-30')
            ->assertJsonPath('data.from_date', '2026-11-14');

        $this->patchJson($this->url("/{$span->id}"), ['to_date' => '2026-11-13'])
            ->assertUnprocessable()->assertJsonValidationErrors(['to_date']);
    }

    /** Final review I4: an ended span is history; stretching it re-reads past days. */
    public function test_an_ended_span_cannot_be_stretched_back_over_past_days(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);
        $span = app(ScheduleAssigner::class)->assign($this->employee, WorkSchedule::where('code', 'GUARD')->sole(), '2026-11-14', '2026-11-14', User::factory()->create());
        $this->patchJson($this->url("/{$span->id}"), ['to_date' => '2026-11-15'])->assertOk();

        Carbon::setTestNow('2026-11-25 10:00:00');

        $this->patchJson($this->url("/{$span->id}"), ['to_date' => '2026-11-30'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'لا يمكن تعديل جدول انتهى؛ أسند جدولاً جديداً.');
        $this->assertSame('2026-11-15', $span->fresh()->to_date->toDateString());
    }

    public function test_only_an_assignment_not_yet_started_can_be_deleted(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);
        $assigner = app(ScheduleAssigner::class);
        $by = User::factory()->create();
        $started = $assigner->assign($this->employee, WorkSchedule::where('code', 'OFFICE')->sole(), '2026-11-14', null, $by);

        $this->deleteJson($this->url("/{$started->id}"))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'لا يمكن حذف جدول بدأ العمل به؛ يمكن إنهاؤه فقط.');

        $future = $assigner->assign($this->employee, WorkSchedule::where('code', 'GUARD')->sole(), '2026-11-20', '2026-11-20', $by);
        $this->deleteJson($this->url("/{$future->id}"))->assertOk();
        $this->assertModelMissing($future);
    }

    public function test_deleting_a_future_assignment_reopens_the_one_it_closed(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);
        $assigner = app(ScheduleAssigner::class);
        $by = User::factory()->create();
        $office = $assigner->assign($this->employee, WorkSchedule::where('code', 'OFFICE')->sole(), '2026-11-14', null, $by);
        $future = $assigner->assign($this->employee, WorkSchedule::where('code', 'GUARD')->sole(), '2026-11-20', '2026-11-20', $by);

        $this->deleteJson($this->url("/{$future->id}"))->assertOk();

        $this->assertNull($office->fresh()->to_date);
    }

    public function test_the_routes_need_the_scheduling_permissions(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.view']);

        $this->getJson($this->url())->assertOk();
        $this->postJson($this->url(), ['work_schedule_id' => $this->scheduleId('OFFICE'), 'from_date' => '2026-11-14'])->assertForbidden();
    }

    public function test_another_employees_assignment_is_a_404(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);
        $other = app(ScheduleAssigner::class)->assign(Employee::factory()->create(), WorkSchedule::where('code', 'OFFICE')->sole(), '2026-11-14', null, User::factory()->create());

        $this->patchJson($this->url("/{$other->id}"), ['to_date' => '2026-11-30'])->assertNotFound();
    }
}
