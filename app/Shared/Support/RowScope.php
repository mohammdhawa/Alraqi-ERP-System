<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * RowScope — the answer to "which rows may this caller touch?" (GAP-3).
 *
 * Exactly one of two shapes: every row, or the rows belonging to a set of
 * departments. `AccessScope` builds it; policies and services read it. It is
 * a value, so it carries no user and runs no queries of its own.
 *
 * ⚠️ A ROW WITH NO DEPARTMENT IS OUTSIDE EVERY UNIT SCOPE. An employee whose
 * `department_id` is null sits in nobody's subtree, so only an unrestricted
 * (company-wide) caller sees them. That is the fail-closed default GAP-3 was
 * specified with, and it is deliberate: the importer is known to produce such
 * rows from blank `أسماء الإدارات` cells, and they are exactly the rows no
 * manager is responsible for yet. If the business wants them routed somewhere,
 * that is a data fix (give them a unit), not a widening here.
 */
final class RowScope
{
    /**
     * @param  array<int, int>  $departmentIds
     */
    private function __construct(
        private readonly bool $unrestricted,
        private readonly array $departmentIds,
    ) {}

    public static function all(): self
    {
        return new self(true, []);
    }

    /**
     * @param  array<int, int>  $departmentIds
     */
    public static function units(array $departmentIds): self
    {
        return new self(false, array_values(array_unique(array_map('intval', $departmentIds))));
    }

    public function isUnrestricted(): bool
    {
        return $this->unrestricted;
    }

    /**
     * @return array<int, int> empty when unrestricted — check that first
     */
    public function departmentIds(): array
    {
        return $this->departmentIds;
    }

    /**
     * Whether a row belonging to this department is in scope. Null — a row
     * with no unit — is in scope only for an unrestricted caller.
     */
    public function covers(?int $departmentId): bool
    {
        if ($this->unrestricted) {
            return true;
        }

        return $departmentId !== null && in_array($departmentId, $this->departmentIds, true);
    }

    /**
     * Narrow a query to in-scope rows.
     *
     * Applied to the BUILDER, before `paginate()`, so the count query Laravel
     * derives from it is narrowed too — `meta.total` then agrees with what the
     * caller can actually page through (the PaginationContractTest contract).
     * An empty unit set becomes `WHERE 1 = 0` via `whereIn([])`, which is the
     * correct answer: a scope that names nothing reaches nothing.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function applyTo(Builder $query, string $column = 'department_id'): Builder
    {
        if ($this->unrestricted) {
            return $query;
        }

        return $query->whereIn($query->qualifyColumn($column), $this->departmentIds);
    }
}
