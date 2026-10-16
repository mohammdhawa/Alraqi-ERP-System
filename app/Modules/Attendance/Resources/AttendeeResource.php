<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Resources;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Resources\EmployeeStub;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A person on an attendance list — absent today: who (C31's stub) and their
 * unit. The service eager-loads `department`.
 *
 * @mixin Employee
 */
class AttendeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'employee'   => EmployeeStub::from($this->resource),
            'department' => $this->department === null ? null : [
                'id'   => (int) $this->department->getKey(),
                'name' => $this->department->name,
            ],
        ];
    }
}
