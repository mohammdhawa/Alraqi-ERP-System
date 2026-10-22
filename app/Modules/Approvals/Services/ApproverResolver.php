<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Services;

use App\Modules\Approvals\Enums\StepResolver;
use App\Modules\Approvals\Exceptions\ApprovalException;
use App\Modules\Approvals\Support\StepDefinition;
use App\Modules\Auth\Models\User;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Shared\Services\ManagementChain;

/**
 * Who a step definition names, at submission (design §4).
 *
 * A step that resolves to NOBODY refuses the whole submission, with a message
 * naming the missing post (§9 Q2 = A) — a request nobody can act on is worse
 * than a clear refusal. Nothing is saved; HR fills the post and the employee
 * submits again.
 *
 * Whether the person found can actually act — an active account — is
 * `ensureCanAct()`, asked separately by the engine, and only of the steps
 * still pending after the skip rules: a step skipped at submission is never
 * acted on, so it needs no account.
 */
class ApproverResolver
{
    public function __construct(
        private readonly ManagementChain $chain,
    ) {}

    /**
     * @throws ApprovalException
     */
    public function resolve(StepDefinition $definition, Employee $requester): Employee
    {
        return match ($definition->resolver) {
            StepResolver::DirectManager => $this->directManager($requester),
            StepResolver::UnitManager   => $this->unitManager((string) $definition->ref),
            StepResolver::NamedEmployee => $this->namedEmployee((int) $definition->ref),
        };
    }

    /**
     * Only an account can press approve, so an approver with no active account
     * refuses the submission exactly as a vacant post does (§9 Q8 = A).
     *
     * @throws ApprovalException
     */
    public function ensureCanAct(Employee $approver): void
    {
        $hasAccount = User::query()
            ->where('employee_id', $approver->getKey())
            ->where('is_active', true)
            ->exists();

        if (! $hasAccount) {
            throw new ApprovalException("لا يمكن تقديم الطلب: المعتمد «{$approver->full_name}» ليس لديه حساب فعّال.");
        }
    }

    /**
     * ManagementChain, as built for decision 15: the unit's manager, past a
     * vacant post or past the requester, ending at المدير العام. Null only
     * when the walk falls off the top — an empty general manager's post, or a
     * requester in no unit.
     */
    private function directManager(Employee $requester): Employee
    {
        $manager = $this->chain->resolve($requester);

        if ($manager !== null) {
            return $manager;
        }

        // The head of the tree — المدير العام — has nobody above them. The
        // step names the requester, and the engine records it skipped with
        // TopOfTree (parity audit C-1, owner 2026-10-20). Anyone else with no
        // manager found is a vacant post, and still refused.
        if ($this->headsTheTree($requester)) {
            return $requester;
        }

        throw new ApprovalException('لا يمكن تقديم الطلب: لا يوجد مدير مباشر في الهيكل التنظيمي لاعتماده.');
    }

    private function headsTheTree(Employee $requester): bool
    {
        return Department::query()
            ->whereNull('parent_id')
            ->where('manager_id', $requester->getKey())
            ->exists();
    }

    /**
     * The manager of a named post. A vacant post does NOT climb — the route
     * named this unit on purpose (leave's مدير الموارد), and someone else's
     * signature in its place would be a different approval.
     */
    private function unitManager(string $code): Employee
    {
        $unit = Department::query()->where('code', $code)->first()
            ?? throw new ApprovalException("لا يمكن تقديم الطلب: الوحدة ذات الرمز «{$code}» غير موجودة.");

        // A pointer at a soft-deleted employee is a vacant post, as in
        // ManagementChain: the global scope leaves them out.
        $manager = $unit->manager_id === null ? null : Employee::query()->find($unit->manager_id);

        return $manager
            ?? throw new ApprovalException("لا يمكن تقديم الطلب: منصب مدير «{$unit->name}» شاغر.");
    }

    private function namedEmployee(int $id): Employee
    {
        return Employee::query()->find($id)
            ?? throw new ApprovalException('لا يمكن تقديم الطلب: المعتمد المحدد غير موجود.');
    }
}
