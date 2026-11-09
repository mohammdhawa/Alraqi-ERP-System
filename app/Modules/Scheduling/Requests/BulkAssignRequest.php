<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Requests;

use App\Modules\Departments\Models\Department;
use App\Modules\HR\Models\Employee;
use Illuminate\Validation\Rule;

/** One schedule for a list of people, or for a unit and optionally its subtree. */
class BulkAssignRequest extends AssignWorkScheduleRequest
{
    public const MAX_PEOPLE = 500;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'employee_ids'    => ['required_without:department_id', 'prohibits:department_id', 'array', 'min:1', 'max:'.self::MAX_PEOPLE],
            'employee_ids.*'  => ['integer', 'distinct'],
            'department_id'   => ['required_without:employee_ids', 'integer', Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'include_subtree' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<int> */
    public function employeeIds(): array
    {
        if ($this->has('employee_ids')) {
            return array_values(array_map('intval', (array) $this->validated('employee_ids')));
        }

        $root = (int) $this->validated('department_id');
        $units = $this->boolean('include_subtree') ? Department::subtreeIds([$root]) : [$root];

        return Employee::query()->whereIn('department_id', $units)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }
}
