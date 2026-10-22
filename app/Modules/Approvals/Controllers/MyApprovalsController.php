<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Controllers;

use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Approvals\Requests\MyApprovalsRequest;
use App\Modules\Approvals\Resources\ApprovalInboxResource;
use App\Modules\Approvals\Services\ApprovalInbox;
use App\Modules\Approvals\Support\ApprovalFilters;
use App\Modules\Approvals\Support\ApprovalRelations;
use App\Shared\Traits\ApiRespond;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/me/approvals — the approver's inbox (design §7), routed under
 * /api/me because it is the caller's own: their steps, found from their
 * employee, never from an id in the request.
 *
 * Filtered and sorted as legacy's queue was (parity audit C-4): the filters
 * narrow the steps' approvals; a sort replaces the inbox's own order.
 */
class MyApprovalsController extends Controller
{
    use ApiRespond;

    public function index(MyApprovalsRequest $request, ApprovalInbox $inbox): JsonResponse
    {
        $user = $request->user();
        $steps = $request->wantsDecided() ? $inbox->decidedBy($user) : $inbox->pendingFor($user);

        $filters = $request->filters();
        if ($filters !== []) {
            $steps->whereHas('approval', static fn (Builder $approvals) => ApprovalFilters::apply($approvals, $filters));
        }

        $sort = $request->validated('sort');
        if ($sort !== null) {
            $this->sort($steps, $sort);
        }

        return $this->paginated(ApprovalInboxResource::collection(
            $steps->with(ApprovalRelations::inbox())->paginate($this->perPage($request)),
        ));
    }

    /**
     * @param  Builder<ApprovalStep>  $steps
     */
    private function sort(Builder $steps, string $sort): void
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $steps->reorder();

        if (ltrim($sort, '-') === 'employee_name') {
            foreach (['first_name', 'last_name'] as $column) {
                $steps->orderBy(
                    DB::table('approvals')
                        ->join('employees', 'employees.id', '=', 'approvals.requested_by')
                        ->whereColumn('approvals.id', 'approval_steps.approval_id')
                        ->select("employees.{$column}"),
                    $direction,
                );
            }
        }

        // Approvals are numbered in the order they were filed, so the id is
        // the filing order — and the tiebreaker for a name sort.
        $steps->orderBy('approval_steps.approval_id', $direction)->orderBy('approval_steps.id', $direction);
    }
}
