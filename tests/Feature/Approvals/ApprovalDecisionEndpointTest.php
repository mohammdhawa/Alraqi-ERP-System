<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Modules\Approvals\Enums\ApprovalStatus;
use App\Modules\Approvals\Enums\SkipReason;
use App\Modules\Approvals\Enums\StepStatus;
use App\Modules\Approvals\Models\Approval;
use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Auth\Enums\ScopeType;
use App\Modules\HR\Models\Employee;
use App\Shared\Support\NotificationEventCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Approvals\Concerns\ApprovalFixtures;
use Tests\Feature\Approvals\Fakes\FakeApprovable;
use Tests\TestCase;

/**
 * POST /api/approvals/{approval}/steps/{step}/approve and /reject (design §7).
 *
 * No permission: you act because you are the step's approver (mapping §6).
 * Someone who may not even read the approval is refused at the route (403)
 * before their body is read; someone who may read it but whose turn it is
 * not gets the engine's 422 — being HR does not make it your turn (legacy).
 */
class ApprovalDecisionEndpointTest extends TestCase
{
    use ApprovalFixtures;
    use RefreshDatabase;

    private Approval $approval;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApprovals();

        // staff (A1) → headA → hrHead.
        $this->approval = $this->submit($this->people['staff']);
    }

    protected function tearDown(): void
    {
        $this->tearDownApprovals();
        parent::tearDown();
    }

    private function act(string $verb, Employee|ApprovalStep $step, array $body = [], ?Approval $approval = null): TestResponse
    {
        $approval ??= $this->approval;
        $step = $step instanceof ApprovalStep ? $step : $this->stepOf($approval, $step);

        return $this->postJson("/api/approvals/{$approval->id}/steps/{$step->id}/{$verb}", $body);
    }

    private function subject(?Approval $approval = null): FakeApprovable
    {
        return FakeApprovable::findOrFail(($approval ?? $this->approval)->approvable_id);
    }

    // ── Approving ────────────────────────────────────────────────────────

    public function test_the_current_approver_approves_and_the_chain_moves_on(): void
    {
        $this->actingAsPerson($this->people['headA']);

        $this->act('approve', $this->people['headA'])
            ->assertOk()
            ->assertJsonPath('message', 'تمت الموافقة.')
            ->assertJsonPath('data.status', ApprovalStatus::Pending->value)
            ->assertJsonPath('data.steps.0.status', StepStatus::Approved->value)
            ->assertJsonPath('data.steps.1.is_current', true)
            ->assertJsonPath('data.can_act', false);

        $this->assertContains(NotificationEventCatalogue::APPROVAL_STEP_PENDING, $this->notificationsOf($this->people['hrHead']));
    }

    public function test_the_last_approval_approves_the_request(): void
    {
        $this->actingAsPerson($this->people['headA']);
        $this->act('approve', $this->people['headA'])->assertOk();

        $this->actingAsPerson($this->people['hrHead']);
        $this->act('approve', $this->people['hrHead'], ['note' => 'تمت المراجعة'])
            ->assertOk()
            ->assertJsonPath('data.status', ApprovalStatus::Approved->value)
            ->assertJsonPath('data.steps.1.note', 'تمت المراجعة');

        $this->assertSame('Approved', $this->subject()->outcome);
    }

    // ── Rejecting ────────────────────────────────────────────────────────

    public function test_a_rejection_with_a_note_ends_the_request(): void
    {
        $this->actingAsPerson($this->people['headA']);

        $this->act('reject', $this->people['headA'], ['note' => 'لا يوجد بديل'])
            ->assertOk()
            ->assertJsonPath('message', 'تم الرفض.')
            ->assertJsonPath('data.status', ApprovalStatus::Rejected->value)
            ->assertJsonPath('data.steps.0.status', StepStatus::Rejected->value)
            ->assertJsonPath('data.steps.0.note', 'لا يوجد بديل')
            ->assertJsonPath('data.steps.1.status', StepStatus::Skipped->value)
            ->assertJsonPath('data.steps.1.skip_reason', SkipReason::ClosedByRejection->value);

        $this->assertSame('Rejected', $this->subject()->outcome);
    }

    public function test_a_decided_request_takes_no_more_decisions(): void
    {
        $this->actingAsPerson($this->people['headA']);
        $this->act('reject', $this->people['headA'])->assertOk();

        $this->actingAsPerson($this->people['hrHead']);
        $this->act('approve', $this->people['hrHead'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'يمكن اتخاذ القرار في الطلبات قيد الاعتماد فقط.');
    }

    // ── The note ─────────────────────────────────────────────────────────

    public function test_the_note_is_optional_text_of_at_most_500_characters(): void
    {
        $this->actingAsPerson($this->people['headA']);

        $this->act('approve', $this->people['headA'], ['note' => str_repeat('ب', 501)])
            ->assertStatus(422)->assertJsonValidationErrors('note');
        $this->act('reject', $this->people['headA'], ['note' => ['not', 'text']])
            ->assertStatus(422)->assertJsonValidationErrors('note');

        $this->assertTrue($this->stepOf($this->approval, $this->people['headA'])->isPending());
    }

    // ── Whose turn ───────────────────────────────────────────────────────

    public function test_a_later_approver_is_told_it_is_not_their_turn(): void
    {
        $this->actingAsPerson($this->people['hrHead']);

        $this->act('approve', $this->people['hrHead'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'ليس دورك في اعتماد هذا الطلب.');
    }

    public function test_reading_the_chain_does_not_make_it_your_turn(): void
    {
        $this->actingAsScopedUser(['approvals.view'], ScopeType::Company);

        $this->act('approve', $this->people['headA'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'ليس دورك في اعتماد هذا الطلب.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function verbs(): array
    {
        return ['approve' => ['approve'], 'reject' => ['reject']];
    }

    #[DataProvider('verbs')]
    public function test_someone_off_the_chain_is_refused_before_their_body_is_read(string $verb): void
    {
        $this->actingAsPerson($this->people['gm']);

        // 403 at the route — not the engine's 422 «ليس دورك…», which would
        // tell an outsider the request exists and is pending.
        $this->act($verb, $this->people['headA'], ['note' => ['not', 'text']])
            ->assertForbidden()
            ->assertJsonPath('message', 'ليس لديك صلاحية لتنفيذ هذا الإجراء.');
        $this->assertTrue($this->stepOf($this->approval, $this->people['headA'])->isPending());
    }

    public function test_a_step_of_another_request_is_not_found(): void
    {
        $other = $this->submit($this->person($this->units['a1']));
        $this->actingAsPerson($this->people['headA']);

        $this->act('approve', $this->stepOf($other, $this->people['headA']))->assertNotFound();
        $this->assertTrue($this->stepOf($other, $this->people['headA'])->isPending());
    }

    // ── The final-approval guard ─────────────────────────────────────────

    public function test_the_subjects_refusal_of_the_final_approval_is_a_422_in_its_own_words(): void
    {
        $approval = $this->submit($this->people['hrStaff'], subject: ['refusal' => 'رصيد الإجازة غير كافٍ.']);
        $this->actingAsPerson($this->people['hrHead']);

        $this->act('approve', $this->people['hrHead'], approval: $approval)
            ->assertStatus(422)
            ->assertJsonPath('message', 'رصيد الإجازة غير كافٍ.');

        $this->assertTrue($approval->fresh()->isPending());
    }
}
