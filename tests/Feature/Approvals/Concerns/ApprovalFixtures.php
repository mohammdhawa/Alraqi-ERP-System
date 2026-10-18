<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals\Concerns;

use App\Modules\Approvals\Enums\Decision;
use App\Modules\Approvals\Enums\RejectionPolicy;
use App\Modules\Approvals\Models\Approval;
use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Approvals\Services\ApprovalService;
use App\Modules\Approvals\Support\ApprovalRoute;
use App\Modules\Approvals\Support\StepDefinition;
use App\Modules\Auth\Models\Notification;
use App\Modules\Auth\Models\User;
use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Approvals\Fakes\FakeApprovable;

/**
 * The tree the engine tests resolve chains over, and the fake subject.
 *
 *   GM   الإدارة العامة               gm manages it
 *   ├── HR  إدارة الموارد البشرية      hrHead manages it; hrStaff works in it
 *   └── A   إدارة أ                    headA manages it
 *       └── A1  قسم أ-1  (vacant)      staff works in it
 *
 * Leave's route over it (decision round Q1 = B): staff → headA (climbing past
 * the vacant A1) then hrHead. Everyone has an active account.
 */
trait ApprovalFixtures
{
    /** @var array<string, Department> */
    protected array $units = [];

    /** @var array<string, Employee> */
    protected array $people = [];

    protected function setUpApprovals(): void
    {
        Schema::create('fake_approvables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id');
            $table->string('outcome')->nullable();
            $table->unsignedInteger('decided_count')->default(0);
            $table->string('refusal')->nullable();
            $table->timestamps();
        });

        Relation::morphMap([FakeApprovable::MORPH_ALIAS => FakeApprovable::class]);
        FakeApprovable::$failOnDecide = false;

        $this->buildTree();
    }

    /**
     * The morph map is static and outlives the test; leaving the fake in it
     * would make the audit-catalogue tests see an unlabelled alias.
     */
    protected function tearDownApprovals(): void
    {
        $map = Relation::morphMap();
        unset($map[FakeApprovable::MORPH_ALIAS]);
        Relation::morphMap($map, false);
        FakeApprovable::$failOnDecide = false;
    }

    private function buildTree(): void
    {
        $level = static fn (int $index): int => DepartmentLevel::cases()[$index]->value;

        $gm = Department::create(['name' => 'الإدارة العامة', 'code' => 'GM', 'level' => $level(0), 'parent_id' => null]);
        $hr = Department::create(['name' => 'إدارة الموارد البشرية', 'code' => 'HR', 'level' => $level(1), 'parent_id' => $gm->id]);
        $a = Department::create(['name' => 'إدارة أ', 'code' => 'A', 'level' => $level(1), 'parent_id' => $gm->id]);
        $a1 = Department::create(['name' => 'قسم أ-1', 'code' => 'A1', 'level' => $level(2), 'parent_id' => $a->id]);
        $this->units = compact('gm', 'hr', 'a', 'a1');

        foreach (['gm' => $gm, 'hrHead' => $hr, 'hrStaff' => $hr, 'headA' => $a, 'staff' => $a1] as $key => $unit) {
            $this->people[$key] = $this->person($unit);
        }

        $gm->update(['manager_id' => $this->people['gm']->id]);
        $hr->update(['manager_id' => $this->people['hrHead']->id]);
        $a->update(['manager_id' => $this->people['headA']->id]);
    }

    protected function person(?Department $unit = null, bool $withAccount = true): Employee
    {
        $employee = Employee::factory()->create(['department_id' => ($unit ?? $this->units['a1'])->id]);

        if ($withAccount) {
            User::factory()->create(['employee_id' => $employee->id]);
        }

        return $employee;
    }

    protected function accountOf(Employee $employee): User
    {
        return User::where('employee_id', $employee->id)->sole();
    }

    protected function engine(): ApprovalService
    {
        return app(ApprovalService::class);
    }

    protected function subjectFor(Employee $requester, array $attributes = []): FakeApprovable
    {
        return FakeApprovable::create(['employee_id' => $requester->id] + $attributes);
    }

    protected function leaveRoute(): ApprovalRoute
    {
        return ApprovalRoute::sequential(RejectionPolicy::Ends, StepDefinition::directManager(), StepDefinition::unitManager('HR'));
    }

    /**
     * A chain of named people, one per stage.
     */
    protected function namedRoute(RejectionPolicy $policy, Employee ...$approvers): ApprovalRoute
    {
        return ApprovalRoute::sequential($policy, ...array_map(
            static fn (Employee $approver): StepDefinition => StepDefinition::namedEmployee($approver->id),
            $approvers,
        ));
    }

    protected function submit(Employee $requester, ?ApprovalRoute $route = null): Approval
    {
        return $this->engine()->submit($this->subjectFor($requester), $route ?? $this->leaveRoute());
    }

    protected function stepOf(Approval $approval, Employee $approver): ApprovalStep
    {
        return $approval->steps()->where('approver_id', $approver->id)->firstOrFail();
    }

    protected function decide(Approval $approval, Employee $approver, Decision $decision = Decision::Approve, ?string $note = null): Approval
    {
        return $this->engine()->act($this->stepOf($approval, $approver), $this->accountOf($approver), $decision, $note);
    }

    /**
     * @return array<int, string> the notification types this person received
     */
    protected function notificationsOf(Employee $employee): array
    {
        return Notification::where('user_id', $this->accountOf($employee)->id)->orderBy('id')->pluck('type')->all();
    }
}
