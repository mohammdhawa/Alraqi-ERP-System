<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Modules\Scheduling\Services\ScheduleAssigner;
use Database\Seeders\SchedulingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Work shifts plan Task 7: GET /api/scheduling/calendar (spec §9.2). */
class ScheduleCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-14 06:00:00');
        $this->seed(SchedulingSeeder::class);
        config(['attendance.default_schedule' => 'OFFICE']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_everyone_s_expected_shift_per_date_and_its_source(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.view']);
        $guard = Employee::factory()->create();
        app(ScheduleAssigner::class)->assign($guard, WorkSchedule::where('code', 'GUARD')->sole(), '2026-11-14', '2026-11-14', User::factory()->create());
        $office = Employee::factory()->create();

        $response = $this->getJson("/api/scheduling/calendar?from=2026-11-14&to=2026-11-20&employee_id={$guard->id}")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee.id', $guard->id)
            ->assertJsonCount(7, 'data.0.days')
            ->assertJsonPath('data.0.days.0', ['date' => '2026-11-14', 'shift' => [
                'code' => 'GUARD', 'name' => 'مناوبة حراسة', 'starts_at' => '2026-11-14T08:00:00+03:00', 'ends_at' => '2026-11-15T08:00:00+03:00',
            ], 'source' => 'assignment'])
            ->assertJsonPath('data.0.days.3', ['date' => '2026-11-17', 'shift' => null, 'source' => 'assignment']);

        $this->getJson("/api/scheduling/calendar?from=2026-11-20&to=2026-11-21&employee_id={$office->id}")->assertOk()
            ->assertJsonPath('data.0.days.0', ['date' => '2026-11-20', 'shift' => null, 'source' => 'default'])
            ->assertJsonPath('data.0.days.1.shift.code', 'DAY');

        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_at_most_92_days(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.view']);

        $this->getJson('/api/scheduling/calendar?from=2026-11-01&to=2027-02-01')
            ->assertUnprocessable()->assertJsonValidationErrors(['to']);
    }

    public function test_it_needs_view(): void
    {
        $this->actingAsRolelessUser();

        $this->getJson('/api/scheduling/calendar?from=2026-11-14&to=2026-11-20')->assertForbidden();
    }
}
