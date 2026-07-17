<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * EmployeeResource
 *
 * API contract for an employee, decoupled from the Eloquent model (§16.8).
 *
 * Response shape:
 * {
 *   "id": 1,
 *   "employee_number": "EMP-00001",
 *   "name": "Jane Doe",
 *   "national_id": "1098765432",
 *   "phone": "+1...",
 *   "email": "jane@example.com",
 *   "address": "...",
 *   "department_id": 2,
 *   "job_title": "Engineer",
 *   "hire_date": "2026-01-15",
 *   "salary": "85000.00",
 *   "status": "active",
 *   "created_at": "...",
 *   "updated_at": "..."
 * }
 */
class EmployeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'employee_number' => $this->employee_number,
            'name'            => $this->name,
            'national_id'     => $this->national_id,
            'phone'           => $this->phone,
            'email'           => $this->email,
            'address'         => $this->address,
            'department_id'   => $this->department_id,
            'job_title'       => $this->job_title,
            'hire_date'       => $this->hire_date?->toDateString(),
            'salary'          => $this->salary,
            'status'          => $this->status,
            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
        ];
    }
}
