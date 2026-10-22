<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use App\Modules\HR\Enums\ActivityType;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Policies\EmployeePolicy;
use App\Modules\HR\Resources\EmployeeResource;
use App\Modules\HR\Services\EmployeeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * EmployeeIndexRequest
 *
 * Validates the filter and sort parameters of `GET /api/hr/employees`.
 *
 * WHY AN INDEX HAS A FORM REQUEST AT ALL: the standard calls for one once a
 * list takes client-controlled filtering, and for a specific reason — an
 * unknown `sort` or filter value must be a 422, never a silent fallback (C30,
 * P3). Silently ignoring `?sort=salary` returns a correct-looking page in the
 * wrong order, and the caller has no way to tell. Silently ignoring
 * `?status=terminated` returns ACTIVE staff to a query that asked for departed
 * ones, which is worse.
 *
 * `per_page` is deliberately NOT validated here. It is clamped rather than
 * rejected, in ApiRespond::perPage(), because a page size is a client hint
 * about batching and not a business fact: an out-of-range one still returns
 * correct data, just in a different batch. A wrong sort returns wrong data.
 *
 * THE ALLOW-LIST IS READ FROM THE SERVICE, not restated. The service is the
 * authority on what is sortable (C30 puts it there); duplicating the list here
 * is how the two drift until a documented sort key silently stops working.
 */
class EmployeeIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->gateSalaryKeys([
            'status'        => ['sometimes', 'integer', Rule::enum(EmployeeStatus::class)],
            'activity_type' => ['sometimes', 'integer', Rule::enum(ActivityType::class)],
            'department_id' => ['sometimes', 'integer',
                Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'job_title_id'  => ['sometimes', 'integer',
                Rule::exists('job_titles', 'id')->whereNull('deleted_at')],
            'search'        => ['sometimes', 'string', 'max:255'],
            'sort'          => ['sometimes', 'string', Rule::in($this->sortKeys())],
        ]);
    }

    /**
     * من لا يرى لا يكتُب — ولا يُصفّي. A caller who holds `hr.employees.viewSalary`
     * over NOTHING may not filter or sort by a field it gates.
     *
     * A filter is a read. `?activity_type=1` answers "which of these people are
     * engineers" for every row it returns, one value at a time, so without this
     * the classification axes — hidden on the Resource and refused on write —
     * were readable through the list's query string by anyone holding
     * `hr.employees.view`. Found 2026-09-24 while syncing the dashboard, which
     * already hid the filter; the server did not.
     *
     * TWO LAYERS, deliberately. Here, a caller with no pay grant at all gets a
     * 422 naming the field, rather than a silently empty page (P3). A caller
     * whose grant reaches only SOME units is not refused — EmployeePolicy's
     * `listScope()` narrows the rows to the ones that grant reaches, the same
     * "lists are filtered" rule GAP-3 applies to the list itself.
     *
     * `missing`, not `prohibited`, as on the write gate: an empty value must not
     * slip through either. A gated sort key is simply not offered — the sort
     * rule's own 422 then applies.
     *
     * @param  array<string, array<int, mixed>>  $rules
     * @return array<string, array<int, mixed>>
     */
    private function gateSalaryKeys(array $rules): array
    {
        if ($this->holdsViewSalary()) {
            return $rules;
        }

        foreach (EmployeePolicy::salaryGatedFilters() as $field) {
            $rules[$field] = ['missing'];
        }

        return $rules;
    }

    /**
     * Whether ANY grant carries the permission. Scope is not this layer's
     * question — see the method above. FAILS CLOSED without a user.
     */
    private function holdsViewSalary(): bool
    {
        return $this->user()?->hasPermission(EmployeeResource::VIEW_SALARY) === true;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.enum'          => 'حالة الموظف المحددة غير معرّفة.',
            'activity_type.enum'   => 'نوع النشاط المحدد غير معرّف.',
            'department_id.exists' => 'الوحدة التنظيمية المحددة غير موجودة أو تم حذفها.',
            'job_title_id.exists'  => 'المسمى الوظيفي المحدد غير موجود أو تم حذفه.',
            'sort.in'              => 'حقل الترتيب المحدد غير مدعوم.',
            ...$this->gatedFilterMessages(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function gatedFilterMessages(): array
    {
        $messages = [];

        foreach (EmployeePolicy::salaryGatedFilters() as $field) {
            $messages["{$field}.missing"] = 'لا تملك صلاحية التصفية حسب حقول الرواتب ومعاييرها.';
        }

        return $messages;
    }

    /**
     * Every accepted sort value: each allow-listed key, plus its `-` descending
     * form.
     *
     * @return array<int, string>
     */
    private function sortKeys(): array
    {
        $keys = array_keys(EmployeeService::SORTABLE);

        if (! $this->holdsViewSalary()) {
            $keys = array_values(array_diff($keys, EmployeePolicy::salaryGatedSorts()));
        }

        return array_merge($keys, array_map(static fn (string $key): string => '-'.$key, $keys));
    }
}
