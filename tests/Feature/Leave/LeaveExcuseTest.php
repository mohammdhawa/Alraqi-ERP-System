<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Modules\Approvals\Models\Approval;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Models\Notification;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Enums\LeaveOrigin;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveRequest;
use App\Shared\Support\NotificationEventCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Leave\Concerns\LeaveFixtures;
use Tests\TestCase;

/**
 * HR files an excuse for an absence — legacy's LeaveExcuseTest on ERP routes
 * (LEAVE_BUILD.md §6). Approved at once, no chain (Q5); refused on a day the
 * employee attended; a deducting excuse may go past the balance only with
 * `force`. Files are optional for HR (the owner's answer, 2026-10-18).
 */
class LeaveExcuseTest extends TestCase
{
    use LeaveFixtures;
    use RefreshDatabase;

    private const CREATE = ['leave.excuses.create'];

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildLeaveTree();
        $this->hr = $this->actingAsScopedUser(self::CREATE, ScopeType::Company, employeeId: null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function excuse(array $body = [], string $type = 'annual', ?Employee $employee = null): TestResponse
    {
        return $this->post('/api/leave/excuses', $body + [
            'employee_id'   => ($employee ?? $this->people['staff'])->id,
            'leave_type_id' => $this->type($type)->id,
            'start_date'    => '2026-06-21',
            'end_date'      => '2026-06-22',
        ], ['Accept' => 'application/json']);
    }

    private function checkInOn(string $at, array $attributes = []): void
    {
        AttendanceRecord::factory()->checkIn()->create(['employee_id' => $this->people['staff']->id, 'recorded_at' => $at] + $attributes);
    }

    // ── Filing ───────────────────────────────────────────────────────────

    /**
     * Parity audit S-5 (owner, 2026-10-20): an excuse justifies an absence
     * that happened, so no day after today; today itself is allowed.
     */
    public function test_an_excuse_cannot_reach_past_today(): void
    {
        Carbon::setTestNow('2026-06-21 10:00:00');

        $this->excuse()
            ->assertUnprocessable()
            ->assertJsonPath('message', 'لا يمكن تسجيل عذر لأيام لم تأتِ بعد.');

        $this->excuse(['end_date' => '2026-06-21'])->assertCreated();
    }

    public function test_an_excuse_is_approved_at_once_with_no_chain_and_its_author(): void
    {
        $this->excuse(['reason' => 'ظرف طارئ'])
            ->assertCreated()
            ->assertJsonPath('message', 'تم تسجيل العذر.')
            ->assertJsonPath('data.leave_request.status', LeaveStatus::Approved->value)
            ->assertJsonPath('data.leave_request.origin', LeaveOrigin::HrExcuse->value)
            ->assertJsonPath('data.leave_request.is_excuse', true)
            ->assertJsonPath('data.leave_request.approval', null)
            ->assertJsonPath('data.leave_request.created_by.id', $this->hr->id)
            ->assertJsonPath('data.leave_request.requested_days', 2)
            ->assertJsonPath('data.balance.used_days', 2);

        $this->assertSame(0, Approval::count());
        $this->assertNotNull(LeaveRequest::sole()->decided_at);
    }

    public function test_the_employee_is_told_whether_it_was_deducted(): void
    {
        $this->excuse()->assertCreated();
        $this->excuse(['start_date' => '2026-07-01', 'end_date' => '2026-07-01', 'reason' => 'مهمة'], 'official_mission')->assertCreated();

        $bodies = Notification::where('user_id', $this->accountOf($this->people['staff'])->id)
            ->where('type', NotificationEventCatalogue::LEAVE_EXCUSE_FILED)->orderBy('id')->get(['title', 'body']);

        $this->assertSame('تم تسجيل عذر عن غيابك', $bodies[0]->title);
        $this->assertSame('سجّلت إدارة الموارد البشرية (إجازة سنوية) من 2026-06-21 إلى 2026-06-22، وقد تم خصمها من رصيد إجازاتك.', $bodies[0]->body);
        $this->assertSame('سجّلت إدارة الموارد البشرية (مأمورية رسمية) من 2026-07-01 إلى 2026-07-01، ولا تُخصم من رصيد إجازاتك.', $bodies[1]->body);
    }

    // ── The balance ──────────────────────────────────────────────────────

    public function test_a_non_deducting_excuse_costs_nothing_even_with_a_spent_balance(): void
    {
        LeaveBalance::create(['employee_id' => $this->people['staff']->id, 'year' => 2026, 'allocated_days' => 0, 'adjustment_days' => 0]);

        $this->excuse(['reason' => 'مهمة'], 'official_mission')
            ->assertCreated()
            ->assertJsonPath('data.balance.used_days', 0)
            ->assertJsonPath('data.balance.non_deducting_days', 2);
    }

    public function test_a_deducting_excuse_past_the_balance_needs_force(): void
    {
        LeaveBalance::create(['employee_id' => $this->people['staff']->id, 'year' => 2026, 'allocated_days' => 1, 'adjustment_days' => 0]);

        $this->excuse()
            ->assertStatus(422)
            ->assertJsonPath('message', 'العذر يتجاوز رصيد الإجازات المتبقي للموظف (المتبقي 1 يوم). أرسل force لتسجيله رغم ذلك.');

        $this->excuse(['force' => true])
            ->assertCreated()
            ->assertJsonPath('data.balance.remaining_days', 0)
            ->assertJsonPath('data.balance.over_balance_days', 1);
    }

    public function test_editing_the_type_later_leaves_the_excuse_as_filed(): void
    {
        $this->excuse()->assertCreated();
        $this->type('annual')->update(['deducts_balance' => false]);

        $this->assertTrue(LeaveRequest::sole()->deducts_balance);
    }

    // ── There has to be an absence ───────────────────────────────────────

    public function test_a_day_the_employee_attended_cannot_be_excused(): void
    {
        $this->checkInOn('2026-06-22 08:00:00');

        $this->excuse()
            ->assertStatus(422)
            ->assertJsonPath('message', 'للموظف حضور مسجّل في بعض هذه الأيام، فلا يوجد غياب لتبريره: 2026-06-22.');
        $this->excuse(['force' => true])->assertStatus(422);

        $this->assertSame(0, LeaveRequest::count());
    }

    public function test_a_day_with_only_a_refused_check_in_can_be_excused(): void
    {
        $this->checkInOn('2026-06-21 08:00:00', ['rejected_at' => now(), 'rejected_by' => $this->hr->id]);

        $this->excuse()->assertCreated();
    }

    public function test_a_span_with_no_working_day_is_refused(): void
    {
        config(['attendance.working_days' => [0, 1, 2, 3, 4]]);

        $this->excuse(['start_date' => '2026-06-26', 'end_date' => '2026-06-27'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'الفترة المحددة لا تتضمن أيام عمل.');
    }

    public function test_it_may_not_overlap_a_pending_or_approved_leave(): void
    {
        LeaveRequest::factory()->forType('annual')->create(['employee_id' => $this->people['staff']->id, 'start_date' => '2026-06-22', 'end_date' => '2026-06-30']);

        $this->excuse()->assertStatus(422)->assertJsonPath('message', 'توجد إجازة قيد الاعتماد أو معتمدة تتداخل مع هذه الفترة.');
    }

    // ── The type and the body ────────────────────────────────────────────

    public function test_the_type_must_be_active_and_for_excuses_and_is_required(): void
    {
        $this->post('/api/leave/excuses', ['employee_id' => $this->people['staff']->id, 'start_date' => '2026-06-21', 'end_date' => '2026-06-21'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('leave_type_id');

        $this->type('study_leave')->update(['for_excuses' => false]);
        $this->excuse([], 'study_leave')
            ->assertStatus(422)
            ->assertJsonPath('errors.leave_type_id.0', 'نوع الإجازة (إجازة دراسة) غير متاح للأعذار.');

        $this->type('emergency')->update(['is_active' => false]);
        $this->excuse([], 'emergency')->assertStatus(422)->assertJsonValidationErrors('leave_type_id');
    }

    public function test_a_type_requiring_a_reason_needs_one(): void
    {
        $this->excuse([], 'official_mission')
            ->assertStatus(422)
            ->assertJsonPath('errors.reason.0', 'نوع الإجازة (مأمورية رسمية) يتطلب ذكر السبب.');
    }

    public function test_files_are_optional_for_hr_even_on_sick_leave_and_bounded_when_given(): void
    {
        $this->excuse(['reason' => 'تقرير ورقي لدى الموارد'], 'sick')->assertCreated();

        $pdf = fn (int $i) => UploadedFile::fake()->create("{$i}.pdf", 50, 'application/pdf');
        $this->excuse(['start_date' => '2026-07-01', 'end_date' => '2026-07-01', 'reason' => 'تقرير', 'attachments' => [$pdf(1)]], 'sick')
            ->assertCreated()->assertJsonCount(1, 'data.leave_request.attachments');
        $this->excuse(['start_date' => '2026-08-01', 'end_date' => '2026-08-01', 'reason' => 'تقرير', 'attachments' => array_map($pdf, range(1, 6))], 'sick')
            ->assertStatus(422)->assertJsonValidationErrors('attachments');
    }

    public function test_the_employee_must_exist(): void
    {
        $this->excuse(['employee_id' => 999999])->assertStatus(422)->assertJsonValidationErrors('employee_id');
    }

    // ── Who may file ─────────────────────────────────────────────────────

    public function test_a_unit_grant_reaches_only_its_units(): void
    {
        $this->actingAsScopedUser(self::CREATE, ScopeType::Unit, $this->units['a']->id);

        $this->excuse(employee: $this->people['bStaff'])
            ->assertForbidden()
            ->assertJsonPath('message', 'هذا الموظف خارج نطاق صلاحياتك.');
        $this->excuse()->assertCreated();
    }

    public function test_without_the_permission_it_is_refused(): void
    {
        $this->actingAsPerson($this->people['staff']);

        $this->excuse()->assertForbidden()->assertJsonPath('message', 'ليس لديك الصلاحيات الكافية لتنفيذ هذا الإجراء.');
    }

    // ── Where it shows ───────────────────────────────────────────────────

    public function test_the_employee_sees_it_and_can_separate_it_from_their_requests(): void
    {
        $this->excuse()->assertCreated();
        $this->actingAsPerson($this->people['staff']);

        $this->getJson('/api/me/leave-requests?is_excuse=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/me/leave-requests?is_excuse=0')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_attendance_shows_the_day_as_excused(): void
    {
        $this->excuse(['reason' => 'ظرف طارئ'])->assertCreated();
        $this->actingAsScopedUser(['attendance.records.view'], ScopeType::Company);

        $this->getJson('/api/attendance/report?date=2026-06-21')
            ->assertOk()
            ->assertJsonPath('data.excused.0.employee.id', $this->people['staff']->id)
            ->assertJsonPath('data.excused.0.excuse.reason', 'ظرف طارئ');
    }
}
