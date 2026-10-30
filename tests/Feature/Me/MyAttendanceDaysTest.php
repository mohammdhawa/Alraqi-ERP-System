<?php

declare(strict_types=1);

namespace Tests\Feature\Me;

use App\Modules\Attendance\Enums\AttendanceDayState;
use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Enums\AttendanceRecordStatus;
use App\Modules\Attendance\Enums\RejectionReason;
use App\Modules\Auth\Enums\ScopeType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Attendance\Concerns\AttendanceTree;
use Tests\Feature\Attendance\Concerns\FakesApprovedLeave;
use Tests\TestCase;

/**
 * GET /api/me/attendance/days — the employee app's month calendar («دوامي»):
 * §14.3's day grid for the caller's OWN employee, through the same days()
 * the home screen reads, so the day-state precedence lives in one place.
 *
 * Self-scoped (C7): no permission, and no id is read from the request — an
 * `employee_id` is ignored, never honoured.
 */
class MyAttendanceDaysTest extends TestCase
{
    use AttendanceTree;
    use FakesApprovedLeave;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday 24 June 2026, midday — every day is a working day (Q8).
        Carbon::setTestNow('2026-06-24 12:00:00');
        $this->buildTree();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $query */
    private function days(array $query = ['from' => '2026-06-21', 'to' => '2026-06-27'], string $as = 'a'): TestResponse
    {
        Sanctum::actingAs($this->accountOf($this->people[$as]));

        return $this->getJson('/api/me/attendance/days?'.http_build_query($query));
    }

    /** @return array<string, int> date => state */
    private function states(TestResponse $response): array
    {
        return array_column($response->json('data.days'), 'status', 'date');
    }

    public function test_a_month_is_every_day_of_it(): void
    {
        $response = $this->days(['month' => 6, 'year' => 2026])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.employee.id', $this->people['a']->id)
            ->assertJsonPath('data.from', '2026-06-01')
            ->assertJsonPath('data.to', '2026-06-30')
            ->assertJsonPath('data.working_days', 30)
            ->assertJsonPath('data.counted_to', '2026-06-24')
            ->assertJsonCount(30, 'data.days');

        $this->assertSame('2026-06-01', $response->json('data.days.0.date'));
        $this->assertSame('2026-06-30', $response->json('data.days.29.date'));
    }

    public function test_a_from_to_window_and_the_92_day_cap(): void
    {
        $this->days(['from' => '2026-06-21', 'to' => '2026-06-27'])->assertOk()->assertJsonCount(7, 'data.days');

        // 92 days is the most; 93 is refused, as §14.3.
        $this->days(['from' => '2026-04-01', 'to' => '2026-07-01'])->assertOk()->assertJsonCount(92, 'data.days');
        $this->days(['from' => '2026-04-01', 'to' => '2026-07-02'])->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.to.0', 'النطاق الزمني المحدد طويل جداً (الحد الأقصى ٩٢ يوماً).');

        $this->days(['from' => '2026-06-27', 'to' => '2026-06-21'])->assertStatus(422)->assertJsonValidationErrors('to');
        $this->days([])->assertStatus(422)->assertJsonValidationErrors(['from', 'to']);
        $this->days(['month' => 13])->assertStatus(422)->assertJsonValidationErrors('month');
    }

    public function test_the_month_defaults_to_this_year(): void
    {
        $this->days(['month' => 2])->assertOk()
            ->assertJsonPath('data.from', '2026-02-01')
            ->assertJsonPath('data.to', '2026-02-28');
    }

    public function test_the_days_are_the_callers_own_and_an_employee_id_is_ignored(): void
    {
        $this->checkIn($this->people['a'], '2026-06-22 08:00:00');
        $this->checkIn($this->people['b'], '2026-06-23 08:00:00');

        foreach ([[], ['employee_id' => $this->people['b']->id], ['employee_ids' => [$this->people['b']->id]]] as $extra) {
            $response = $this->days(['from' => '2026-06-21', 'to' => '2026-06-27'] + $extra)->assertOk()
                ->assertJsonPath('data.employee.id', $this->people['a']->id)
                ->assertJsonPath('data.summary.present_days', 1);

            $this->assertSame(AttendanceDayState::CheckedIn->value, $this->states($response)['2026-06-22']);
            $this->assertSame(AttendanceDayState::Absent->value, $this->states($response)['2026-06-23'], "b's day is not a's");
        }

        // b, asking, gets b's own.
        $this->days(as: 'b')->assertOk()->assertJsonPath('data.employee.id', $this->people['b']->id);
    }

    public function test_no_permission_is_needed(): void
    {
        $this->assertSame([], $this->accountOf($this->people['a'])->allPermissionNames());

        $this->days()->assertOk();
    }

    /**
     * §14.3's precedence: an accepted check-in (2 / 1 / 3), else a day off
     * (7), else an excuse (5), else leave (4), else a refusal (8), else absent
     * (6) — and after today, or today with nothing yet, 9.
     */
    public function test_one_state_per_day_by_the_grids_precedence(): void
    {
        Carbon::setTestNow('2026-06-30 12:00:00');
        $a = $this->people['a'];
        $this->checkIn($a, '2026-06-21 08:00:00');
        $this->checkOut($a, '2026-06-21 16:00:00');
        $this->checkIn($a, '2026-06-22 08:00:00');
        $this->checkIn($a, '2026-06-23 08:00:00', ['status' => AttendanceRecordStatus::MissingCheckout]);
        $this->grantLeave($a, '2026-06-24', '2026-06-24');
        $this->grantLeave($a, '2026-06-25', '2026-06-25', excuse: true);
        $this->checkIn($a, '2026-06-26 08:00:00', ['rejected_at' => now(), 'rejection_reason' => RejectionReason::Selfie]);

        $this->assertSame([
            '2026-06-21' => AttendanceDayState::CheckedOut->value,
            '2026-06-22' => AttendanceDayState::CheckedIn->value,
            '2026-06-23' => AttendanceDayState::MissingCheckout->value,
            '2026-06-24' => AttendanceDayState::OnLeave->value,
            '2026-06-25' => AttendanceDayState::Excused->value,
            '2026-06-26' => AttendanceDayState::Rejected->value,
            '2026-06-27' => AttendanceDayState::Absent->value,
        ], $this->states($this->days()->assertOk()));
    }

    /**
     * The two rules the app's home leans on for its missing check-outs: a
     * refused check-in is a refusal (8), never a missing check-out (3); and a
     * check-out that arrives late, through the offline queue, clears the 3.
     */
    public function test_a_refusal_is_not_a_missing_check_out_and_a_late_check_out_clears_it(): void
    {
        Storage::fake('local');
        $a = $this->people['a'];
        $this->checkIn($a, '2026-06-22 08:00:00', ['status' => AttendanceRecordStatus::MissingCheckout]);
        $this->checkIn($a, '2026-06-23 08:00:00', ['rejected_at' => now(), 'rejection_reason' => RejectionReason::Selfie]);

        $before = $this->days()->assertOk()->assertJsonPath('data.summary.missing_checkouts', 1);
        $this->assertSame(AttendanceDayState::MissingCheckout->value, $this->states($before)['2026-06-22']);
        $this->assertSame(AttendanceDayState::Rejected->value, $this->states($before)['2026-06-23']);

        $this->postJson('/api/me/attendance/sync', [
            'records' => [[
                'type'        => AttendanceEventType::CheckOut->value,
                'latitude'    => 33.5,
                'longitude'   => 36.3,
                'recorded_at' => '2026-06-22T16:00:00+03:00',
                'client_uuid' => (string) Str::uuid(),
            ]],
            'selfies' => [UploadedFile::fake()->create('selfie.jpg', 100, 'image/jpeg')],
        ])->assertOk()->assertJsonCount(1, 'data.succeeded')->assertJsonCount(0, 'data.failed');

        $after = $this->days()->assertOk()->assertJsonPath('data.summary.missing_checkouts', 0);
        $this->assertSame(AttendanceDayState::CheckedOut->value, $this->states($after)['2026-06-22']);
        $this->assertSame(AttendanceDayState::Rejected->value, $this->states($after)['2026-06-23']);
    }

    public function test_a_configured_day_off_is_off(): void
    {
        config(['attendance.working_days' => [1, 2, 3, 4, 5, 6]]);   // Sunday off

        $response = $this->days()->assertOk()->assertJsonPath('data.working_days', 6);

        $this->assertSame(AttendanceDayState::Off->value, $this->states($response)['2026-06-21']);
        $this->assertFalse($response->json('data.days.0.working_day'));
    }

    /**
     * Today with nothing recorded is 9 «لم يُسجَّل بعد», not absent, and so
     * is every day after it — leave over a future day still shows, for the
     * calendar. None of them is counted.
     */
    public function test_today_with_nothing_yet_and_the_days_after_it_are_not_yet(): void
    {
        $this->checkIn($this->people['a'], '2026-06-23 08:00:00');
        $this->grantLeave($this->people['a'], '2026-06-26', '2026-06-26');

        $response = $this->days()->assertOk();

        $this->assertSame([
            '2026-06-21' => AttendanceDayState::Absent->value,
            '2026-06-22' => AttendanceDayState::Absent->value,
            '2026-06-23' => AttendanceDayState::CheckedIn->value,
            '2026-06-24' => AttendanceDayState::NotYet->value,
            '2026-06-25' => AttendanceDayState::NotYet->value,
            '2026-06-26' => AttendanceDayState::OnLeave->value,
            '2026-06-27' => AttendanceDayState::NotYet->value,
        ], $this->states($response));

        $response->assertJsonPath('data.days.3.status_label', 'لم يُسجَّل بعد')
            ->assertJsonPath('data.working_days', 7)
            ->assertJsonPath('data.counted_to', '2026-06-24')
            ->assertJsonPath('data.summary.expected_days', 3)
            ->assertJsonPath('data.summary.present_days', 1)
            ->assertJsonPath('data.summary.on_leave_days', 0)
            ->assertJsonPath('data.summary.absent_days', 2);
    }

    public function test_today_once_checked_in_is_counted(): void
    {
        $this->checkIn($this->people['a'], '2026-06-24 08:00:00');

        $response = $this->days()->assertOk()
            ->assertJsonPath('data.days.3.date', '2026-06-24')
            ->assertJsonPath('data.days.3.status', AttendanceDayState::CheckedIn->value)
            ->assertJsonPath('data.days.3.status_label', 'حاضر (لم ينصرف بعد)')
            ->assertJsonPath('data.summary.expected_days', 4)
            ->assertJsonPath('data.summary.present_days', 1);

        $this->assertSame(Carbon::parse('2026-06-24 08:00:00')->toIso8601String(), $response->json('data.days.3.check_in_time'));
    }

    /** A window that starts after today counts nothing: `counted_to` is null. */
    public function test_a_window_after_today_counts_nothing(): void
    {
        $response = $this->days(['month' => 7, 'year' => 2026])->assertOk()
            ->assertJsonPath('data.counted_to', null)
            ->assertJsonPath('data.working_days', 31)
            ->assertJsonPath('data.summary.expected_days', 0)
            ->assertJsonPath('data.summary.attendance_rate', null);

        $this->assertSame([AttendanceDayState::NotYet->value], array_values(array_unique($this->states($response))));
    }

    /** The shape is §14.3's, key for key. */
    public function test_the_response_is_the_grids_shape(): void
    {
        $data = $this->days()->assertOk()->json('data');

        $this->assertSame(['employee', 'department', 'from', 'to', 'working_days', 'counted_to', 'days', 'summary'], array_keys($data));
        $this->assertSame(['id', 'full_name', 'employee_number'], array_keys($data['employee']));
        $this->assertSame(['id' => $this->units['a']->id, 'name' => 'إدارة أ'], $data['department']);
        $this->assertSame([
            'date', 'weekday', 'working_day', 'status', 'status_label', 'check_in_id', 'check_in_time', 'check_out_time',
            'check_in_location_review', 'check_out_location_review',
            'work_hours', 'work_hours_formatted', 'leave_type', 'rejection', 'correction', 'excuse',
        ], array_keys($data['days'][0]));
        $this->assertSame(0, $data['days'][0]['weekday'], '21 June 2026 is a Sunday');
        $this->assertSame([
            'expected_days', 'present_days', 'on_leave_days', 'excused_days', 'excused_deducted_days', 'excused_not_deducted_days',
            'absent_days', 'rejected_days', 'off_days', 'missing_checkouts', 'total_work_hours', 'total_work_hours_formatted',
            'attendance_rate',
        ], array_keys($data['summary']));
    }

    /** One place for the precedence: what HR's grid shows for this person, byte for byte. */
    public function test_it_is_exactly_what_hrs_grid_shows_for_the_caller(): void
    {
        $a = $this->people['a'];
        $this->checkIn($a, '2026-06-21 08:00:00');
        $this->checkOut($a, '2026-06-21 16:30:00', ['work_hours' => 8.5]);
        $this->checkIn($a, '2026-06-22 08:00:00', ['rejected_at' => now(), 'rejection_reason' => RejectionReason::Location]);
        $this->grantLeave($a, '2026-06-23', '2026-06-23', excuse: true, deducts: false);
        $this->grantLeave($a, '2026-06-25', '2026-06-26');

        $mine = $this->days(['month' => 6, 'year' => 2026])->assertOk()->json('data');

        $this->actingAsScopedUser(['attendance.records.view'], ScopeType::Company);
        $hrs = $this->getJson("/api/attendance/report/employee?employee_id={$a->id}&month=6&year=2026")->assertOk()->json('data');

        $this->assertSame($hrs, $mine);
    }

    /**
     * The grid's day filters are HR's; the calendar wants every day, so
     * `status`, `working_days_only` and `sort` are not read here.
     */
    public function test_the_grids_day_filters_are_not_offered(): void
    {
        $this->checkIn($this->people['a'], '2026-06-21 08:00:00');

        $this->days(['from' => '2026-06-21', 'to' => '2026-06-27', 'status' => AttendanceDayState::CheckedIn->value, 'sort' => '-date'])
            ->assertOk()
            ->assertJsonCount(7, 'data.days')
            ->assertJsonPath('data.days.0.date', '2026-06-21');
    }

    public function test_an_account_with_no_employee_is_refused(): void
    {
        $this->actingAsRolelessUser();

        $this->getJson('/api/me/attendance/days?month=6&year=2026')->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'لا يوجد موظف مرتبط بهذا الحساب.');
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/me/attendance/days?month=6&year=2026')->assertUnauthorized();
    }
}
