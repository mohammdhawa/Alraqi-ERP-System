<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Shared\Support\RequestRowScopes;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Listing attendance records with legacy's filters and sorts: the employee's
 * own list (slice 1) and the oversight list (slice 2), which adds the row
 * scope and the person filters on top of the same filters.
 *
 * THE DATE FILTERS, in legacy's precedence: `date` > `from`/`to` > `month`
 * (with `year`, this year by default) > `year` > **today** when none is given.
 * The default is legacy's own, and kept.
 */
final class AttendanceRecordQuery
{
    /**
     * What AttendanceRecordResource renders, eager-loaded by every path that
     * hands it rows: the person (C31) and the accounts that corrected or
     * refused the row, with their employee for the name.
     */
    public const RENDER_RELATIONS = ['employee', 'correctedBy.employee', 'rejectedBy.employee'];

    /**
     * Client sort key → column (C30). `date` is legacy's alias for the moment.
     * A descending sort is the key with a leading `-`.
     */
    public const SORTABLE = [
        'recorded_at' => 'recorded_at',
        'date'        => 'recorded_at',
        'type'        => 'type',
        'status'      => 'status',
        'work_hours'  => 'work_hours',
        'created_at'  => 'created_at',
        'rejected_at' => 'rejected_at',
        'id'          => 'id',
    ];

    /** The oversight list also sorts by the person, as legacy's does: key → employees column. */
    public const PERSON_SORTABLE = [
        'employee_name'   => 'first_name',
        'employee_number' => 'employee_number',
    ];

    public function __construct(
        private readonly AttendeeResolver $attendees,
        private readonly AttendanceCalendar $calendar,
    ) {}

    /**
     * The caller's own records.
     *
     * @param  array<string, mixed>  $filters  validated by MyAttendanceRecordsRequest
     * @return LengthAwarePaginator<int, AttendanceRecord>
     */
    public function mine(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = AttendanceRecord::query()
            ->with(self::RENDER_RELATIONS)
            ->forEmployee((int) $this->attendees->employeeOf($user)->getKey());

        $this->applyFilters($query, $filters);
        $this->applyDates($query, $filters);
        $this->applySort($query, $filters['sort'] ?? null);

        return $query->paginate($perPage);
    }

    /**
     * Everyone's records the caller's attendance.records.view grants reach —
     * filtered on the builder, so `meta.total` counts only what they may page
     * through (GAP-3).
     *
     * @param  array<string, mixed>  $filters  validated by AttendanceRecordsRequest
     * @return LengthAwarePaginator<int, AttendanceRecord>
     */
    public function oversight(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = AttendanceRecord::query()->with(self::RENDER_RELATIONS);

        $scope = RequestRowScopes::forUser($user, 'attendance.records.view');
        if (! $scope->isUnrestricted()) {
            $query->whereIn('employee_id', $scope->applyTo(Employee::withTrashed()->select('id')));
        }

        $this->applyPeople($query, $filters);
        $this->applyFilters($query, $filters);
        $this->applyDates($query, $filters);
        $this->applySort($query, $filters['sort'] ?? null);

        return $query->paginate($perPage);
    }

    /**
     * Whose records — the person filters every attendance list and report
     * shares (AttendancePopulation::filter) — and the refusal reason and
     * receipt window.
     *
     * @param  Builder<AttendanceRecord>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyPeople(Builder $query, array $filters): void
    {
        $people = array_intersect_key($filters, array_flip(['employee_id', 'employee_ids', 'department_id', 'department_ids', 'search']));
        if (array_filter($people, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []) !== []) {
            $query->whereIn('employee_id', AttendancePopulation::filter(Employee::withTrashed()->select('id'), $people));
        }

        if (isset($filters['rejection_reason'])) {
            $query->where('rejection_reason', (int) $filters['rejection_reason']);
        }
        if (isset($filters['created_from'])) {
            $query->whereDate('created_at', '>=', (string) $filters['created_from']);
        }
        if (isset($filters['created_to'])) {
            $query->whereDate('created_at', '<=', (string) $filters['created_to']);
        }
    }

    /**
     * @param  Builder<AttendanceRecord>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $flag = static fn (string $key): ?bool => array_key_exists($key, $filters)
            ? filter_var($filters[$key], FILTER_VALIDATE_BOOLEAN)
            : null;

        $query
            ->when(isset($filters['type']), fn (Builder $q) => $q->where('type', (int) $filters['type']))
            ->when($flag('missing_checkout') !== null, fn (Builder $q) => $q->missingCheckout($flag('missing_checkout')))
            ->when($flag('is_corrected') !== null, fn (Builder $q) => $q->corrected($flag('is_corrected')))
            ->when($flag('rejected') !== null, fn (Builder $q) => $q->rejected($flag('rejected')))
            ->workHoursBetween(
                isset($filters['work_hours_min']) ? (float) $filters['work_hours_min'] : null,
                isset($filters['work_hours_max']) ? (float) $filters['work_hours_max'] : null,
            );
    }

    /**
     * @param  Builder<AttendanceRecord>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyDates(Builder $query, array $filters): void
    {
        if (isset($filters['date'])) {
            $query->onDate((string) $filters['date']);
        } elseif (isset($filters['from']) || isset($filters['to'])) {
            $query->inDateRange($filters['from'] ?? null, $filters['to'] ?? null);
        } elseif (isset($filters['month'])) {
            $query->inMonth((int) $filters['month'], (int) ($filters['year'] ?? Carbon::now($this->calendar->timezone())->year));
        } elseif (isset($filters['year'])) {
            $query->inYear((int) $filters['year']);
        } else {
            $query->onDate($this->calendar->today());
        }
    }

    /**
     * Newest first by default; every order ends on the id, so paging is
     * deterministic (C29). A person sort orders by a correlated subquery, so
     * no join disturbs the selected columns.
     *
     * @param  Builder<AttendanceRecord>  $query
     */
    private function applySort(Builder $query, ?string $sort): void
    {
        if ($sort === null) {
            $query->orderByDesc('recorded_at')->orderByDesc('id');

            return;
        }

        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $key = ltrim($sort, '-');

        if (isset(self::PERSON_SORTABLE[$key])) {
            $query->orderBy(
                Employee::withTrashed()->select(self::PERSON_SORTABLE[$key])->whereColumn('employees.id', 'attendance_records.employee_id'),
                $direction,
            );
        } else {
            $query->orderBy(self::SORTABLE[$key], $direction);
        }

        $query->orderBy('id', $direction);
    }

    /**
     * Every accepted `sort` value: each key and its descending form.
     *
     * @return array<int, string>
     */
    public static function sortKeys(bool $oversight = false): array
    {
        $keys = array_keys($oversight ? [...self::SORTABLE, ...self::PERSON_SORTABLE] : self::SORTABLE);

        return [...$keys, ...array_map(static fn (string $key): string => "-{$key}", $keys)];
    }
}
