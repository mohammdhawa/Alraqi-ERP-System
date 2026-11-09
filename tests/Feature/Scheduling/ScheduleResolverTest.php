<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Attendance\Contracts\ExpectedShifts;
use App\Modules\Attendance\Support\Duty;
use App\Modules\Attendance\Support\ExpectedShift;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Models\Shift;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Modules\Scheduling\Services\ScheduleAssigner;
use Database\Seeders\SchedulingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Work shifts plan Task 6: each person's expected shifts and duties (spec §4).
 * 2026-11-14 is a Saturday; 2026-11-20 a Friday.
 */
class ScheduleResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-14 06:00:00');
        $this->seed(SchedulingSeeder::class);
        config(['attendance.default_schedule' => null]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function shifts(): ExpectedShifts
    {
        return app(ExpectedShifts::class);
    }

    private function assign(Employee $employee, string $code, string $from, ?string $cycleStart = null): void
    {
        app(ScheduleAssigner::class)->assign($employee, WorkSchedule::where('code', $code)->sole(), $from, $cycleStart, User::factory()->create());
    }

    private function at(string $moment): Carbon
    {
        return Carbon::parse($moment, (string) config('attendance.timezone'));
    }

    /** @return list<string> the dates that have a shift */
    private function dates(Employee $employee, string $from, string $to): array
    {
        return array_keys($this->shifts()->shiftsFor([$employee->id], $from, $to)[$employee->id] ?? []);
    }

    public function test_office_gives_saturday_to_thursday_and_friday_off(): void
    {
        $employee = Employee::factory()->create();
        $this->assign($employee, 'OFFICE', '2026-11-14');

        $this->assertSame(['2026-11-14', '2026-11-15', '2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19'], $this->dates($employee, '2026-11-14', '2026-11-20'));

        $saturday = $this->shifts()->shiftsFor([$employee->id], '2026-11-14', '2026-11-14')[$employee->id]['2026-11-14'];
        $this->assertSame(['DAY', '2026-11-14T09:00:00+03:00', '2026-11-14T16:00:00+03:00', 'assignment', false], [
            $saturday->code, $saturday->startsAt->toIso8601String(), $saturday->endsAt->toIso8601String(), $saturday->source, $saturday->isOpen,
        ]);
    }

    public function test_guard_positions_follow_the_cycle_start(): void
    {
        $employee = Employee::factory()->create();
        $this->assign($employee, 'GUARD', '2026-11-14', '2026-11-14');

        $this->assertSame(
            ['2026-11-14', '2026-11-15', '2026-11-16', '2026-11-20', '2026-11-21', '2026-11-22'],
            $this->dates($employee, '2026-11-14', '2026-11-25'),
        );
    }

    public function test_a_date_before_the_cycle_start_wraps_correctly(): void
    {
        $employee = Employee::factory()->create();
        // 11-14 is three days before the cycle start: position −3 ≡ 3, a day off;
        // 11-17 is position 0.
        $this->assign($employee, 'GUARD', '2026-11-14', '2026-11-17');

        $this->assertSame(['2026-11-17', '2026-11-18', '2026-11-19'], $this->dates($employee, '2026-11-14', '2026-11-20'));
    }

    public function test_a_guard_duty_is_three_shifts_from_08_00_to_08_00(): void
    {
        $employee = Employee::factory()->create();
        $this->assign($employee, 'GUARD', '2026-11-14', '2026-11-14');

        $duty = $this->shifts()->dutyOn($employee, '2026-11-14');

        $this->assertInstanceOf(Duty::class, $duty);
        $this->assertCount(3, $duty->shifts);
        $this->assertSame('2026-11-14', $duty->shiftDate());
        $this->assertSame('2026-11-14 08:00', $duty->startsAt()->format('Y-m-d H:i'));
        $this->assertSame('2026-11-17 08:00', $duty->endsAt()->format('Y-m-d H:i'));
        $this->assertSame('2026-11-14 07:00', $duty->opensAt->format('Y-m-d H:i'));
        $this->assertSame('2026-11-17 12:00', $duty->closesAt->format('Y-m-d H:i'));
        $this->assertNull($this->shifts()->dutyOn($employee, '2026-11-15'), 'a duty is found by its start date only');
    }

    public function test_a_night_shift_ends_the_next_day(): void
    {
        $employee = Employee::factory()->create();
        $night = Shift::create(['code' => 'NIGHT', 'name' => 'ليلية', 'starts_at' => '22:00', 'duration_minutes' => 480]);
        WorkSchedule::createWithDays(['code' => 'NIGHTS', 'name' => 'ليلي', 'kind' => 1, 'cycle_days' => 7], array_fill(0, 7, $night->id));
        $this->assign($employee, 'NIGHTS', '2026-11-14');

        $duty = $this->shifts()->dutyOn($employee, '2026-11-14');

        $this->assertCount(1, $duty->shifts, 'night shifts on consecutive days do not touch, so each is its own duty');
        $this->assertSame('2026-11-15 06:00', $duty->endsAt()->format('Y-m-d H:i'));
        $this->assertSame('2026-11-15 10:00', $duty->closesAt->format('Y-m-d H:i'));
    }

    public function test_duties_holding_opens_sixty_minutes_early(): void
    {
        $employee = Employee::factory()->create();
        $this->assign($employee, 'OFFICE', '2026-11-14');

        $this->assertSame('2026-11-14', $this->shifts()->dutiesHolding($employee, $this->at('2026-11-14 08:00:00'))[0]->shiftDate());
        $this->assertSame([], $this->shifts()->dutiesHolding($employee, $this->at('2026-11-14 07:59:00')));
        $this->assertSame([], $this->shifts()->dutiesHolding($employee, $this->at('2026-11-14 16:00:00')));
        $this->assertSame([], $this->shifts()->dutiesHolding($employee, $this->at('2026-11-20 10:00:00')), 'Friday is off');
    }

    public function test_a_guard_mid_duty_is_held_by_the_duty_that_began_two_days_earlier(): void
    {
        $employee = Employee::factory()->create();
        $this->assign($employee, 'GUARD', '2026-11-14', '2026-11-14');

        $this->assertSame('2026-11-14', $this->shifts()->dutiesHolding($employee, $this->at('2026-11-16 23:00:00'))[0]->shiftDate());
        $this->assertSame([], $this->shifts()->dutiesHolding($employee, $this->at('2026-11-17 08:00:00')));
    }

    public function test_the_assignment_in_force_on_each_date_is_used(): void
    {
        $employee = Employee::factory()->create();
        $this->assign($employee, 'OFFICE', '2026-11-14');
        $this->assign($employee, 'GUARD', '2026-11-20', '2026-11-20');

        $this->assertSame(
            ['2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22'],
            $this->dates($employee, '2026-11-18', '2026-11-23'),
        );
    }

    public function test_unassigned_people_get_the_open_day_while_no_default_is_set(): void
    {
        $employee = Employee::factory()->create();

        $days = $this->shifts()->shiftsFor([$employee->id], '2026-11-14', '2026-11-20')[$employee->id];

        $this->assertCount(7, $days);
        $this->assertTrue($days['2026-11-20']->isOpen);
        $this->assertSame('open', $days['2026-11-20']->source);
        $duty = $this->shifts()->dutiesHolding($employee, $this->at('2026-11-20 00:00:00'))[0];
        $this->assertSame(['2026-11-20 00:00:00', '2026-11-20 23:59:59'], [$duty->opensAt->format('Y-m-d H:i:s'), $duty->closesAt->format('Y-m-d H:i:s')]);
    }

    public function test_unassigned_people_get_the_default_when_one_is_set(): void
    {
        config(['attendance.default_schedule' => 'OFFICE']);
        $employee = Employee::factory()->create();

        $days = $this->shifts()->shiftsFor([$employee->id], '2026-11-14', '2026-11-20')[$employee->id];

        $this->assertCount(6, $days);
        $this->assertSame('default', $days['2026-11-14']->source);
    }

    public function test_working_days_between_counts_dates_with_a_shift(): void
    {
        $guard = Employee::factory()->create();
        $this->assign($guard, 'GUARD', '2026-11-14', '2026-11-14');
        $office = Employee::factory()->create();
        $this->assign($office, 'OFFICE', '2026-11-14');

        $this->assertSame(3, $this->shifts()->workingDaysBetween($guard, '2026-11-14', '2026-11-19'));
        $this->assertSame(6, $this->shifts()->workingDaysBetween($office, '2026-11-14', '2026-11-20'));
        $this->assertSame(0, $this->shifts()->workingDaysBetween($office, '2026-11-20', '2026-11-20'));
    }

    public function test_shifts_for_resolves_many_people_in_a_fixed_number_of_queries(): void
    {
        $people = Employee::factory()->count(20)->create();
        foreach ($people as $employee) {
            $this->assign($employee, 'OFFICE', '2026-11-14');
        }

        DB::enableQueryLog();
        $all = $this->shifts()->shiftsFor($people->modelKeys(), '2026-11-14', '2026-12-13');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(20, $all);
        $this->assertLessThanOrEqual(4, $queries);
    }

    public function test_expected_shift_serialises_for_the_api(): void
    {
        $employee = Employee::factory()->create();
        $this->assign($employee, 'OFFICE', '2026-11-14');

        $shift = $this->shifts()->shiftsFor([$employee->id], '2026-11-14', '2026-11-14')[$employee->id]['2026-11-14'];

        $this->assertInstanceOf(ExpectedShift::class, $shift);
        $this->assertSame([
            'code' => 'DAY', 'name' => 'الدوام الإداري',
            'starts_at' => '2026-11-14T09:00:00+03:00', 'ends_at' => '2026-11-14T16:00:00+03:00',
        ], $shift->toArray());
    }
}
