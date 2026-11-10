<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Enums\ShiftChangeOrigin;
use App\Modules\Scheduling\Models\Shift;
use App\Modules\Scheduling\Models\ShiftChange;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Modules\Scheduling\Services\ScheduleAssigner;
use App\Modules\Scheduling\Services\ScheduleResolver;
use Database\Seeders\SchedulingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Work shifts piece 2, plan Task 2: a change comes before the schedule (spec
 * §4.1), and no run is longer than six back-to-back shifts (§4.2).
 * 2026-11-14 is a Saturday; 2026-11-20 a Friday.
 */
class ShiftChangeResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-13 10:00:00');
        $this->seed(SchedulingSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function resolver(): ScheduleResolver
    {
        return app(ScheduleResolver::class);
    }

    private function assign(Employee $employee, string $code, string $from, ?string $cycleStart = null): void
    {
        app(ScheduleAssigner::class)->assign($employee, WorkSchedule::where('code', $code)->sole(), $from, $cycleStart, User::factory()->create());
    }

    private function night(): Shift
    {
        return Shift::firstOrCreate(['code' => 'NIGHT'], ['name' => 'الوردية الليلية', 'starts_at' => '22:00', 'duration_minutes' => 480, 'is_active' => true]);
    }

    private function shiftId(string $code): int
    {
        return (int) Shift::where('code', $code)->value('id');
    }

    private function change(Employee $employee, string $date, ?int $shiftId, ShiftChangeOrigin $origin = ShiftChangeOrigin::Hr): ShiftChange
    {
        return ShiftChange::create([
            'employee_id'   => $employee->id,
            'date'          => $date,
            'shift_id'      => $shiftId,
            'origin'        => $origin,
            'shift_swap_id' => $origin === ShiftChangeOrigin::Swap ? 1 : null,
            'reason'        => $origin === ShiftChangeOrigin::Hr ? 'تكليف' : null,
        ]);
    }

    private function guardFrom1114(): Employee
    {
        $guard = Employee::factory()->create();
        $this->assign($guard, 'GUARD', '2026-11-14', '2026-11-14');

        return $guard;
    }

    public function test_a_change_wins_over_an_assignment(): void
    {
        $employee = Employee::factory()->create();
        $this->assign($employee, 'OFFICE', '2026-11-14');
        $this->change($employee, '2026-11-15', $this->night()->id);

        $shift = $this->resolver()->shiftsFor([$employee->id], '2026-11-15', '2026-11-15')[$employee->id]['2026-11-15'];

        $this->assertSame('2026-11-15 22:00', $shift->startsAt->format('Y-m-d H:i'));
        $this->assertSame('2026-11-16 06:00', $shift->endsAt->format('Y-m-d H:i'));
        $this->assertSame('change', $shift->source);
    }

    public function test_a_change_wins_over_the_default(): void
    {
        $employee = Employee::factory()->create();
        $this->change($employee, '2026-11-20', $this->shiftId('DAY'));

        $this->assertArrayHasKey('2026-11-20', $this->resolver()->shiftsFor([$employee->id], '2026-11-20', '2026-11-20')[$employee->id]);
    }

    public function test_a_null_change_is_a_day_off(): void
    {
        $employee = Employee::factory()->create();
        $this->assign($employee, 'OFFICE', '2026-11-14');
        $this->change($employee, '2026-11-16', null);

        $dates = array_keys($this->resolver()->shiftsFor([$employee->id], '2026-11-15', '2026-11-17')[$employee->id]);

        $this->assertSame(['2026-11-15', '2026-11-17'], $dates);
    }

    public function test_the_calendar_shows_change_and_swap(): void
    {
        $employee = Employee::factory()->create();
        $this->change($employee, '2026-11-15', $this->night()->id);
        $this->change($employee, '2026-11-16', null, ShiftChangeOrigin::Swap);

        $days = $this->resolver()->calendarFor([$employee->id], '2026-11-14', '2026-11-16')[$employee->id];

        $this->assertSame(['default', 'change', 'swap'], array_column($days, 'source'));
        $this->assertNull($days[2]['shift']);
    }

    public function test_a_run_of_six_is_two_duties(): void
    {
        $guard = $this->guardFrom1114();
        foreach (['2026-11-17', '2026-11-18', '2026-11-19'] as $date) {
            $this->change($guard, $date, $this->shiftId('GUARD'));
        }
        $this->change($guard, '2026-11-20', null);

        $first = $this->resolver()->dutyOn($guard, '2026-11-14');
        $second = $this->resolver()->dutyOn($guard, '2026-11-17');

        $this->assertCount(3, $first->shifts);
        $this->assertCount(3, $second->shifts);
        $this->assertSame('2026-11-17', $second->shiftDate());

        $holding = $this->resolver()->dutiesHolding($guard, Carbon::parse('2026-11-18 10:00:00', (string) config('attendance.timezone')));
        $this->assertSame('2026-11-17', $holding[0]->shiftDate());
    }

    public function test_a_seventh_back_to_back_shift_is_a_violation(): void
    {
        $guard = $this->guardFrom1114();
        $guardShift = $this->shiftId('GUARD');

        $this->assertSame(
            'يتجاوز هذا التعديل ست ورديات متتالية للموظف.',
            $this->resolver()->runViolation($guard, ['2026-11-17' => $guardShift, '2026-11-18' => $guardShift, '2026-11-19' => $guardShift]),
        );
        $this->assertNull(
            $this->resolver()->runViolation($guard, ['2026-11-17' => $guardShift, '2026-11-18' => $guardShift, '2026-11-19' => $guardShift, '2026-11-20' => null]),
        );
    }

    public function test_changes_add_one_query_to_a_batch_read(): void
    {
        $people = Employee::factory()->count(20)->create();
        foreach ($people as $person) {
            $this->change($person, '2026-11-15', null);
        }
        $resolver = $this->resolver();

        DB::enableQueryLog();
        $resolver->shiftsFor($people->modelKeys(), '2026-11-01', '2026-11-30');

        $this->assertLessThanOrEqual(5, count(DB::getQueryLog()));
    }

    public function test_shift_id_is_set_on_every_expected_shift(): void
    {
        $employee = Employee::factory()->create();
        $this->assign($employee, 'OFFICE', '2026-11-14');
        $this->change($employee, '2026-11-15', $this->night()->id);

        $shifts = $this->resolver()->shiftsFor([$employee->id], '2026-11-14', '2026-11-15')[$employee->id];

        $this->assertSame($this->shiftId('DAY'), $shifts['2026-11-14']->shiftId);
        $this->assertSame($this->night()->id, $shifts['2026-11-15']->shiftId);
    }
}
