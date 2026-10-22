<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Modules\Approvals\Models\Approval;
use App\Modules\Approvals\Models\ApprovalReassignment;
use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Auth\Enums\ScopeType;
use App\Shared\Support\NotificationEventCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Approvals\Concerns\ApprovalFixtures;
use Tests\TestCase;

/**
 * PATCH /api/approvals/{approval}/steps/{step}/assignee — legacy's
 * reassignment, as a scoped grant (§9 Q4 = A): `approvals.reassign`, reaching
 * the requester's current unit. Who the step may go to is the engine's rule
 * (§9 Q7 = A), tested in ApprovalReassignmentTest; here, that its refusals
 * reach the client.
 */
class ApprovalReassignEndpointTest extends TestCase
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

    private function firstStep(?Approval $approval = null): ApprovalStep
    {
        return ($approval ?? $this->approval)->steps()->where('step_order', 1)->sole();
    }

    private function reassign(array $body, ?ApprovalStep $step = null): TestResponse
    {
        $step ??= $this->firstStep();

        return $this->patchJson("/api/approvals/{$this->approval->id}/steps/{$step->id}/assignee", $body);
    }

    private function toGm(): array
    {
        return ['employee_id' => $this->people['gm']->id, 'reason' => 'المدير في إجازة'];
    }

    public function test_hr_hands_a_pending_step_to_someone_else_and_it_is_logged(): void
    {
        $hr = $this->actingAsScopedUser(['approvals.reassign'], ScopeType::Company);

        $this->reassign($this->toGm())
            ->assertOk()
            ->assertJsonPath('message', 'تمت إعادة إسناد الخطوة.')
            ->assertJsonPath('data.steps.0.approver.id', $this->people['gm']->id)
            ->assertJsonPath('data.reassignments.0.from.id', $this->people['headA']->id)
            ->assertJsonPath('data.reassignments.0.performed_by.id', $hr->id)
            ->assertJsonPath('data.reassignments.0.reason', 'المدير في إجازة');

        $this->assertSame(1, ApprovalReassignment::count());
        $this->assertSame([NotificationEventCatalogue::APPROVAL_STEP_PENDING], $this->notificationsOf($this->people['gm']));
    }

    public function test_without_the_permission_it_is_refused(): void
    {
        $this->actingAsScopedUser(['approvals.view'], ScopeType::Company);

        // Refused the VERB, so the plain refusal — not the out-of-reach one,
        // which is for a holder of the verb whose grant stops short.
        $this->reassign($this->toGm())
            ->assertForbidden()
            ->assertJsonPath('message', 'ليس لديك الصلاحيات الكافية لتنفيذ هذا الإجراء.');
        $this->assertSame(0, ApprovalReassignment::count());
    }

    public function test_a_scoped_grant_reaches_only_requests_from_its_units(): void
    {
        $this->actingAsScopedUser(['approvals.reassign'], ScopeType::Unit, $this->units['hr']->id);
        $this->reassign($this->toGm())
            ->assertForbidden()
            ->assertJsonPath('message', 'طلب الاعتماد هذا لموظف خارج نطاق صلاحياتك.');

        $this->actingAsScopedUser(['approvals.reassign'], ScopeType::Unit, $this->units['a']->id);
        $this->reassign($this->toGm())->assertOk();
    }

    public function test_out_of_reach_is_refused_before_the_body_is_read(): void
    {
        $this->actingAsScopedUser(['approvals.reassign'], ScopeType::Unit, $this->units['hr']->id);

        $this->reassign([])->assertForbidden();
    }

    public function test_the_body_is_validated(): void
    {
        $this->actingAsScopedUser(['approvals.reassign'], ScopeType::Company);

        $this->reassign([])->assertStatus(422)->assertJsonValidationErrors(['employee_id', 'reason']);
        $this->reassign(['employee_id' => 999999, 'reason' => 'المدير في إجازة'])
            ->assertStatus(422)->assertJsonValidationErrors('employee_id');
        $this->reassign(['employee_id' => $this->people['gm']->id, 'reason' => 'لا'])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->reassign(['employee_id' => $this->people['gm']->id, 'reason' => str_repeat('س', 501)])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->assertSame(0, ApprovalReassignment::count());
    }

    public function test_a_departed_employee_is_not_a_valid_target(): void
    {
        $this->actingAsScopedUser(['approvals.reassign'], ScopeType::Company);
        $this->people['gm']->delete();

        $this->reassign($this->toGm())->assertStatus(422)->assertJsonValidationErrors('employee_id');
    }

    public function test_the_engines_refusal_reaches_the_client(): void
    {
        $this->actingAsScopedUser(['approvals.reassign'], ScopeType::Company);

        $this->reassign(['employee_id' => $this->people['staff']->id, 'reason' => 'خطأ في المسار'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن إسناد الاعتماد إلى مُقدِّم الطلب نفسه.');
    }

    public function test_a_step_of_another_request_is_not_found(): void
    {
        $other = $this->submit($this->person($this->units['a1']));
        $this->actingAsScopedUser(['approvals.reassign'], ScopeType::Company);

        $this->reassign($this->toGm(), $this->firstStep($other))->assertNotFound();
        $this->assertSame(0, ApprovalReassignment::count());
    }
}
