<?php

declare(strict_types=1);

namespace App\Modules\Leave\Controllers;

use App\Modules\Attendance\Services\AttendancePopulation;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Exceptions\LeaveException;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Requests\LeaveBalancesRequest;
use App\Modules\Leave\Requests\MyLeaveBalanceRequest;
use App\Modules\Leave\Requests\UpdateLeaveBalanceRequest;
use App\Modules\Leave\Resources\LeaveBalanceResource;
use App\Modules\Leave\Services\LeaveBalances;
use App\Modules\Leave\Support\LeaveReach;
use App\Shared\Support\RequestRowScopes;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Leave balances — legacy's LeaveBalanceController and its `/leave-balance`
 * read (LEAVE_BUILD.md §3).
 *
 * HR's side is row-scoped through the employee's current unit (D6): the list
 * by the builder, the update by LeaveReach before anything is written.
 */
class LeaveBalanceController extends Controller
{
    use ApiRespond;

    /**
     * Client sort key → employees columns, in order (C30).
     */
    private const SORT_COLUMNS = [
        'employee_name'   => ['first_name', 'middle_name', 'last_name'],
        'employee_number' => ['employee_number'],
    ];

    public function __construct(private readonly LeaveBalances $balances) {}

    /**
     * GET /api/me/leave-balance
     */
    public function mine(MyLeaveBalanceRequest $request): JsonResponse
    {
        $employeeId = $request->user()->employee_id
            ?? throw new LeaveException('لا يوجد موظف مرتبط بهذا الحساب.', Response::HTTP_FORBIDDEN);

        return $this->success($this->balances->for((int) $employeeId, $request->year())->toArray());
    }

    /**
     * GET /api/leave/balances — every active employee the caller's
     * `leave.balances.view` reaches.
     */
    public function index(LeaveBalancesRequest $request): JsonResponse
    {
        $year = $request->year();
        $query = RequestRowScopes::for($request, 'leave.balances.view')
            ->applyTo(AttendancePopulation::filter(Employee::query()->with('department'), $request->validated()));

        $sort = $request->validated('sort', 'employee_name');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

        foreach (self::SORT_COLUMNS[ltrim($sort, '-')] as $column) {
            $query->orderBy($column, $direction);
        }

        $page = $query->orderBy('id')->paginate($this->perPage($request));
        $figures = $this->balances->forMany($page->getCollection()->modelKeys(), $year);

        $page->through(static fn (Employee $employee): array => [$employee, $figures[$employee->id]]);

        return $this->paginated(LeaveBalanceResource::collection($page), extra: [
            'year'         => $year,
            'default_days' => (int) config('leave.annual_days', 15),
        ]);
    }

    /**
     * PUT /api/leave/balances/{employee}/{year} — set the allocation and the
     * adjustment (the opening balance at the switch date, Q6). Never below
     * the days already approved that year (legacy): that would leave a
     * negative balance nobody can act on.
     */
    public function update(UpdateLeaveBalanceRequest $request, Employee $employee, int $year): JsonResponse
    {
        LeaveReach::ensure($request->user(), $employee, 'leave.balances.update');

        $allocated = (int) $request->validated('allocated_days');
        $adjustment = (int) $request->validated('adjustment_days', 0);

        DB::transaction(function () use ($request, $employee, $year, $allocated, $adjustment): void {
            // One person's balance writes serialise on the person, as filing does.
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            $used = $this->balances->for((int) $employee->id, $year)->used;

            if (max(0, $allocated + $adjustment) < $used) {
                throw new LeaveException("لا يمكن أن يقل الرصيد عن الأيام المعتمدة لهذه السنة ({$used} يوم).");
            }

            LeaveBalance::updateOrCreate(
                ['employee_id' => $employee->id, 'year' => $year],
                [
                    'allocated_days'  => $allocated,
                    'adjustment_days' => $adjustment,
                    'note'            => $request->validated('note'),
                    'updated_by'      => $request->user()->getKey(),
                ],
            );
        });

        $employee->load('department');

        return $this->success(
            new LeaveBalanceResource([$employee, $this->balances->for((int) $employee->id, $year)]),
            'تم تحديث رصيد الإجازات.',
        );
    }
}
