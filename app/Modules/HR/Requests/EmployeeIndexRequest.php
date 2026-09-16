<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use App\Modules\HR\Enums\ActivityType;
use App\Modules\HR\Enums\EmployeeStatus;
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
        return [
            'status'        => ['sometimes', 'integer', Rule::enum(EmployeeStatus::class)],
            'activity_type' => ['sometimes', 'integer', Rule::enum(ActivityType::class)],
            'department_id' => ['sometimes', 'integer',
                Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'job_title_id'  => ['sometimes', 'integer',
                Rule::exists('job_titles', 'id')->whereNull('deleted_at')],
            'search'        => ['sometimes', 'string', 'max:255'],
            'sort'          => ['sometimes', 'string', Rule::in($this->sortKeys())],
        ];
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
        ];
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

        return array_merge($keys, array_map(static fn (string $key): string => '-'.$key, $keys));
    }
}
