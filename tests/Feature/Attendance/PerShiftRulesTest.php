<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Enums\AttendanceRecordStatus;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Support\Duty;
use App\Modules\Attendance\Support\ExpectedShift;
use App\Modules\Attendance\Support\ShiftRules;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Models\Shift;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Modules\Scheduling\Services\ScheduleAssigner;
use Carbon\CarbonImmutable;
use Database\Seeders\SchedulingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Per-shift rules plan Task 2: every reader judges a day by the rules in force
 * on it (spec §3). 2026-11-14 is a Saturday; the office day is 09:00–16:00.
 */
class PerShiftRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        Storage::fake('local');
        Carbon::setTestNow('2026-11-14 06:00:00');
        $this->seed(SchedulingSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Sets DAY's first (2000-01-01) version. */
    private function dayRules(array $values): void
    {
        Shift::where('code', 'DAY')->sole()->rules()->where('effective_from', ShiftRules::FIRST_DAY)->sole()->update($values);
    }

    private function office(): Employee
    {
        $employee = Employee::factory()->create();
        User::factory()->create(['employee_id' => $employee->id, 'must_change_password' => false]);
        app(ScheduleAssigner::class)->assign($employee, WorkSchedule::where('code', 'OFFICE')->sole(), '2026-11-14', null, User::factory()->create());

        return $employee;
    }

    private function in(Employee $employee, string $at, string $shiftDate): AttendanceRecord
    {
        return AttendanceRecord::factory()->checkIn()->create(['employee_id' => $employee->id, 'recorded_at' => $at, 'shift_date' => $shiftDate]);
    }

    private function out(Employee $employee, string $at, string $shiftDate, float $hours): AttendanceRecord
    {
        return AttendanceRecord::factory()->checkOut()->create(['employee_id' => $employee->id, 'recorded_at' => $at, 'shift_date' => $shiftDate, 'work_hours' => $hours]);
    }

    /** @return array<string, array<string, mixed>> */
    private function grid(Employee $employee, string $from, string $to): array
    {
        $this->actingAsAdmin();

        return collect($this->getJson('/api/attendance/report/employee?'.http_build_query(['employee_id' => $employee->id, 'from' => $from, 'to' => $to]))
            ->assertOk()->json('data.days'))->keyBy('date')->all();
    }

    private function checkIn(Employee $employee, string $at): TestResponse
    {
        Carbon::setTestNow($at);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::where('employee_id', $employee->id)->sole());

        return $this->postJson('/api/me/attendance', [
            'type'        => AttendanceEventType::CheckIn->value,
            'latitude'    => 33.5,
            'longitude'   => 36.3,
            'selfie'      => UploadedFile::fake()->create('selfie.jpg', 100, 'image/jpeg'),
            'recorded_at' => $at,
        ]);
    }

    public function test_a_per_shift_late_grace_decides_late(): void
    {
        $this->dayRules(['late_grace_minutes' => 5]);
        $office = $this->office();
        $this->in($office, '2026-11-14 09:06:00', '2026-11-14');
        $this->out($office, '2026-11-14 16:00:00', '2026-11-14', 6.9);
        Carbon::setTestNow('2026-11-15 10:00:00');

        $this->assertSame(6, $this->grid($office, '2026-11-14', '2026-11-14')['2026-11-14']['late_minutes']);
    }

    public function test_a_per_shift_opening_window_decides_matching(): void
    {
        $this->dayRules(['check_in_opens_minutes' => 30]);

        $this->checkIn($this->office(), '2026-11-14 08:29:00')->assertCreated()->assertJsonPath('data.outside_schedule', true);
        $this->checkIn($this->office(), '2026-11-14 08:30:00')->assertCreated()->assertJsonPath('data.outside_schedule', false);
    }

    public function test_a_per_shift_close_decides_the_missing_checkout(): void
    {
        $this->dayRules(['check_out_closes_minutes' => 60]);
        $checkIn = $this->in($this->office(), '2026-11-14 09:00:00', '2026-11-14');

        foreach (['2026-11-14 16:55:00' => null, '2026-11-14 17:00:00' => AttendanceRecordStatus::MissingCheckout] as $at => $status) {
            Carbon::setTestNow($at);
            $this->artisan('attendance:shift-clock', ['--at' => $at])->assertSuccessful();
            $this->assertSame($status, $checkIn->fresh()->status, $at);
        }
    }

    public function test_a_future_version_leaves_earlier_days_alone(): void
    {
        Shift::where('code', 'DAY')->sole()->rules()->create(
            ['effective_from' => '2026-11-15', 'late_grace_minutes' => 0] + ShiftRules::defaults(420)->toArray(),
        );
        $office = $this->office();
        foreach (['2026-11-14', '2026-11-15'] as $date) {
            $this->in($office, "{$date} 09:10:00", $date);
            $this->out($office, "{$date} 16:00:00", $date, 6.83);
        }
        Carbon::setTestNow('2026-11-16 10:00:00');

        $days = $this->grid($office, '2026-11-14', '2026-11-15');

        $this->assertSame(0, $days['2026-11-14']['late_minutes'], 'the default 15-minute grace still holds the 14th');
        $this->assertSame(10, $days['2026-11-15']['late_minutes'], 'the version from the 15th has no grace');
    }

    public function test_a_duty_takes_check_in_rules_from_its_first_shift_and_close_from_its_last(): void
    {
        $at = fn (string $moment): CarbonImmutable => CarbonImmutable::parse($moment, 'Asia/Damascus');
        $shift = fn (string $date, string $start, ShiftRules $rules): ExpectedShift => new ExpectedShift(
            $date, $at("{$date} {$start}"), $at("{$date} {$start}")->addDay(), 'G', 'حراسة', 'assignment', null, $rules,
        );

        $duty = Duty::of([
            $shift('2026-11-14', '08:00', new ShiftRules(15, 15, 30, 240, false, 0, 1440)),
            $shift('2026-11-15', '08:00', new ShiftRules(15, 15, 90, 240, false, 0, 1440)),
            $shift('2026-11-16', '08:00', new ShiftRules(15, 15, 60, 120, false, 0, 1440)),
        ]);

        $this->assertSame('2026-11-14 07:30', $duty->opensAt->format('Y-m-d H:i'));
        $this->assertSame('2026-11-17 10:00', $duty->closesAt->format('Y-m-d H:i'));
    }

    public function test_rules_are_read_once_per_call_not_per_day(): void
    {
        $count = function (int $people): int {
            for ($i = 0; $i < $people; $i++) {
                $this->office();
            }
            $this->actingAsUserWithPermissions(['scheduling.view']);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/scheduling/calendar?from=2026-11-14&to=2026-11-27')->assertOk();
            $queries = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'shift_rules'))->count();
            DB::disableQueryLog();

            return $queries;
        };

        $one = $count(1);
        $this->assertGreaterThan(0, $one, 'the calendar reads the shifts\' rules');
        $this->assertLessThanOrEqual(2, $one);
        $this->assertSame($one, $count(4), 'five people over 14 days read the rules as often as one');
    }
}
