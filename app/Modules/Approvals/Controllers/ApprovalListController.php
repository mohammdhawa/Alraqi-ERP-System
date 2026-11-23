<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Controllers;

use App\Modules\Approvals\Contracts\RestrictsApprovalVisibility;
use App\Modules\Approvals\Models\Approval;
use App\Modules\Approvals\Requests\ApprovalsIndexRequest;
use App\Modules\Approvals\Resources\ApprovalListResource;
use App\Modules\Approvals\Support\ApprovalFilters;
use App\Modules\Approvals\Support\ApprovalRelations;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Shared\Support\RequestRowScopes;
use App\Shared\Traits\ApiRespond;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * GET /api/approvals — HR's list of every approval (owner, 2026-10-20,
 * parity audit C-4), behind the dashboard's menu of all requests.
 *
 * Reach: `approvals.view` (the route's `permission:`), narrowed to the
 * requesters whose CURRENT unit it covers — the reading ApprovalPolicy gives
 * one approval (§9 Q5, D6). Every subject type; every status by default.
 */
class ApprovalListController extends Controller
{
    use ApiRespond;

    public function index(ApprovalsIndexRequest $request): JsonResponse
    {
        $scope = RequestRowScopes::for($request, 'approvals.view');
        RequestRowScopes::warm($request, ['approvals.reassign']);

        $approvals = Approval::query();

        if (! $scope->isUnrestricted()) {
            $approvals->whereIn('requested_by', $scope->applyTo(Employee::withTrashed()->select('id')));
        }

        $hidden = $this->hiddenSubjectTypes($request->user());
        if ($hidden !== []) {
            $approvals->whereNotIn('approvable_type', $hidden);
        }

        $statuses = $request->statuses();
        if ($statuses !== []) {
            $approvals->whereIn('status', $statuses);
        }

        ApprovalFilters::apply($approvals, $request->filters());
        $this->sort($approvals, $request->validated('sort') ?? '-submitted_at');

        return $this->paginated(ApprovalListResource::collection(
            $approvals->with(ApprovalRelations::list())->paginate($this->perPage($request)),
        ));
    }

    /**
     * The morph aliases whose chains this user may not read from HR's list
     * (RestrictsApprovalVisibility). A party reads their own on the subject's
     * screens, not here.
     *
     * @return array<int, string>
     */
    private function hiddenSubjectTypes(User $user): array
    {
        return collect(Relation::morphMap())
            ->filter(static fn (string $class): bool => is_a($class, RestrictsApprovalVisibility::class, true)
                && ! $class::mayReadApprovalsWithoutBeingParty($user))
            ->keys()
            ->map(static fn (int|string $alias): string => (string) $alias)
            ->values()
            ->all();
    }

    /**
     * @param  Builder<Approval>  $approvals
     */
    private function sort(Builder $approvals, string $sort): void
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

        match (ltrim($sort, '-')) {
            'decided_at'    => $approvals->orderBy('approvals.decided_at', $direction),
            'employee_name' => $approvals
                ->orderBy(Employee::withTrashed()->select('first_name')->whereColumn('employees.id', 'approvals.requested_by'), $direction)
                ->orderBy(Employee::withTrashed()->select('last_name')->whereColumn('employees.id', 'approvals.requested_by'), $direction),
            default         => null,
        };

        // The id is the filing order, and the tiebreaker.
        $approvals->orderBy('approvals.id', $direction);
    }
}
