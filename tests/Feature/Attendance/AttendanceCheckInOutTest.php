<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Enums\AttendanceRecordStatus;
use App\Modules\Attendance\Enums\AttendanceSource;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Attendance\Concerns\FakesApprovedLeave;
use Tests\TestCase;

/**
 * Check-in and check-out — legacy's AttendanceCheckInOutTest, ported onto ERP
 * employees (work order §4.5; docs/ATTENDANCE_BUILD.md).
 *
 * Every legacy rule is kept: one check-in a day, a check-out needs a standing
 * check-in after it, the 60-minute mis-tap guard, no check-in during approved
 * leave, the configurable window and working days, the attendance timezone.
 * Plus what the decision round added: anyone active may record (Q7), selfies
 * are private (Q11), and a retried submission returns the saved record (Q12).
 */
class AttendanceCheckInOutTest extends TestCase
{
    use FakesApprovedLeave;
    use RefreshDatabase;

    // Verified weekdays: 2026-06-19 is a Friday, the 20th a Saturday, the 21st a Sunday.
    private const SUNDAY = '2026-06-21';

    private const FRIDAY = '2026-06-19';

    private const SATURDAY = '2026-06-20';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Legacy's own tests pin a Friday off and a 06:00–17:00 window to exercise the mechanism. */
    private function pinFridayOffAndWindow(): void
    {
        config([
            'attendance.working_days'   => [0, 1, 2, 3, 4, 6],
            'attendance.check_in.start' => '06:00',
            'attendance.check_in.end'   => '17:00',
        ]);
    }

    private function employee(array $attributes = []): Employee
    {
        $employee = Employee::factory()->create($attributes);
        User::factory()->create(['employee_id' => $employee->id, 'must_change_password' => false]);

        return $employee;
    }

    private function actAs(Employee $employee): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::where('employee_id', $employee->id)->sole());
    }

    /** @param  array<string, mixed>  $overrides */
    private function record(string $recordedAt, AttendanceEventType $type = AttendanceEventType::CheckIn, array $overrides = []): TestResponse
    {
        return $this->postJson('/api/me/attendance', array_merge([
            'type'        => $type->value,
            'latitude'    => 33.5,
            'longitude'   => 36.3,
            'selfie'      => UploadedFile::fake()->create('selfie.jpg', 100, 'image/jpeg'),
            'recorded_at' => $recordedAt,
        ], $overrides));
    }

    private function checkInAt(Employee $employee, string $recordedAt): AttendanceRecord
    {
        return AttendanceRecord::factory()->checkIn()->create(['employee_id' => $employee->id, 'recorded_at' => $recordedAt]);
    }

    private function onLeave(Employee $employee, string $date): void
    {
        $this->grantLeave($employee, $date, $date);
    }

    // ── Parity audit, 2026-10-20 ─────────────────────────────────────────

    /**
     * S-2: an offline check-out that arrives after the day closed clears the
     * check-in's missing-check-out flag, so the reports agree on the day.
     */
    public function test_a_late_check_out_clears_the_missing_check_out_flag(): void
    {
        $employee = $this->employee();
        $checkIn = AttendanceRecord::factory()->checkIn()->create([
            'employee_id' => $employee->id, 'recorded_at' => self::SUNDAY.' 08:00:00', 'status' => AttendanceRecordStatus::MissingCheckout,
        ]);
        $this->actAs($employee);

        $this->record(self::SUNDAY.' 16:00:00', AttendanceEventType::CheckOut)->assertCreated();

        $this->assertNull($checkIn->fresh()->status);
    }

    /**
     * S-6: a moment more than ten minutes ahead of the server is refused — a
     * phone with a wrong clock can no longer check in for tomorrow. Within
     * the tolerance, clock drift is accepted.
     */
    public function test_a_moment_in_the_future_is_refused_beyond_the_tolerance(): void
    {
        Carbon::setTestNow(self::SUNDAY.' 08:00:00');
        $employee = $this->employee();
        $this->actAs($employee);

        $this->record(self::SUNDAY.' 08:30:00')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'وقت التسجيل لا يمكن أن يكون في المستقبل.');

        $this->record(self::SUNDAY.' 08:09:00')->assertCreated();
        $this->assertSame(1, AttendanceRecord::count());
    }

    // ── Check-in ─────────────────────────────────────────────────────────

    public function test_a_check_in_is_recorded_and_returned(): void
    {
        $this->actAs($employee = $this->employee());

        $this->record(self::SUNDAY.' 08:00:00')
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', AttendanceEventType::CheckIn->value)
            ->assertJsonPath('data.type_label', 'حضور')
            ->assertJsonPath('data.source', AttendanceSource::Online->value)
            ->assertJsonPath('data.recorded_at', '2026-06-21T08:00:00+03:00')
            ->assertJsonPath('data.has_selfie', true)
            ->assertJsonMissingPath('data.selfie_path');

        $this->assertSame($employee->id, AttendanceRecord::sole()->employee_id);
    }

    public function test_the_shipped_calendar_has_no_day_off_and_no_window(): void
    {
        // Q8 and Q9: every day is a working day and check-in is open 24 hours.
        $this->actAs($this->employee());

        $this->record(self::FRIDAY.' 03:00:00')->assertCreated();
    }

    public function test_check_in_accepted_at_window_boundaries(): void
    {
        $this->pinFridayOffAndWindow();

        $this->actAs($this->employee());
        $this->record(self::SUNDAY.' 06:00:00')->assertCreated();

        $this->actAs($this->employee());
        $this->record(self::SUNDAY.' 17:00:00')->assertCreated();
    }

    public function test_check_in_rejected_before_and_after_the_window(): void
    {
        $this->pinFridayOffAndWindow();
        $this->actAs($this->employee());

        $this->record(self::SUNDAY.' 05:59:00')->assertStatus(422)->assertJsonPath('message', 'لا يمكن تسجيل الحضور قبل الساعة 06:00.');
        $this->record(self::SUNDAY.' 17:01:00')->assertStatus(422)->assertJsonPath('message', 'لا يمكن تسجيل الحضور بعد الساعة 17:00.');

        $this->assertSame(0, AttendanceRecord::count());
    }

    public function test_a_configured_day_off_is_honoured(): void
    {
        $this->pinFridayOffAndWindow();
        $this->actAs($this->employee());

        $this->record(self::FRIDAY.' 09:00:00')->assertStatus(422);
        $this->record(self::SATURDAY.' 09:00:00')->assertCreated();
    }

    public function test_a_second_check_in_on_the_same_day_is_refused(): void
    {
        $this->actAs($this->employee());

        $this->record(self::SUNDAY.' 08:00:00')->assertCreated();
        $this->record(self::SUNDAY.' 09:00:00')
            ->assertStatus(422)
            ->assertJsonPath('message', 'لديك تسجيل حضور قائم بالفعل لهذا اليوم.');

        $this->assertSame(1, AttendanceRecord::count());
    }

    public function test_a_second_check_in_is_refused_after_a_completed_day_too(): void
    {
        $this->actAs($employee = $this->employee());
        $this->checkInAt($employee, self::SUNDAY.' 08:00:00');
        AttendanceRecord::factory()->checkOut()->create(['employee_id' => $employee->id, 'recorded_at' => self::SUNDAY.' 12:00:00']);

        $this->record(self::SUNDAY.' 13:00:00')->assertStatus(422);
    }

    public function test_check_in_refused_during_approved_leave(): void
    {
        $this->actAs($employee = $this->employee());
        $this->onLeave($employee, self::SUNDAY);

        $this->record(self::SUNDAY.' 08:00:00')
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن تسجيل الحضور أثناء إجازة معتمدة لهذا اليوم.');
        $this->assertSame(0, AttendanceRecord::count());
    }

    public function test_check_in_during_leave_allowed_when_config_permits(): void
    {
        config(['attendance.allow_attendance_during_leave' => true]);
        $this->actAs($employee = $this->employee());
        $this->onLeave($employee, self::SUNDAY);

        $this->record(self::SUNDAY.' 08:00:00')->assertCreated();
    }

    public function test_with_no_leave_module_nobody_is_on_leave(): void
    {
        $this->actAs($this->employee());

        $this->record(self::SUNDAY.' 08:00:00')->assertCreated();
    }

    public function test_recorded_at_is_stored_in_the_attendance_timezone(): void
    {
        $this->actAs($this->employee());

        $this->record('2026-06-21T09:00:00Z')->assertCreated();

        $this->assertSame('2026-06-21 12:00:00', AttendanceRecord::sole()->recorded_at->format('Y-m-d H:i:s'));
    }

    // ── Check-out ────────────────────────────────────────────────────────

    public function test_a_check_out_carries_the_days_hours(): void
    {
        $this->actAs($employee = $this->employee());
        $this->checkInAt($employee, self::SUNDAY.' 08:00:00');

        $this->record(self::SUNDAY.' 16:00:00', AttendanceEventType::CheckOut)
            ->assertCreated()
            ->assertJsonPath('data.work_hours', 8)
            ->assertJsonPath('data.work_hours_formatted', '8h 0m');

        $this->assertNull(AttendanceRecord::checkIns()->sole()->work_hours);
    }

    public function test_check_out_refused_without_a_check_in(): void
    {
        $this->actAs($this->employee());

        $this->record(self::SUNDAY.' 16:00:00', AttendanceEventType::CheckOut)
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن تسجيل الانصراف بدون تسجيل حضور صالح لنفس اليوم.');
    }

    public function test_check_out_refused_before_the_check_in(): void
    {
        $this->actAs($employee = $this->employee());
        $this->checkInAt($employee, self::SUNDAY.' 08:00:00');

        $this->record(self::SUNDAY.' 07:00:00', AttendanceEventType::CheckOut)->assertStatus(422);
        $this->assertSame(0, AttendanceRecord::checkOuts()->count());
    }

    public function test_check_out_refused_inside_the_minimum_gap_and_allowed_at_it(): void
    {
        $this->actAs($employee = $this->employee());
        $this->checkInAt($employee, self::SUNDAY.' 08:00:00');

        $this->record(self::SUNDAY.' 08:30:00', AttendanceEventType::CheckOut)
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن تسجيل الانصراف قبل مرور 60 دقيقة على الحضور.');
        $this->record(self::SUNDAY.' 09:00:00', AttendanceEventType::CheckOut)
            ->assertCreated()
            ->assertJsonPath('data.work_hours', 1);
    }

    public function test_the_gap_guard_is_off_at_zero(): void
    {
        config(['attendance.min_checkout_gap_minutes' => 0]);
        $this->actAs($employee = $this->employee());
        $this->checkInAt($employee, self::SUNDAY.' 08:00:00');

        $this->record(self::SUNDAY.' 08:01:00', AttendanceEventType::CheckOut)->assertCreated();
    }

    public function test_a_second_check_out_is_refused(): void
    {
        $this->actAs($employee = $this->employee());
        $this->checkInAt($employee, self::SUNDAY.' 08:00:00');

        $this->record(self::SUNDAY.' 16:00:00', AttendanceEventType::CheckOut)->assertCreated();
        $this->record(self::SUNDAY.' 17:00:00', AttendanceEventType::CheckOut)->assertStatus(422);
    }

    // ── Selfies (Q11) ────────────────────────────────────────────────────

    public function test_the_selfie_is_stored_privately(): void
    {
        $this->actAs($employee = $this->employee());

        $this->record(self::SUNDAY.' 08:00:00')->assertCreated();

        $path = AttendanceRecord::sole()->selfie_path;
        $this->assertStringStartsWith("attendance/selfies/{$employee->id}/2026-06-21/", $path);
        Storage::disk('local')->assertExists($path);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'nothing on the public disk');
    }

    public function test_a_refused_event_leaves_no_selfie_behind(): void
    {
        $this->actAs($this->employee());

        $this->record(self::SUNDAY.' 16:00:00', AttendanceEventType::CheckOut)->assertStatus(422);

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_selfie_is_required_and_must_be_an_image(): void
    {
        $this->actAs($this->employee());

        $this->record(self::SUNDAY.' 08:00:00', overrides: ['selfie' => null])
            ->assertStatus(422)->assertJsonValidationErrors('selfie');
        $this->record(self::SUNDAY.' 08:00:00', overrides: ['selfie' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
            ->assertStatus(422)->assertJsonValidationErrors('selfie');
    }

    // ── A retried submission (Q12) ───────────────────────────────────────

    public function test_a_retry_with_the_same_client_uuid_returns_the_saved_record(): void
    {
        $this->actAs($this->employee());
        $uuid = '7d0f6f2e-3b1a-4c5e-9a8b-1f2e3d4c5b6a';

        $first = $this->record(self::SUNDAY.' 08:00:00', overrides: ['client_uuid' => $uuid])->assertCreated();
        $retry = $this->record(self::SUNDAY.' 08:00:00', overrides: ['client_uuid' => $uuid])->assertOk();

        $this->assertSame($first->json('data.id'), $retry->json('data.id'));
        $this->assertSame($uuid, $retry->json('data.client_uuid'));
        $this->assertSame(1, AttendanceRecord::count());
        $this->assertCount(1, Storage::disk('local')->allFiles(), 'the retry stored no second selfie');
    }

    public function test_without_a_client_uuid_a_retry_is_legacys_error(): void
    {
        $this->actAs($this->employee());

        $this->record(self::SUNDAY.' 08:00:00')->assertCreated();
        $this->record(self::SUNDAY.' 08:00:00')->assertStatus(422);
    }

    public function test_the_same_client_uuid_from_another_employee_is_a_different_record(): void
    {
        $uuid = '7d0f6f2e-3b1a-4c5e-9a8b-1f2e3d4c5b6a';

        $this->actAs($this->employee());
        $this->record(self::SUNDAY.' 08:00:00', overrides: ['client_uuid' => $uuid])->assertCreated();
        $this->actAs($this->employee());
        $this->record(self::SUNDAY.' 08:00:00', overrides: ['client_uuid' => $uuid])->assertCreated();

        $this->assertSame(2, AttendanceRecord::count());
    }

    public function test_a_client_uuid_must_be_a_uuid(): void
    {
        $this->actAs($this->employee());

        $this->record(self::SUNDAY.' 08:00:00', overrides: ['client_uuid' => 'not-a-uuid'])
            ->assertStatus(422)->assertJsonValidationErrors('client_uuid');
    }

    // ── Who records (Q7) ─────────────────────────────────────────────────

    public function test_an_account_with_no_employee_cannot_record(): void
    {
        Sanctum::actingAs(User::factory()->create(['must_change_password' => false]));

        $this->record(self::SUNDAY.' 08:00:00')->assertForbidden()->assertJsonPath('success', false);
    }

    public function test_a_suspended_employee_cannot_record(): void
    {
        $this->actAs($this->employee(['status' => EmployeeStatus::Suspended]));

        $this->record(self::SUNDAY.' 08:00:00')->assertStatus(422);
        $this->assertSame(0, AttendanceRecord::count());
    }

    public function test_recording_needs_authentication(): void
    {
        $this->record(self::SUNDAY.' 08:00:00')->assertUnauthorized();
    }

    public function test_the_type_must_be_a_known_event(): void
    {
        $this->actAs($this->employee());

        $this->record(self::SUNDAY.' 08:00:00', overrides: ['type' => 9])
            ->assertStatus(422)->assertJsonValidationErrors('type');
    }
}
