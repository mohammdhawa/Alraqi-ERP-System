<?php

declare(strict_types=1);

namespace App\Modules\Leave\Resources;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Resources\EmployeeStub;
use App\Modules\Leave\Services\BalanceFigures;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One person's balance for one year, as HR's list and the update return it:
 * who (C31's stub), their unit, and the figures — legacy's balances row.
 *
 * Built over `[Employee, BalanceFigures]`; the controller eager-loads the
 * employee's `department`.
 */
class LeaveBalanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Employee $employee */
        /** @var BalanceFigures $figures */
        [$employee, $figures] = $this->resource;

        return [
            'employee'   => EmployeeStub::from($employee),
            'department' => $employee->department === null ? null : [
                'id'   => (int) $employee->department->getKey(),
                'name' => $employee->department->name,
            ],
        ] + $figures->toArray();
    }
}
