<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Modules\Approvals\Enums\Decision;
use App\Modules\Approvals\Enums\RejectionPolicy;
use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Approvals\Services\ApprovalInbox;
use App\Modules\Approvals\Support\ApprovalRoute;
use App\Modules\Approvals\Support\StepDefinition;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Approvals\Concerns\ApprovalFixtures;
use Tests\TestCase;

/**
 * What waits on an approver (design §7, the inbox both clients read): steps
 * they can act on NOW. A later step on a chain arrives when its turn comes —
 * the queue never shows work nobody can do yet, or work a rejection closed.
 */
class ApprovalInboxTest extends TestCase
{
    use ApprovalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApprovals();
    }

    protected function tearDown(): void
    {
        $this->tearDownApprovals();
        parent::tearDown();
    }

    /**
     * @return array<int, int> step ids
     */
    private function pending(Employee|User $who): array
    {
        $user = $who instanceof User ? $who : $this->accountOf($who);

        return app(ApprovalInbox::class)->pendingFor($user)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return array<int, int> step ids
     */
    private function decided(Employee $who): array
    {
        return app(ApprovalInbox::class)->decidedBy($this->accountOf($who))->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_a_step_reaches_the_inbox_when_its_turn_comes_and_leaves_when_decided(): void
    {
        $approval = $this->submit($this->people['staff']);
        $first = $this->stepOf($approval, $this->people['headA']);
        $second = $this->stepOf($approval, $this->people['hrHead']);

        $this->assertSame([$first->id], $this->pending($this->people['headA']));
        $this->assertSame([], $this->pending($this->people['hrHead']), 'not yet their turn');

        $this->decide($approval, $this->people['headA']);

        $this->assertSame([], $this->pending($this->people['headA']));
        $this->assertSame([$first->id], $this->decided($this->people['headA']));
        $this->assertSame([$second->id], $this->pending($this->people['hrHead']));

        $this->decide($approval, $this->people['hrHead'], Decision::Reject);
        $this->assertSame([], $this->pending($this->people['hrHead']));
        $this->assertSame([$second->id], $this->decided($this->people['hrHead']));
    }

    public function test_steps_closed_by_a_rejection_never_reach_the_inbox(): void
    {
        [$first, $second] = [$this->person(), $this->person()];
        $approval = $this->submit($this->people['staff'], $this->namedRoute(RejectionPolicy::Ends, $first, $second));

        $this->decide($approval, $first, Decision::Reject);

        $this->assertSame([], $this->pending($second));
        $this->assertSame([], $this->decided($second), 'a closed step is not a decision of theirs');
    }

    public function test_parallel_steps_are_all_in_their_approvers_inboxes(): void
    {
        [$m1, $m2, $chief] = [$this->person(), $this->person(), $this->person()];
        $approval = $this->submit($this->people['staff'], ApprovalRoute::stages(
            RejectionPolicy::Advisory,
            [StepDefinition::namedEmployee($m1->id), StepDefinition::namedEmployee($m2->id)],
            [StepDefinition::namedEmployee($chief->id)->decisive()],
        ));

        $this->assertCount(1, $this->pending($m1));
        $this->assertCount(1, $this->pending($m2));
        $this->assertSame([], $this->pending($chief));

        $this->decide($approval, $m1);
        $this->assertCount(1, $this->pending($m2), 'the other parallel step is still waiting');
        $this->assertSame([], $this->pending($chief));

        $this->decide($approval, $m2);
        $this->assertCount(1, $this->pending($chief));
    }

    public function test_newest_first_across_requests(): void
    {
        $older = $this->submit($this->people['staff']);
        $newer = $this->submit($this->person());

        $this->assertSame(
            [$this->stepOf($newer, $this->people['headA'])->id, $this->stepOf($older, $this->people['headA'])->id],
            $this->pending($this->people['headA']),
        );
    }

    public function test_an_account_with_no_employee_has_an_empty_inbox(): void
    {
        $this->submit($this->people['staff']);

        $this->assertSame([], $this->pending(User::factory()->create()));
        $this->assertGreaterThan(0, ApprovalStep::count());
    }
}
