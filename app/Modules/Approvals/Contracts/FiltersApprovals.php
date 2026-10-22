<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A subject that lets the approval lists filter on its own fields — the leave
 * type, the leave's dates — beside the engine's own filters (owner,
 * 2026-10-20, parity audit C-4: legacy's queue filtered on them).
 *
 * The engine does not know what a leave is, so each consumer declares its
 * filters' validation and applies them to its own query; ApprovalFilters
 * picks them up from the morph map, as ApprovalRelations does the summaries.
 * A filter only one type understands narrows the list to that type.
 */
interface FiltersApprovals
{
    /**
     * Validation rules for this subject's filters, keyed by query parameter.
     *
     * @return array<string, mixed>
     */
    public static function approvalFilterRules(): array;

    /**
     * @return array<string, string>
     */
    public static function approvalFilterMessages(): array;

    /**
     * Narrow this subject's query by the validated filters it declared.
     *
     * @param  Builder<Model>  $subjects
     * @param  array<string, mixed>  $filters
     */
    public static function applyApprovalFilters(Builder $subjects, array $filters): void;
}
