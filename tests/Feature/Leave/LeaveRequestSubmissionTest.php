<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Modules\Approvals\Models\Approval;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveRequest;
use App\Shared\Models\Attachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Leave\Concerns\LeaveFixtures;
use Tests\TestCase;

/**
 * Filing a leave request — legacy's LeaveRequestSubmissionTest, on the
 * resolved chain (LEAVE_BUILD.md §4). No approvers in the payload (Q1); a
 * sick request carries its medical report (Q3, the owner's answer); a chain
 * that cannot resolve refuses the filing and leaves nothing behind.
 */
class LeaveRequestSubmissionTest extends TestCase
{
    use LeaveFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildLeaveTree();
        $this->actingAsPerson($this->people['staff']);
    }

    private function fileLeave(string $type = 'annual', array $body = [], array $files = []): TestResponse
    {
        return $this->post('/api/me/leave-requests', $body + [
            'leave_type_id' => $this->type($type)->id,
            'start_date'    => '2026-06-21',
            'end_date'      => '2026-06-23',
            'attachments'   => $files,
        ], ['Accept' => 'application/json']);
    }

    private function pdf(string $name = 'تقرير.pdf', int $kb = 200): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, 'application/pdf');
    }

    private function existing(string $status, string $start = '2026-06-22', string $end = '2026-06-25'): void
    {
        LeaveRequest::factory()->forType('annual')->create([
            'employee_id' => $this->people['staff']->id, 'status' => LeaveStatus::from((int) $status), 'start_date' => $start, 'end_date' => $end,
        ]);
    }

    private function assertNothingSaved(): void
    {
        $this->assertSame(0, LeaveRequest::count());
        $this->assertSame(0, Approval::count());
        $this->assertSame(0, Attachment::count());
        $this->assertSame([], Storage::disk('local')->allFiles('attachments'));
    }

    // ── Filing ───────────────────────────────────────────────────────────

    public function test_an_employee_files_annual_leave_on_the_resolved_chain(): void
    {
        $this->fileLeave()
            ->assertCreated()
            ->assertJsonPath('message', 'تم تقديم طلب الإجازة.')
            ->assertJsonPath('data.leave_request.employee_id', $this->people['staff']->id)
            ->assertJsonPath('data.leave_request.leave_type.code', 'annual')
            ->assertJsonPath('data.leave_request.requested_days', 3)
            ->assertJsonPath('data.leave_request.deducts_balance', true)
            ->assertJsonPath('data.leave_request.status', LeaveStatus::Pending->value)
            ->assertJsonPath('data.leave_request.status_label', 'قيد الاعتماد')
            ->assertJsonPath('data.leave_request.is_excuse', false)
            ->assertJsonPath('data.leave_request.approval.steps.0.approver.id', $this->people['headA']->id)
            ->assertJsonPath('data.leave_request.approval.steps.1.approver.id', $this->people['hrHead']->id)
            ->assertJsonPath('data.leave_request.approval.can_act', false)
            ->assertJsonPath('data.leave_request.approval.can_withdraw', true)
            ->assertJsonPath('data.balance.remaining_days', 15);
    }

    public function test_approvers_in_the_payload_are_ignored(): void
    {
        $this->fileLeave(body: ['approver_ids' => [$this->people['gm']->id], 'manager_id' => $this->people['gm']->id])
            ->assertCreated()
            ->assertJsonPath('data.leave_request.approval.steps.0.approver.id', $this->people['headA']->id);
    }

    public function test_the_deducting_flag_is_the_types_at_filing(): void
    {
        $this->fileLeave()->assertCreated();
        $this->type('annual')->update(['deducts_balance' => false]);

        $this->assertTrue(LeaveRequest::sole()->deducts_balance);
    }

    // ── The type ─────────────────────────────────────────────────────────

    public function test_the_type_is_required_active_and_for_requests(): void
    {
        $this->post('/api/me/leave-requests', ['start_date' => '2026-06-21', 'end_date' => '2026-06-21'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('leave_type_id');

        $this->fileLeave('official_mission')
            ->assertStatus(422)
            ->assertJsonPath('errors.leave_type_id.0', 'نوع الإجازة (مأمورية رسمية) غير متاح لطلبات الموظفين.');

        $this->type('emergency')->update(['is_active' => false]);
        $this->fileLeave('emergency')->assertStatus(422)->assertJsonValidationErrors('leave_type_id');

        $this->post('/api/me/leave-requests', ['leave_type_id' => 999999, 'start_date' => '2026-06-21', 'end_date' => '2026-06-21'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('leave_type_id');
    }

    public function test_a_type_requiring_a_reason_is_refused_without_one(): void
    {
        $this->fileLeave('unpaid')
            ->assertStatus(422)
            ->assertJsonPath('errors.reason.0', 'نوع الإجازة (إجازة بدون راتب) يتطلب ذكر السبب.');

        $this->fileLeave('unpaid', ['reason' => 'ظرف عائلي'])->assertCreated();
    }

    // ── Attachments ──────────────────────────────────────────────────────

    public function test_sick_leave_needs_its_medical_report(): void
    {
        $this->fileLeave('sick', ['reason' => 'زكام'])
            ->assertStatus(422)
            ->assertJsonPath('errors.attachments.0', 'نوع الإجازة (إجازة مرضية) يتطلب إرفاق مستند.');

        $this->assertNothingSaved();
    }

    public function test_sick_leave_with_its_report_is_filed_and_the_files_kept_privately(): void
    {
        $response = $this->fileLeave('sick', ['reason' => 'زكام'], [$this->pdf('صفحة 1.pdf'), UploadedFile::fake()->create('صفحة 2.png', 80, 'image/png')])
            ->assertCreated()
            ->assertJsonCount(2, 'data.leave_request.attachments')
            ->assertJsonPath('data.leave_request.attachments.0.name', 'صفحة 1.pdf')
            ->assertJsonPath('data.leave_request.attachments.0.mime_type', 'application/pdf')
            ->assertJsonPath('data.leave_request.deducts_balance', false);

        $this->assertArrayNotHasKey('path', $response->json('data.leave_request.attachments.0'));
        $this->assertCount(2, Storage::disk('local')->allFiles('attachments/leave_request/'.LeaveRequest::sole()->id));
    }

    public function test_any_type_may_carry_files(): void
    {
        $this->fileLeave(files: [$this->pdf()])->assertCreated()->assertJsonCount(1, 'data.leave_request.attachments');
    }

    public function test_files_are_bounded_in_number_kind_and_size(): void
    {
        $this->fileLeave(files: array_map(fn (int $i) => $this->pdf("{$i}.pdf"), range(1, 6)))
            ->assertStatus(422)->assertJsonValidationErrors('attachments');
        $this->fileLeave(files: [UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')])
            ->assertStatus(422)->assertJsonValidationErrors('attachments.0');
        $this->fileLeave(files: [$this->pdf('big.pdf', 11 * 1024)])
            ->assertStatus(422)->assertJsonValidationErrors('attachments.0');

        $this->assertNothingSaved();
    }

    // ── Days and the balance ─────────────────────────────────────────────

    public function test_a_span_with_no_working_day_is_refused(): void
    {
        // 2026-06-26 is a Friday, 06-27 a Saturday.
        config(['attendance.working_days' => [0, 1, 2, 3, 4]]);

        $this->fileLeave(body: ['start_date' => '2026-06-26', 'end_date' => '2026-06-27'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'الفترة المحددة لا تتضمن أيام عمل.');
    }

    public function test_requested_days_are_working_days(): void
    {
        config(['attendance.working_days' => [0, 1, 2, 3, 4]]);

        // Thursday 25 → Sunday 28: Thursday and Sunday.
        $this->fileLeave(body: ['start_date' => '2026-06-25', 'end_date' => '2026-06-28'])
            ->assertCreated()
            ->assertJsonPath('data.leave_request.requested_days', 2);
    }

    public function test_a_deducting_request_may_not_exceed_the_remaining_balance(): void
    {
        LeaveBalance::create(['employee_id' => $this->people['staff']->id, 'year' => 2026, 'allocated_days' => 2, 'adjustment_days' => 0]);

        $this->fileLeave()
            ->assertStatus(422)
            ->assertJsonPath('message', 'طلب الإجازة يتجاوز رصيد الإجازات المتبقي (المتبقي 2 يوم).');
        $this->assertNothingSaved();
    }

    public function test_a_non_deducting_request_is_never_blocked_by_the_balance(): void
    {
        LeaveBalance::create(['employee_id' => $this->people['staff']->id, 'year' => 2026, 'allocated_days' => 0, 'adjustment_days' => 0]);

        $this->fileLeave('sick', ['reason' => 'زكام'], [$this->pdf()])->assertCreated();
    }

    public function test_the_end_is_not_before_the_start(): void
    {
        $this->fileLeave(body: ['start_date' => '2026-06-23', 'end_date' => '2026-06-21'])
            ->assertStatus(422)->assertJsonValidationErrors('end_date');
    }

    // ── Overlap ──────────────────────────────────────────────────────────

    public function test_it_may_not_overlap_a_pending_or_approved_leave(): void
    {
        $this->existing((string) LeaveStatus::Pending->value);
        $this->fileLeave()->assertStatus(422)->assertJsonPath('message', 'توجد إجازة قيد الاعتماد أو معتمدة تتداخل مع هذه الفترة.');

        LeaveRequest::query()->update(['status' => LeaveStatus::Approved]);
        $this->fileLeave()->assertStatus(422);

        LeaveRequest::query()->update(['status' => LeaveStatus::Rejected]);
        $this->fileLeave()->assertCreated();
    }

    public function test_a_colleagues_leave_over_the_same_days_does_not_block(): void
    {
        LeaveRequest::factory()->forType('annual')->create(['employee_id' => $this->people['bStaff']->id, 'start_date' => '2026-06-21', 'end_date' => '2026-06-23']);

        $this->fileLeave()->assertCreated();
    }

    // ── A chain that cannot resolve ──────────────────────────────────────

    public function test_a_vacant_hr_post_refuses_the_filing_and_leaves_nothing_behind(): void
    {
        $this->units['hr']->update(['manager_id' => null]);

        $this->fileLeave(files: [$this->pdf()])
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن تقديم الطلب: منصب مدير «إدارة الموارد البشرية» شاغر.');

        $this->assertNothingSaved();
    }

    public function test_an_approver_without_an_active_account_refuses_the_filing(): void
    {
        $this->accountOf($this->people['headA'])->update(['is_active' => false]);

        $this->fileLeave(files: [$this->pdf()])->assertStatus(422);

        $this->assertNothingSaved();
    }

    public function test_an_account_with_no_employee_cannot_file(): void
    {
        $this->actingAsRolelessUser();

        $this->fileLeave()->assertForbidden()->assertJsonPath('message', 'لا يوجد موظف مرتبط بهذا الحساب.');
    }
}
