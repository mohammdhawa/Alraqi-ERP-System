<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\JobTitle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * JobTitleService
 *
 * Business logic for the job-title catalogue. The in-use guard that refuses to
 * delete a title someone holds lives in the MODEL (C4), not here, so it binds
 * the Batch 3 importer and tinker too; this service only delegates to it.
 */
class JobTitleService
{
    /**
     * @var array<string, string>
     */
    public const SORTABLE = [
        'name'       => 'name',
        'created_at' => 'created_at',
    ];

    /**
     * Paginated catalogue.
     *
     * Ordered by name by default rather than newest-first, because this list is
     * read as a CATALOGUE — someone looking for محاسب wants it where محاسب
     * belongs, not wherever it happened to be created. The primary key still
     * closes the ordering (C29): titles collide on prefix and two rows sorting
     * equal would page non-deterministically.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<JobTitle>
     */
    public function paginate(int $perPage = 15, array $filters = [], ?string $sort = null): LengthAwarePaginator
    {
        $query = JobTitle::query();

        foreach (['is_described', 'is_active'] as $flag) {
            $value = $this->boolOrNull($filters[$flag] ?? null);

            if ($value !== null) {
                $query->where($flag, $value);
            }
        }

        if (($filters['search'] ?? null) !== null && $filters['search'] !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $filters['search']).'%';
            $query->where('name', 'like', $like);
        }

        return $this->applySort($query, $sort)->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): JobTitle
    {
        return DB::transaction(fn (): JobTitle => JobTitle::create($data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(JobTitle $jobTitle, array $data): JobTitle
    {
        $jobTitle->update($data);

        return $jobTitle->refresh();
    }

    /**
     * Soft-delete a title.
     *
     * The refusal when staff still hold it is the model's (C4). This method
     * stays a one-liner on purpose: duplicating the check here would create a
     * second place for it to be wrong.
     */
    public function delete(JobTitle $jobTitle): void
    {
        $jobTitle->delete();
    }

    /**
     * Read a filter value as a boolean, or null when it says nothing.
     *
     * ⚠️ NOT `(bool) $value`. A query string carries only strings, and
     * `(bool) "false"` is TRUE — so the plain cast returns exactly the rows the
     * caller asked to exclude, with a 200 and a response that looks correct.
     * `FILTER_VALIDATE_BOOLEAN` knows the spellings a client actually sends
     * ("false", "0", "no", "off") and reports anything else as null rather than
     * guessing at it.
     *
     * A null is SKIPPED rather than treated as false: over HTTP an unparseable
     * value has already been refused by JobTitleIndexRequest with a 422, so
     * reaching here means a console or importer caller passed something the
     * filter cannot honour, and silently narrowing their result set would be
     * the worse answer.
     */
    private function boolOrNull(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * Apply an allow-listed sort, defaulting to alphabetical.
     *
     * An unknown key never reaches here — JobTitleIndexRequest rejects it with
     * a 422 rather than letting it fall back silently (C30, P3), and it reads
     * this same allow-list so the two cannot disagree. The branch below is the
     * backstop for a non-HTTP caller, and it falls back to the DEFAULT ordering
     * rather than to `name` ascending, so a mistyped `-name` from a console
     * script does not quietly answer in ascending order.
     *
     * @param  Builder<JobTitle>  $query
     * @return Builder<JobTitle>
     */
    private function applySort(Builder $query, ?string $sort): Builder
    {
        $default = fn (): Builder => $query->orderBy('name')->orderByDesc('id');

        if ($sort === null || $sort === '') {
            return $default();
        }

        $descending = str_starts_with($sort, '-');
        $column     = self::SORTABLE[ltrim($sort, '-')] ?? null;

        if ($column === null) {
            return $default();
        }

        return $query->orderBy($column, $descending ? 'desc' : 'asc')->orderByDesc('id');
    }
}
