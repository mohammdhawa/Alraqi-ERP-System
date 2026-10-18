<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Modules\Approvals\Enums\ApprovalStatus;
use App\Modules\Approvals\Enums\Decision;
use App\Modules\Approvals\Enums\SkipReason;
use App\Modules\Approvals\Enums\StepStatus;
use App\Modules\Approvals\Models\Approval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Approvals\Concerns\ApprovalFixtures;
use Tests\Feature\Approvals\Fakes\FakeApprovable;
use Tests\TestCase;

/**
 * POST /api/approvals/{approval}/withdraw — the requester takes back a request
 * nobody has decided yet (§9 Q1 = B; legacy has no withdrawal).
 */
class ApprovalWithdrawEndpointTest extends TestCase
{
    use ApprovalFixtures;
    use RefreshDatabase;

    private Approval $approval;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApprovals();

        $this->approval = $this->submit($this->people['staff']);
    }

    protected function tearDown(): void
    {
        $this->tearDownApprovals();
        parent::tearDown();
    }

    private function withdraw(): TestResponse
    {
        return $this->postJson("/api/approvals/{$this->approval->id}/withdraw");
    }

    public function test_the_requester_withdraws_a_request_nobody_has_decided(): void
    {
        $this->actingAsPerson($this->people['staff']);

        $this->withdraw()
            ->assertOk()
            ->assertJsonPath('message', 'تم سحب الطلب.')
            ->assertJsonPath('data.status', ApprovalStatus::Cancelled->value)
            ->assertJsonPath('data.status_label', 'مسحوب')
            ->assertJsonPath('data.steps.0.status', StepStatus::Skipped->value)
            ->assertJsonPath('data.steps.0.skip_reason', SkipReason::ClosedByCancellation->value)
            ->assertJsonPath('data.steps.1.skip_reason', SkipReason::ClosedByCancellation->value)
            ->assertJsonPath('data.can_withdraw', false);

        $this->assertSame('Cancelled', FakeApprovable::findOrFail($this->approval->approvable_id)->outcome);
    }

    public function test_not_once_an_approver_has_decided(): void
    {
        $this->decide($this->approval, $this->people['headA']);
        $this->actingAsPerson($this->people['staff']);

        $this->withdraw()
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن سحب الطلب بعد أن اتُّخذ فيه قرار.');
    }

    public function test_an_approver_may_not_withdraw_it(): void
    {
        $this->actingAsPerson($this->people['headA']);

        $this->withdraw()
            ->assertForbidden()
            ->assertJsonPath('message', 'لا يمكن سحب طلب لم تقدّمه.');

        $this->assertTrue($this->approval->fresh()->isPending());
    }

    public function test_someone_off_the_chain_is_refused(): void
    {
        $this->actingAsPerson($this->people['gm']);

        $this->withdraw()->assertForbidden();
        $this->assertTrue($this->approval->fresh()->isPending());
    }

    public function test_an_outsider_learns_nothing_of_where_a_request_stands(): void
    {
        // The engine would answer a decided request with a 422 saying it is
        // no longer pending; the route refuses an outsider before that.
        $this->decide($this->approval, $this->people['headA'], Decision::Reject);
        $this->actingAsPerson($this->people['gm']);

        $this->withdraw()
            ->assertForbidden()
            ->assertJsonPath('message', 'ليس لديك صلاحية لتنفيذ هذا الإجراء.');
    }
}
