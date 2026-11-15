<?php

declare(strict_types=1);

namespace Tests\Feature\Me;

use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Support\ShiftRules;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Models\Shift;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Modules\Scheduling\Services\ScheduleAssigner;
use App\Modules\Scheduling\Services\ShiftChanger;
use Database\Seeders\SchedulingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/me/summary's `duty` card (work shifts piece 4, app spec §4.1): the
 * check-in a check-out now would close, and the duties from the one in
 * progress through the next seven days, each with its window and close from
 * its shifts' own rules. The app compares "now" with these instants and never
 * works a window out itself.
 *
 * 2026-11-14 is a Saturday: OFFICE works it (09:00–16:00; opens 08:00, closes
 * 20:00), and Friday the 20th is off.
 */
class MyDutyCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Carbon::setTestNow('2026-11-14 06:00:00');
        $this->seed(SchedulingSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function person(string $schedule, ?string $cycleStart = null): Employee
    {
        $employee = Employee::factory()->create();
        User::factory()->create(['employee_id' => $employee->id, 'must_change_password' => false]);
        app(ScheduleAssigner::class)->assign($employee, WorkSchedule::where('code', $schedule)->sole(), '2026-11-14', $cycleStart, User::factory()->create());

        return $employee;
    }

    private function actAs(Employee $employee): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::where('employee_id', $employee->id)->sole());
    }

    private function record(string $at, AttendanceEventType $type = AttendanceEventType::CheckIn): TestResponse
    {
        Carbon::setTestNow($at);

        return $this->postJson('/api/me/attendance', [
            'type'        => $type->value,
            'latitude'    => 33.5,
            'longitude'   => 36.3,
            'selfie'      => UploadedFile::fake()->create('selfie.jpg', 100, 'image/jpeg'),
            'recorded_at' => $at,
        ]);
    }

    /** @return array<string, mixed> */
    private function dutyAt(string $now): array
    {
        Carbon::setTestNow($now);

        return $this->getJson('/api/me/summary')->assertOk()->json('data.duty');
    }

    private function nights(): void
    {
        $night = Shift::create(['code' => 'NIGHT', 'name' => 'وردية ليلية', 'starts_at' => '22:00', 'duration_minutes' => 480]);
        WorkSchedule::createWithDays(['code' => 'NIGHTS', 'name' => 'ليلي', 'kind' => 1, 'cycle_days' => 7], array_fill(0, 7, $night->id));
    }

    public function test_office_day_lists_todays_duty_with_its_window(): void
    {
        $this->actAs($this->person('OFFICE'));

        $duty = $this->dutyAt('2026-11-14 07:00:00');

        $this->assertNull($duty['open_check_in']);
        $this->assertSame([
            'shift_date'     => '2026-11-14',
            'opens_at'       => '2026-11-14T08:00:00+03:00',
            'starts_at'      => '2026-11-14T09:00:00+03:00',
            'ends_at'        => '2026-11-14T16:00:00+03:00',
            'closes_at'      => '2026-11-14T20:00:00+03:00',
            'shifts'         => [['code' => 'DAY', 'name' => 'الدوام الإداري', 'starts_at' => '2026-11-14T09:00:00+03:00', 'ends_at' => '2026-11-14T16:00:00+03:00']],
            'check_in_time'  => null,
            'check_out_time' => null,
        ], $duty['duties'][0]);
    }

    public function test_night_shift_at_two_lists_the_open_check_in_and_its_duty(): void
    {
        $this->nights();
        $this->actAs($this->person('NIGHTS'));
        $this->record('2026-11-14 21:55:00')->assertCreated();

        $duty = $this->dutyAt('2026-11-15 02:00:00');

        $this->assertSame('2026-11-14', $duty['open_check_in']['shift_date']);
        $this->assertFalse($duty['open_check_in']['outside_schedule']);
        $this->assertSame('2026-11-15T10:00:00+03:00', $duty['open_check_in']['closes_at']);
        $this->assertSame('2026-11-14T21:55:00+03:00', $duty['open_check_in']['recorded_at']);
        $this->assertSame('2026-11-14', $duty['duties'][0]['shift_date']);
        $this->assertSame('2026-11-14T21:55:00+03:00', $duty['duties'][0]['check_in_time']);
        $this->assertNull($duty['duties'][0]['check_out_time']);
    }

    public function test_guard_on_the_morning_his_duty_ends(): void
    {
        $this->actAs($this->person('GUARD', '2026-11-14'));
        $this->record('2026-11-14 07:55:00')->assertCreated();

        $duty = $this->dutyAt('2026-11-17 07:30:00');

        $this->assertNotNull($duty['open_check_in']);
        $this->assertSame('2026-11-14', $duty['duties'][0]['shift_date']);
        $this->assertSame('2026-11-17T08:00:00+03:00', $duty['duties'][0]['ends_at']);
        $this->assertCount(3, $duty['duties'][0]['shifts']);
    }

    public function test_outside_schedule_open_check_in_closes_at_the_end_of_its_date(): void
    {
        $this->actAs($this->person('OFFICE'));
        $this->record('2026-11-20 10:00:00')->assertCreated()->assertJsonPath('data.outside_schedule', true);

        $duty = $this->dutyAt('2026-11-20 12:00:00');

        $this->assertTrue($duty['open_check_in']['outside_schedule']);
        $this->assertSame('2026-11-20T23:59:59+03:00', $duty['open_check_in']['closes_at']);
    }

    public function test_refused_check_in_is_not_open(): void
    {
        $this->actAs($this->person('OFFICE'));
        $this->record('2026-11-14 09:00:00')->assertCreated();
        AttendanceRecord::query()->checkIns()->sole()->forceFill(['rejected_at' => '2026-11-14 10:00:00'])->save();

        $duty = $this->dutyAt('2026-11-14 11:00:00');

        $this->assertNull($duty['open_check_in']);
        $this->assertNull($duty['duties'][0]['check_in_time']);
    }

    public function test_done_duty_carries_both_times(): void
    {
        $this->actAs($this->person('OFFICE'));
        $this->record('2026-11-14 08:55:00')->assertCreated();
        $this->record('2026-11-14 16:05:00', AttendanceEventType::CheckOut)->assertCreated();

        $duty = $this->dutyAt('2026-11-14 17:00:00');

        $this->assertNull($duty['open_check_in']);
        $this->assertSame('2026-11-14T08:55:00+03:00', $duty['duties'][0]['check_in_time']);
        $this->assertSame('2026-11-14T16:05:00+03:00', $duty['duties'][0]['check_out_time']);
    }

    /**
     * A check-in before the window is outside the schedule, yet it takes the
     * duty of its date as the check-in rule counts it (final review I1): the
     * card says so, or the hero would offer a check-in the server refuses.
     */
    public function test_an_early_outside_check_in_is_the_dutys(): void
    {
        $this->actAs($this->person('OFFICE'));
        $this->record('2026-11-14 07:55:00')->assertCreated()->assertJsonPath('data.outside_schedule', true);
        $this->record('2026-11-14 13:00:00', AttendanceEventType::CheckOut)->assertCreated();

        $duty = $this->dutyAt('2026-11-14 14:00:00');

        $this->assertSame('2026-11-14T07:55:00+03:00', $duty['duties'][0]['check_in_time']);
        $this->assertSame('2026-11-14T13:00:00+03:00', $duty['duties'][0]['check_out_time']);
    }

    public function test_check_in_past_its_close_is_a_missed_check_out(): void
    {
        $this->actAs($this->person('OFFICE'));
        $this->record('2026-11-14 09:00:00')->assertCreated();

        $open = $this->dutyAt('2026-11-14 19:00:00');
        $this->assertNotNull($open['open_check_in']);
        $this->assertNull($open['missed_check_out']);

        $duty = $this->dutyAt('2026-11-14 20:01:00');

        $this->assertNull($duty['open_check_in']);
        $this->assertSame('2026-11-14', $duty['missed_check_out']['shift_date']);
        $this->assertSame('2026-11-14T09:00:00+03:00', $duty['missed_check_out']['recorded_at']);
        $this->assertSame('2026-11-14T20:00:00+03:00', $duty['missed_check_out']['closed_at']);
        $this->assertFalse($duty['missed_check_out']['outside_schedule']);
    }

    public function test_checked_out_duty_is_not_a_missed_check_out(): void
    {
        $this->actAs($this->person('OFFICE'));
        $this->record('2026-11-14 09:00:00')->assertCreated();
        $this->record('2026-11-14 16:00:00', AttendanceEventType::CheckOut)->assertCreated();

        $this->assertNull($this->dutyAt('2026-11-14 20:01:00')['missed_check_out']);
    }

    public function test_duty_after_its_close_is_not_listed(): void
    {
        $this->actAs($this->person('OFFICE'));

        $duty = $this->dutyAt('2026-11-14 20:01:00');

        $this->assertSame('2026-11-15', $duty['duties'][0]['shift_date']);
    }

    public function test_one_off_change_appears(): void
    {
        $this->nights();
        $employee = $this->person('OFFICE');
        app(ShiftChanger::class)->record($employee, '2026-11-15', '2026-11-15', Shift::where('code', 'NIGHT')->sole(), 'تكليف', User::factory()->create());
        $this->actAs($employee);

        $duty = $this->dutyAt('2026-11-14 20:01:00');

        $this->assertSame('2026-11-15', $duty['duties'][0]['shift_date']);
        $this->assertSame('NIGHT', $duty['duties'][0]['shifts'][0]['code']);
        $this->assertSame('2026-11-15T21:00:00+03:00', $duty['duties'][0]['opens_at']);
    }

    public function test_rule_version_from_tomorrow_moves_tomorrows_opens_at(): void
    {
        Shift::where('code', 'DAY')->sole()->rules()->create(
            ['effective_from' => '2026-11-15', 'check_in_opens_minutes' => 30] + ShiftRules::defaults(420)->toArray()
        );
        $this->actAs($this->person('OFFICE'));

        $duty = $this->dutyAt('2026-11-14 07:00:00');

        $this->assertSame('2026-11-14T08:00:00+03:00', $duty['duties'][0]['opens_at']);
        $this->assertSame('2026-11-15T08:30:00+03:00', $duty['duties'][1]['opens_at']);
    }

    public function test_lists_seven_days_ahead_only(): void
    {
        $this->actAs($this->person('OFFICE'));

        $duty = $this->dutyAt('2026-11-14 07:00:00');

        $limit = Carbon::parse('2026-11-21 07:00:00');
        foreach ($duty['duties'] as $d) {
            $this->assertTrue(Carbon::parse($d['opens_at'])->lt($limit), $d['opens_at']);
        }
        // Sat 14 … Thu 19 and Sat 21 (opens 08:00, after the limit) — Friday off.
        $this->assertSame(['2026-11-14', '2026-11-15', '2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19'], array_column($duty['duties'], 'shift_date'));
    }

    public function test_reads_only_the_callers_employee(): void
    {
        $other = $this->person('OFFICE');
        $this->actAs($other);
        $this->record('2026-11-14 08:55:00')->assertCreated();

        $this->actAs($this->person('OFFICE'));
        $duty = $this->dutyAt('2026-11-14 10:00:00');

        $this->assertNull($duty['open_check_in']);
        $this->assertNull($duty['duties'][0]['check_in_time']);
    }
}
