<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AttendanceDayState;
use App\Modules\Attendance\Enums\AttendanceRecordStatus;
use App\Modules\Attendance\Enums\RejectionReason;
use App\Modules\Auth\Enums\ScopeType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Attendance\Concerns\AttendanceTree;
use Tests\Feature\Attendance\Concerns\FakesApprovedLeave;
use Tests\TestCase;

/**
 * GET /api/attendance/report — the daily board (legacy's /attendance/report and
 * the report half of its AttendanceFilterAndReportTest and
 * AttendanceRejectionTest).
 *
 * Seven sections for one day: checked in (not out yet), checked out, present
 * (both), missing check-out, on leave, excused, and rejected — a refused day
 * the employee did not put right. The sections are disjoint, so every person
 * appears once and the summary adds up.
 */
class AttendanceDailyReportTest extends TestCase
{
    use AttendanceTree;
    use FakesApprovedLeave;
    use RefreshDatabase;

    private const DAY = '2026-06-21';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::DAY.' 18:00:00');
        $this->buildTree();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $query */
    private function report(array $query = []): TestResponse
    {
        return $this->getJson('/api/attendance/report?'.http_build_query($query));
    }

    /** @return array<int, int> */
    private function employeesIn(TestResponse $response, string $section): array
    {
        return array_map(static fn (array $row): int => $row['employee']['id'], $response->json("data.{$section}"));
    }

    private function asHr(): void
    {
        $this->actingAsScopedUser(['attendance.records.view'], ScopeType::Company);
    }

    public function test_the_day_is_split_into_its_sections(): void
    {
        $this->checkIn($this->people['a'], self::DAY.' 09:00:00');                      // still in
        $this->checkIn($this->people['a1'], self::DAY.' 08:00:00');
        $this->checkOut($this->people['a1'], self::DAY.' 16:00:00');                    // completed
        $this->checkIn($this->people['b'], self::DAY.' 07:00:00', ['status' => AttendanceRecordStatus::MissingCheckout]);
        $this->asHr();

        $response = $this->report()->assertOk()
            ->assertJsonPath('data.date', self::DAY)
            ->assertJsonPath('data.working_day', true);

        $this->assertSame([$this->people['a']->id], $this->employeesIn($response, 'checked_in'));
        $this->assertSame([$this->people['a1']->id], $this->employeesIn($response, 'checked_out'));
        $this->assertSame([$this->people['b']->id], $this->employeesIn($response, 'missing_checkout'));
        $this->assertSame([$this->people['a1']->id, $this->people['a']->id], $this->employeesIn($response, 'present'), 'present = in + out, by check-in time');
        $this->assertSame(
            ['checked_in' => 1, 'checked_out' => 1, 'present' => 2, 'on_leave' => 0, 'excused' => 0, 'missing_checkout' => 1, 'rejected' => 0],
            $response->json('data.summary'),
        );
    }

    public function test_a_row_carries_what_the_board_shows_and_the_ids_it_acts_on(): void
    {
        $in = $this->checkIn($this->people['a1'], self::DAY.' 08:00:00');
        $out = $this->checkOut($this->people['a1'], self::DAY.' 16:30:00', ['work_hours' => 8.5]);
        $this->asHr();

        $row = $this->report()->json('data.checked_out.0');

        $this->assertSame($in->id, $row['record_id']);
        $this->assertSame($out->id, $row['check_out_record_id']);
        $this->assertSame(['id' => $this->units['a1']->id, 'name' => 'قسم أ-1'], $row['department']);
        $this->assertSame(AttendanceDayState::CheckedOut->value, $row['status']);
        $this->assertSame('2026-06-21T08:00:00+03:00', $row['check_in_time']);
        $this->assertSame('2026-06-21T16:30:00+03:00', $row['check_out_time']);
        $this->assertSame('8h 30m', $row['work_hours_formatted']);
        $this->assertSame(['latitude' => 33.5, 'longitude' => 36.3], $row['check_in_location']);
        $this->assertFalse($row['check_in_has_selfie']);
        $this->assertNull($row['correction']);
        $this->assertNull($row['rejection']);
    }

    public function test_a_corrected_check_out_says_who_corrected_it(): void
    {
        $corrector = $this->actingAsScopedUser(['attendance.records.view'], ScopeType::Company);
        $this->checkIn($this->people['a'], self::DAY.' 08:00:00');
        $this->checkOut($this->people['a'], self::DAY.' 16:00:00', ['corrected_by' => $corrector->id, 'corrected_at' => now(), 'correction_note' => 'نسي']);

        $correction = $this->report()->json('data.checked_out.0.correction');

        $this->assertSame($corrector->id, $correction['by']['id']);
        $this->assertSame('نسي', $correction['note']);
    }

    // ── Leave and excuses ────────────────────────────────────────────────

    public function test_planned_leave_and_excuses_list_apart(): void
    {
        $this->grantLeave($this->people['a'], '2026-06-20', '2026-06-22');
        $this->grantLeave($this->people['b'], self::DAY, self::DAY, excuse: true, deducts: false, type: 'external_task', typeName: 'مهمة خارجية');
        $this->asHr();

        $response = $this->report()->assertOk();

        $this->assertSame([$this->people['a']->id], $this->employeesIn($response, 'on_leave'));
        $this->assertSame([$this->people['b']->id], $this->employeesIn($response, 'excused'));

        $leave = $response->json('data.on_leave.0');
        $this->assertSame(AttendanceDayState::OnLeave->value, $leave['status']);
        $this->assertSame('2026-06-20', $leave['start_date']);
        $this->assertSame('المدير المباشر', $leave['approved_by']);
        $this->assertNull($leave['excuse']);

        $excuse = $response->json('data.excused.0');
        $this->assertSame(AttendanceDayState::Excused->value, $excuse['status']);
        $this->assertFalse($excuse['deducts_balance']);
        $this->assertSame('مهمة خارجية', $excuse['excuse']['leave_type_name']);
        $this->assertNull($excuse['approved_by']);
    }

    // ── Refused days ─────────────────────────────────────────────────────

    public function test_a_refused_day_leaves_the_present_sections_and_lands_in_rejected(): void
    {
        $batch = (string) Str::uuid();
        $refusal = ['rejected_at' => now(), 'rejection_reason' => RejectionReason::Location, 'rejection_batch' => $batch];
        $in = $this->checkIn($this->people['a'], self::DAY.' 08:00:00', $refusal);
        $out = $this->checkOut($this->people['a'], self::DAY.' 16:00:00', $refusal);
        $this->asHr();

        $response = $this->report();

        $this->assertSame([], $this->employeesIn($response, 'present'));
        $row = $response->json('data.rejected.0');
        $this->assertSame($in->id, $row['record_id']);
        $this->assertSame($out->id, $row['check_out_record_id'], 'the check-out of the same decision');
        $this->assertSame(AttendanceDayState::Rejected->value, $row['status']);
        $this->assertSame(RejectionReason::Location->value, $row['rejection']['reason']);
        $this->assertSame(1, $response->json('data.summary.rejected'));
    }

    public function test_a_day_recorded_again_after_a_refusal_counts_as_present_only(): void
    {
        $this->checkIn($this->people['a'], self::DAY.' 08:00:00', ['rejected_at' => now(), 'rejection_reason' => RejectionReason::Selfie]);
        $this->checkIn($this->people['a'], self::DAY.' 09:00:00');
        $this->asHr();

        $response = $this->report();

        $this->assertSame([$this->people['a']->id], $this->employeesIn($response, 'present'));
        $this->assertSame([], $this->employeesIn($response, 'rejected'));
    }

    public function test_a_refused_day_covered_by_leave_is_leave_not_a_refusal(): void
    {
        $this->checkIn($this->people['a'], self::DAY.' 08:00:00', ['rejected_at' => now(), 'rejection_reason' => RejectionReason::Other]);
        $this->grantLeave($this->people['a'], self::DAY, self::DAY);
        $this->asHr();

        $response = $this->report();

        $this->assertSame([$this->people['a']->id], $this->employeesIn($response, 'on_leave'));
        $this->assertSame([], $this->employeesIn($response, 'rejected'));
    }

    public function test_a_refused_day_on_a_day_off_is_not_listed(): void
    {
        config(['attendance.working_days' => [1, 2, 3, 4, 5, 6]]);   // Sunday off
        $this->checkIn($this->people['a'], self::DAY.' 08:00:00', ['rejected_at' => now(), 'rejection_reason' => RejectionReason::Other]);
        $this->asHr();

        $this->report()->assertJsonPath('data.working_day', false)->assertJsonPath('data.summary.rejected', 0);
    }

    // ── Reach, filters, order ────────────────────────────────────────────

    public function test_a_unit_grant_sees_its_subtree_in_every_section(): void
    {
        foreach (['a', 'a1', 'b'] as $key) {
            $this->checkIn($this->people[$key], self::DAY.' 08:00:00');
        }
        $this->grantLeave($this->people['b'], self::DAY, self::DAY);
        $this->actingAsScopedUser(['attendance.records.view'], ScopeType::Unit, $this->units['a']->id);

        $response = $this->report();

        $this->assertEqualsCanonicalizing([$this->people['a']->id, $this->people['a1']->id], $this->employeesIn($response, 'present'));
        $this->assertSame([], $this->employeesIn($response, 'on_leave'));
    }

    public function test_the_filters_apply_to_every_section(): void
    {
        $this->checkIn($this->people['a'], self::DAY.' 08:00:00');
        $this->checkIn($this->people['b'], self::DAY.' 08:00:00');
        $this->grantLeave($this->people['a1'], self::DAY, self::DAY);
        $this->grantLeave($this->people['b'], '2026-06-01', '2026-06-01');   // another day: not today
        $this->asHr();

        $response = $this->report(['department_id' => $this->units['a']->id]);

        $this->assertSame([$this->people['a']->id], $this->employeesIn($response, 'present'));
        $this->assertSame([$this->people['a1']->id], $this->employeesIn($response, 'on_leave'));
        $this->assertSame([$this->people['b']->id], $this->employeesIn($this->report(['search' => $this->people['b']->employee_number]), 'present'));
    }

    public function test_a_date_and_a_sort(): void
    {
        $this->checkIn($this->people['a'], '2026-06-20 08:00:00');
        $this->checkOut($this->people['a'], '2026-06-20 12:00:00', ['work_hours' => 4.0]);
        $this->checkIn($this->people['b'], '2026-06-20 08:00:00');
        $this->checkOut($this->people['b'], '2026-06-20 17:00:00', ['work_hours' => 9.0]);
        $this->asHr();

        $this->report()->assertJsonPath('data.summary.present', 0);
        $this->assertSame(
            [$this->people['b']->id, $this->people['a']->id],
            $this->employeesIn($this->report(['date' => '2026-06-20', 'sort' => '-work_hours']), 'checked_out'),
        );
    }

    public function test_bad_parameters_are_422_and_no_permission_is_403(): void
    {
        $this->asHr();
        $this->report(['date' => 'yesterday-ish'])->assertStatus(422)->assertJsonValidationErrors('date');
        $this->report(['sort' => 'salary'])->assertStatus(422)->assertJsonValidationErrors('sort');

        $this->actingAsRolelessUser();
        $this->report()->assertForbidden();
    }
}
