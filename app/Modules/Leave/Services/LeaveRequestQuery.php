<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Modules\Approvals\Support\ApprovalRelations;
use App\Modules\Leave\Models\LeaveRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Listing leave with legacy's filters and sorts (its applyLeaveFilters() and
 * leaveSortMap()), and what every response eager-loads so
 * LeaveRequestResource never queries (C31). The own list is here; the
 * register joins it in slice 3.
 */
class LeaveRequestQuery
{
    /**
     * Client sort key → column (C30); a leading `-` is descending.
     */
    public const SORTABLE = ['created_at', 'start_date', 'end_date', 'requested_days', 'status', 'decided_at', 'id'];

    /**
     * @return array<int, string>
     */
    public static function sortKeys(): array
    {
        return [...self::SORTABLE, ...array_map(static fn (string $key): string => '-'.$key, self::SORTABLE)];
    }

    /**
     * The request, its people, its files, and its approval's live state
     * (ApprovalRelations::liveState() under `approval.`).
     *
     * @return array<int|string, mixed>
     */
    public static function renderRelations(): array
    {
        $relations = ['employee', 'type', 'attachments', 'createdBy.employee', 'cancelledBy.employee', 'shortenedBy.employee', 'approval'];

        foreach (ApprovalRelations::liveState() as $key => $value) {
            if (is_int($key)) {
                $relations[] = 'approval.'.$value;
            } else {
                $relations['approval.'.$key] = $value;
            }
        }

        return $relations;
    }

    /**
     * One employee's leave — requests and HR's excuses — newest first.
     *
     * @param  array<string, mixed>  $filters
     */
    public function own(int $employeeId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = LeaveRequest::query()->where('employee_id', $employeeId)->with(self::renderRelations());

        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters['sort'] ?? '-created_at');

        return $query->paginate($perPage);
    }

    /**
     * @param  Builder<LeaveRequest>  $query
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $query, array $filters): void
    {
        $statuses = [...($filters['statuses'] ?? []), ...(isset($filters['status']) ? [$filters['status']] : [])];
        if ($statuses !== []) {
            $query->whereIn('status', array_map('intval', $statuses));
        }

        $types = [...($filters['leave_type_ids'] ?? []), ...(isset($filters['leave_type_id']) ? [$filters['leave_type_id']] : [])];
        if ($types !== []) {
            $query->whereIn('leave_type_id', array_map('intval', $types));
        }

        if (isset($filters['month'])) {
            $start = Carbon::create((int) ($filters['year'] ?? Carbon::now()->year), (int) $filters['month'], 1);
            $query->whereBetween('start_date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()]);
        } elseif (isset($filters['year'])) {
            $query->startingInYear((int) $filters['year']);
        }

        // An overlap, not containment: the requests whose leave shares a day
        // with the window — the on-leave rosters' meaning (legacy).
        if (isset($filters['date_from']) || isset($filters['date_to'])) {
            $query->overlapping(
                Carbon::parse($filters['date_from'] ?? '1000-01-01')->toDateString(),
                Carbon::parse($filters['date_to'] ?? '9999-12-31')->toDateString(),
            );
        }

        if (array_key_exists('is_excuse', $filters)) {
            $query->excuses(filter_var($filters['is_excuse'], FILTER_VALIDATE_BOOLEAN));
        }
    }

    /**
     * Deterministic: the id breaks every tie (C29).
     *
     * @param  Builder<LeaveRequest>  $query
     */
    private function applySort(Builder $query, string $sort): void
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

        $query->orderBy(ltrim($sort, '-'), $direction)->orderBy('id', $direction);
    }
}
