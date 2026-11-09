<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Enums\WorkScheduleKind;
use App\Modules\Scheduling\Exceptions\SchedulingException;
use App\Modules\Scheduling\Models\EmployeeWorkSchedule;
use App\Modules\Scheduling\Models\Shift;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Modules\Scheduling\Models\WorkScheduleDay;
use Database\Seeders\SchedulingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Work shifts plan Task 3: the tables, the rules every writer is bound by
 * (seeders and tinker included), and the seeded OFFICE and GUARD schedules.
 * 2026-11-14 is a Saturday; 2026-11-20 a Friday.
 */
class ScheduleModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-14 10:00:00');
        $this->seed(SchedulingSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function guardShift(): Shift
    {
        return Shift::where('code', 'GUARD')->sole();
    }

    /** @param  array<int, ?int>  $days */
    private function cycle(array $days, string $code = 'T'): WorkSchedule
    {
        return WorkSchedule::createWithDays(
            ['code' => $code, 'name' => 'تجربة', 'kind' => WorkScheduleKind::Cycle, 'cycle_days' => count($days)],
            $days,
        );
    }

    private function assign(Employee $employee, string $code, string $from, ?string $cycleStart = null): EmployeeWorkSchedule
    {
        return EmployeeWorkSchedule::create([
            'employee_id'      => $employee->id,
            'work_schedule_id' => WorkSchedule::where('code', $code)->value('id'),
            'from_date'        => $from,
            'cycle_start_date' => $cycleStart,
            'created_by'       => User::factory()->create()->id,
        ]);
    }

    public function test_the_seeder_creates_office_and_guard_and_is_idempotent(): void
    {
        $this->seed(SchedulingSeeder::class);

        $this->assertSame(2, Shift::count());
        $this->assertSame(2, WorkSchedule::count());
        $this->assertSame(7, WorkSchedule::where('code', 'OFFICE')->sole()->days()->count());
        $this->assertSame(6, WorkSchedule::where('code', 'GUARD')->sole()->days()->count());

        $day = Shift::where('code', 'DAY')->sole();
        $this->assertSame(['09:00', 420], [$day->starts_at, $day->duration_minutes]);
        $this->assertSame(['08:00', 1440], [$this->guardShift()->starts_at, $this->guardShift()->duration_minutes]);
    }

    public function test_office_has_friday_off(): void
    {
        $days = WorkSchedule::where('code', 'OFFICE')->sole()->days()->with('shift')->get()->mapWithKeys(
            fn (WorkScheduleDay $d): array => [$d->position => $d->shift?->code],
        )->all();

        $this->assertSame([0 => 'DAY', 1 => 'DAY', 2 => 'DAY', 3 => 'DAY', 4 => 'DAY', 5 => null, 6 => 'DAY'], $days);
    }

    public function test_guard_is_three_on_three_off(): void
    {
        $days = WorkSchedule::where('code', 'GUARD')->sole()->days()->with('shift')->get()
            ->map(fn (WorkScheduleDay $d): ?string => $d->shift?->code)->all();

        $this->assertSame(['GUARD', 'GUARD', 'GUARD', null, null, null], $days);
    }

    public function test_a_schedule_with_four_back_to_back_24h_shifts_is_refused(): void
    {
        $g = $this->guardShift()->id;

        $this->expectException(SchedulingException::class);
        $this->expectExceptionMessage('لا يمكن أن تتصل أكثر من ثلاث ورديات متتالية بلا فاصل.');

        $this->cycle([$g, $g, $g, $g, null, null]);
    }

    public function test_the_wrap_around_counts(): void
    {
        $g = $this->guardShift()->id;

        $this->expectException(SchedulingException::class);

        $this->cycle([$g, $g, null, null, $g, $g]);
    }

    public function test_three_back_to_back_shifts_are_allowed(): void
    {
        $g = $this->guardShift()->id;

        $this->assertSame(6, $this->cycle([$g, $g, $g, null, null, null])->days()->count());
    }

    public function test_incomplete_positions_are_refused(): void
    {
        $this->expectException(SchedulingException::class);
        $this->expectExceptionMessage('يجب تحديد كل أيام الجدول.');

        WorkSchedule::createWithDays(
            ['code' => 'W', 'name' => 'ناقص', 'kind' => WorkScheduleKind::Weekly, 'cycle_days' => 7],
            [0 => null, 1 => null, 2 => null, 3 => null, 4 => null, 5 => null],
        );
    }

    public function test_a_used_shifts_start_cannot_change_but_its_name_can(): void
    {
        $day = Shift::where('code', 'DAY')->sole();

        $day->update(['name' => 'دوام الإدارة']);
        $this->assertSame('دوام الإدارة', $day->fresh()->name);

        $this->expectException(SchedulingException::class);
        $day->update(['starts_at' => '08:00']);
    }

    public function test_a_used_shift_cannot_be_deleted(): void
    {
        $this->expectException(SchedulingException::class);

        Shift::where('code', 'DAY')->sole()->delete();
    }

    public function test_an_assigned_schedules_days_cannot_change(): void
    {
        $this->assign(Employee::factory()->create(), 'GUARD', '2026-11-14', '2026-11-14');

        $this->expectException(SchedulingException::class);
        $this->expectExceptionMessage('لا يمكن تعديل أيام جدول مُسند لموظفين؛ أنشئ جدولاً جديداً.');

        WorkSchedule::where('code', 'GUARD')->sole()->replaceDays(array_fill(0, 6, null));
    }

    public function test_overlapping_assignments_are_refused(): void
    {
        $employee = Employee::factory()->create();
        $first = $this->assign($employee, 'OFFICE', '2026-11-14');
        $first->update(['to_date' => '2026-11-30']);

        $this->expectException(SchedulingException::class);
        $this->expectExceptionMessage('تتداخل هذه الفترة مع جدول آخر مُسند للموظف.');

        $this->assign($employee, 'OFFICE', '2026-11-20');
    }

    public function test_a_past_from_date_is_refused(): void
    {
        $this->expectException(SchedulingException::class);
        $this->expectExceptionMessage('لا يمكن أن يبدأ الجدول قبل اليوم.');

        $this->assign(Employee::factory()->create(), 'OFFICE', '2026-11-13');
    }

    public function test_a_cycle_assignment_needs_its_start_date(): void
    {
        $this->expectException(SchedulingException::class);
        $this->expectExceptionMessage('يجب تحديد تاريخ بداية الدورة لهذا الجدول.');

        $this->assign(Employee::factory()->create(), 'GUARD', '2026-11-14');
    }

    public function test_a_weekly_assignment_takes_no_cycle_start(): void
    {
        $this->expectException(SchedulingException::class);

        $this->assign(Employee::factory()->create(), 'OFFICE', '2026-11-14', '2026-11-14');
    }

    public function test_in_force_on_finds_the_span_holding_a_date(): void
    {
        $employee = Employee::factory()->create();
        $span = $this->assign($employee, 'OFFICE', '2026-11-14');
        $span->update(['to_date' => '2026-11-19']);

        $this->assertSame([$span->id], EmployeeWorkSchedule::inForceOn('2026-11-19')->pluck('id')->all());
        $this->assertSame([], EmployeeWorkSchedule::inForceOn('2026-11-20')->pluck('id')->all());
    }
}
