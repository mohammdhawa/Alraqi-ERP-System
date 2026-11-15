<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests\Concerns;

use App\Shared\Support\CsvList;

/**
 * The person filters a list or report request shares — employees, units (each
 * with its subtree), a name part or staff number — read by
 * Employee::scopeMatchingPeople. The id lists take legacy's CSV or an array.
 */
trait FiltersPeople
{
    protected function normalisePeople(): void
    {
        foreach (['employee_ids', 'department_ids'] as $key) {
            if ($this->has($key)) {
                $this->merge([$key => CsvList::parse($this->input($key))]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function peopleRules(): array
    {
        return [
            'employee_id'      => ['sometimes', 'integer', 'min:1'],
            'employee_ids'     => ['sometimes', 'array'],
            'employee_ids.*'   => ['integer', 'min:1'],
            'department_id'    => ['sometimes', 'integer', 'min:1'],
            'department_ids'   => ['sometimes', 'array'],
            'department_ids.*' => ['integer', 'min:1'],
            'search'           => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
