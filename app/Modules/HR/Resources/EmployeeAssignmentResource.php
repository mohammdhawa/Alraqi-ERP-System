<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * EmployeeAssignmentResource
 *
 * The API contract for one posting in سجل التعيين.
 *
 * `is_current` ships explicitly rather than leaving clients to test
 * `to_date === null`. The null carries real meaning here — "still in force",
 * not "unknown" — and a client that guessed the other reading would render an
 * employee's live posting as an incomplete record.
 *
 * `organizational_grade` IS NOT GATED HERE even though the same column on the
 * employee is. That is deliberate and worth stating: the assignments endpoint
 * is guarded by `hr.assignments.view`, a permission an HR administrator holds
 * to read posting history and which is not handed out with
 * `hr.employees.view`. Gating it twice would make the history unreadable to the
 * people the permission exists for; leaving the permission ungranted is the
 * control.
 *
 * ⚠️ If `hr.assignments.view` is ever bundled into a broad read-only role, that
 * reasoning expires and the grade must come behind `viewSalary` here too.
 */
class EmployeeAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'employee_id' => $this->employee_id,

            'department_id' => $this->department_id,
            'department'    => $this->whenLoaded('department', fn () => [
                'id'   => $this->department?->id,
                'name' => $this->department?->name,
            ]),
            'job_title_id'  => $this->job_title_id,
            'job_title'     => $this->whenLoaded('jobTitle', fn () => [
                'id'   => $this->jobTitle?->id,
                'name' => $this->jobTitle?->name,
            ]),

            'organizational_grade' => $this->organizational_grade,

            'from_date'  => $this->from_date?->toDateString(),
            'to_date'    => $this->to_date?->toDateString(),
            'is_current' => $this->to_date === null,

            'reason'       => $this->reason?->value,
            'reason_label' => $this->reason?->label(),

            // Import-only, and present so an unresolvable legacy value stays
            // visible to a human instead of vanishing.
            'legacy_workplace'    => $this->legacy_workplace,
            'legacy_manager_name' => $this->legacy_manager_name,
            'legacy_job_title'    => $this->legacy_job_title,

            'notes'      => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
