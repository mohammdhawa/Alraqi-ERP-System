<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use App\Modules\HR\Services\JobTitleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * JobTitleIndexRequest
 *
 * Validates the filter and sort parameters of `GET /api/hr/job-titles`.
 *
 * The twin of EmployeeIndexRequest, and here for the same reason: an unknown
 * `sort` or an invalid filter value is a 422, never a silent fallback (C30, P3).
 * Quietly ignoring `?sort=grade` returns a correct-looking page in the wrong
 * order, and the caller has no way to tell it was ignored.
 *
 * ⚠️ THE BOOLEAN FILTERS ARE NORMALISED BEFORE VALIDATION, and that is the
 * point of this class rather than a convenience. A query string carries only
 * strings, so `?is_active=false` arrives as the STRING "false" — which is
 * truthy in PHP. Cast with `(bool)` it becomes `true` and the endpoint returns
 * precisely the rows the caller asked to exclude: a silently inverted filter,
 * which is worse than an error because the response looks right.
 *
 * `prepareForValidation()` maps the spellings a client actually sends onto real
 * booleans, and Laravel's `boolean` rule then rejects anything else with a 422.
 * The service receives a genuine bool and never has to guess.
 *
 * THE SORT ALLOW-LIST IS READ FROM THE SERVICE, not restated — the service is
 * the authority (C30), and a second copy here is how the two drift until a
 * documented sort key silently stops working.
 */
class JobTitleIndexRequest extends FormRequest
{
    /**
     * The query-string spellings of a boolean that this API accepts, mapped to
     * the value they mean.
     *
     * `0`/`1` are what the documentation promises; `true`/`false` are what a
     * JavaScript client sends without thinking about it, and rejecting those
     * would fail the most common caller for no gain. Anything outside this map
     * is a 422 rather than a guess.
     *
     * @var array<string, bool>
     */
    private const BOOLEANS = [
        '1' => true,  'true'  => true,  'on'  => true,  'yes' => true,
        '0' => false, 'false' => false, 'off' => false, 'no'  => false,
    ];

    /**
     * The filters that take one.
     *
     * @var array<int, string>
     */
    private const BOOLEAN_FILTERS = ['is_described', 'is_active'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Turn the string a query string can carry into the boolean it means.
     *
     * Only replaces values this API recognises: an unrecognised one is left
     * exactly as it arrived so the `boolean` rule can reject it by name.
     */
    protected function prepareForValidation(): void
    {
        $normalised = [];

        foreach (self::BOOLEAN_FILTERS as $filter) {
            $value = $this->query($filter);

            if (is_string($value) && array_key_exists(strtolower($value), self::BOOLEANS)) {
                $normalised[$filter] = self::BOOLEANS[strtolower($value)];
            }
        }

        if ($normalised !== []) {
            $this->merge($normalised);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'is_described' => ['sometimes', 'boolean'],
            'is_active'    => ['sometimes', 'boolean'],
            'search'       => ['sometimes', 'string', 'max:255'],
            'sort'         => ['sometimes', 'string', Rule::in($this->sortKeys())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'is_described.boolean' => 'قيمة حقل التوصيف يجب أن تكون 0 أو 1.',
            'is_active.boolean'    => 'قيمة حقل التفعيل يجب أن تكون 0 أو 1.',
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
        $keys = array_keys(JobTitleService::SORTABLE);

        return array_merge($keys, array_map(static fn (string $key): string => '-'.$key, $keys));
    }
}
