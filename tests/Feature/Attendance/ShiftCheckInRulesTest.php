<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Enums\AttendanceRecordStatus;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Exceptions\SchedulingException;
use App\Modules\Scheduling\Models\Shift;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Modules\Scheduling\Services\ScheduleAssigner;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SchedulingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Attendance\Concerns\FakesApprovedLeave;
use Tests\TestCase;

/**
 * Work shifts plan Task 8: recording against each person's duties (spec §5).
 * 2026-11-14 is a Saturday; the guard's duty runs 11-14 08:00 → 11-17 08:00
 * and closes at 12:00; the office day is 09:00–16:00, closing at 20:00.
 */
class ShiftCheckInRulesTest extends TestCase
{
    use FakesApprovedLeave;
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

    private function person(string $schedule, ?string $cycleStart = null, string $from = '2026-11-14'): Employee
    {
        $employee = Employee::factory()->create();
        User::factory()->create(['employee_id' => $employee->id, 'must_change_password' => false]);
        app(ScheduleAssigner::class)->assign($employee, WorkSchedule::where('code', $schedule)->sole(), $from, $cycleStart, User::factory()->create());

        return $employee;
    }

    private function actAs(Employee $employee): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::where('employee_id', $employee->id)->sole());
    }

    /** Records at $at, with the clock at $at too unless $now says otherwise. */
    private function record(string $at, AttendanceEventType $type = AttendanceEventType::CheckIn, ?string $now = null): TestResponse
    {
        Carbon::setTestNow($now ?? $at);

        return $this->postJson('/api/me/attendance', [
            'type'        => $type->value,
            'latitude'    => 33.5,
            'longitude'   => 36.3,
            'selfie'      => UploadedFile::fake()->create('selfie.jpg', 100, 'image/jpeg'),
            'recorded_at' => $at,
        ]);
    }

    private function out(string $at, ?string $now = null): TestResponse
    {
        return $this->record($at, AttendanceEventType::CheckOut, $now);
    }

    public function test_a_guard_checks_in_once_for_a_72_hour_duty(): void
    {
        $this->actAs($this->person('GUARD', '2026-11-14'));

        $this->record('2026-11-14 07:30:00')->assertCreated()->assertJsonPath('data.shift_date', '2026-11-14');
        $this->record('2026-11-15 09:00:00')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'لديك تسجيل حضور قائم؛ يجب تسجيل الانصراف أولاً.');
    }

    public function test_a_guard_checks_out_at_the_end_of_the_duty(): void
    {
        $this->actAs($this->person('GUARD', '2026-11-14'));
        $this->record('2026-11-14 07:30:00')->assertCreated();

        $this->out('2026-11-17 08:05:00')->assertCreated()->assertJsonPath('data.shift_date', '2026-11-14');
        $this->assertSame(72.58, AttendanceRecord::query()->checkOuts()->sole()->work_hours);
    }

    public function test_a_check_out_after_the_close_is_refused(): void
    {
        $this->actAs($this->person('GUARD', '2026-11-14'));
        $this->record('2026-11-14 07:30:00')->assertCreated();

        $this->out('2026-11-17 12:01:00')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'لا يمكن تسجيل الانصراف بدون تسجيل حضور قائم.');
        $this->out('2026-11-17 11:59:00', now: '2026-11-17 12:05:00')->assertCreated();
    }

    public function test_a_check_out_recorded_before_the_close_lands_after_it_and_clears_the_flag(): void
    {
        $this->actAs($this->person('GUARD', '2026-11-14'));
        $this->record('2026-11-14 07:30:00')->assertCreated();
        AttendanceRecord::query()->checkIns()->update(['status' => AttendanceRecordStatus::MissingCheckout]);

        $this->out('2026-11-17 11:00:00', now: '2026-11-17 13:00:00')->assertCreated();

        $this->assertNull(AttendanceRecord::query()->checkIns()->sole()->status);
    }

    public function test_a_night_shift_check_out_the_next_morning_pairs(): void
    {
        $night = Shift::create(['code' => 'NIGHT', 'name' => 'ليلية', 'starts_at' => '22:00', 'duration_minutes' => 480]);
        WorkSchedule::createWithDays(['code' => 'NIGHTS', 'name' => 'ليلي', 'kind' => 1, 'cycle_days' => 7], array_fill(0, 7, $night->id));
        $this->actAs($this->person('NIGHTS'));

        $this->record('2026-11-14 21:30:00')->assertCreated()->assertJsonPath('data.shift_date', '2026-11-14');
        $this->out('2026-11-15 06:30:00')->assertCreated()->assertJsonPath('data.shift_date', '2026-11-14');
    }

    public function test_an_office_check_in_at_07_59_is_outside_and_at_08_00_is_the_shift(): void
    {
        $early = $this->person('OFFICE');
        $this->actAs($early);
        $this->record('2026-11-14 07:59:00')->assertCreated()
            ->assertJsonPath('data.shift_date', '2026-11-14')
            ->assertJsonPath('data.outside_schedule', true);

        $onTime = $this->person('OFFICE');
        $this->actAs($onTime);
        $this->record('2026-11-14 08:00:00')->assertCreated()->assertJsonPath('data.outside_schedule', false);
    }

    public function test_a_friday_check_in_is_accepted_and_flagged(): void
    {
        $this->actAs($this->person('OFFICE'));

        $this->record('2026-11-20 10:00:00')->assertCreated()
            ->assertJsonPath('data.shift_date', '2026-11-20')
            ->assertJsonPath('data.outside_schedule', true);
    }

    public function test_a_second_outside_check_in_on_one_date_is_refused(): void
    {
        $this->actAs($this->person('OFFICE'));
        $this->record('2026-11-20 10:00:00')->assertCreated();
        $this->out('2026-11-20 12:00:00')->assertCreated();

        $this->record('2026-11-20 13:00:00')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'لديك تسجيل حضور قائم بالفعل لهذا اليوم.');
    }

    public function test_a_check_in_after_the_shift_has_its_check_in_is_refused(): void
    {
        $this->actAs($this->person('OFFICE'));
        $this->record('2026-11-14 09:00:00')->assertCreated();
        $this->out('2026-11-14 12:00:00')->assertCreated();

        $this->record('2026-11-14 13:00:00')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'لديك تسجيل حضور قائم بالفعل لهذه الوردية.');
    }

    public function test_leave_blocks_the_shift_the_moment_falls_in(): void
    {
        $guard = $this->person('GUARD', '2026-11-14');
        $this->grantLeave($guard, '2026-11-15', '2026-11-15');
        $this->actAs($guard);

        $this->record('2026-11-14 07:30:00')->assertCreated();

        $office = $this->person('OFFICE');
        $this->grantLeave($office, '2026-11-14', '2026-11-14');
        $this->actAs($office);
        $this->record('2026-11-14 09:00:00')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'لا يمكن تسجيل الحضور أثناء إجازة معتمدة لهذا اليوم.');
    }

    public function test_hr_corrects_a_guards_missing_check_out_within_the_close(): void
    {
        $guard = $this->person('GUARD', '2026-11-14');
        $this->actAs($guard);
        $this->record('2026-11-14 07:30:00')->assertCreated();
        $checkIn = AttendanceRecord::query()->checkIns()->sole();
        $checkIn->update(['status' => AttendanceRecordStatus::MissingCheckout]);
        Carbon::setTestNow('2026-11-18 10:00:00');
        $this->seed(PermissionSeeder::class);
        $this->actingAsUserWithPermissions(['attendance.records.correct']);

        $this->patchJson("/api/attendance/records/{$checkIn->id}/checkout", ['checked_out_at' => '2026-11-17 12:01:00'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'وقت الانصراف يجب أن يكون قبل انتهاء مهلة الوردية.');

        $this->patchJson("/api/attendance/records/{$checkIn->id}/checkout", ['checked_out_at' => '2026-11-17 08:00:00'])->assertOk();
        $this->assertSame('2026-11-14', AttendanceRecord::query()->checkOuts()->sole()->shift_date->toDateString());
    }

    /**
     * Review Focus 3 (final review C2): a schedule change cannot start inside
     * a duty that already has its check-in — it would cut the duty short and
     * lose the guard's hours. It may start the day after the duty's last
     * shift; the duty then keeps its three shifts and its check-out.
     */
    public function test_a_schedule_change_today_keeps_the_open_duty(): void
    {
        $guard = $this->person('GUARD', '2026-11-14');
        $this->actAs($guard);
        $this->record('2026-11-14 07:30:00')->assertCreated();

        Carbon::setTestNow('2026-11-15 10:00:00');
        $office = WorkSchedule::where('code', 'OFFICE')->sole();
        $by = User::factory()->create();

        try {
            app(ScheduleAssigner::class)->assign($guard, $office, '2026-11-15', null, $by);
            $this->fail('a change inside the duty must be refused');
        } catch (SchedulingException $refused) {
            $this->assertSame('لدى الموظف تسجيل حضور في مناوبة تمتد إلى 2026-11-16؛ لا يمكن أن يبدأ الجدول الجديد قبل 2026-11-17.', $refused->getMessage());
        }

        app(ScheduleAssigner::class)->assign($guard, $office, '2026-11-17', null, $by);

        $this->out('2026-11-17 08:00:00')->assertCreated()->assertJsonPath('data.shift_date', '2026-11-14');
    }

    public function test_a_schedule_change_cannot_rejudge_a_day_already_checked_into(): void
    {
        $office = $this->person('OFFICE');
        $this->actAs($office);
        $this->record('2026-11-14 09:00:00')->assertCreated();

        Carbon::setTestNow('2026-11-14 10:00:00');
        $this->expectException(SchedulingException::class);
        $this->expectExceptionMessage('لا يمكن أن يبدأ الجدول الجديد قبل 2026-11-15.');

        app(ScheduleAssigner::class)->assign($office, WorkSchedule::where('code', 'GUARD')->sole(), '2026-11-14', '2026-11-14', User::factory()->create());
    }

    public function test_a_sync_with_a_check_out_after_the_close_lands_the_rest(): void
    {
        $this->actAs($this->person('OFFICE'));
        Carbon::setTestNow('2026-11-15 09:30:00');

        $response = $this->postJson('/api/me/attendance/sync', [
            'records' => [
                $this->entry('2026-11-14 09:00:00', AttendanceEventType::CheckIn),
                $this->entry('2026-11-14 20:01:00', AttendanceEventType::CheckOut),
                $this->entry('2026-11-15 08:55:00', AttendanceEventType::CheckIn),
            ],
            'selfies' => array_map(fn (): UploadedFile => UploadedFile::fake()->create('selfie.jpg', 100, 'image/jpeg'), range(1, 3)),
        ]);

        $response->assertOk();
        $this->assertSame(2, AttendanceRecord::count());
        $response->assertJsonPath('data.failed.0.index', 1)
            ->assertJsonPath('data.failed.0.error', 'لا يمكن تسجيل الانصراف بدون تسجيل حضور قائم.');
    }

    /** @return array<string, mixed> */
    private function entry(string $at, AttendanceEventType $type): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'type'        => $type->value,
            'latitude'    => 33.5,
            'longitude'   => 36.3,
            'recorded_at' => $at,
        ];
    }
}
