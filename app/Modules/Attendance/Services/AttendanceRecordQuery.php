<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Auth\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Listing attendance records with legacy's filters and sorts. Slice 1 serves
 * the employee's own list; slice 2's oversight list adds the row scope and the
 * person filters on top of the same filters.
 *
 * THE DATE FILTERS, in legacy's precedence: `date` > `from`/`to` > `month`
 * (with `year`, this year by default) > `year` > **today** when none is given.
 * The default is legacy's own, and kept.
 */
final class AttendanceRecordQuery
{
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
        $query = AttendanceRecord::query()->forEmployee((int) $this->attendees->employeeOf($user)->getKey());

        $this->applyFilters($query, $filters);
        $this->applyDates($query, $filters);
        $this->applySort($query, $filters['sort'] ?? null);

        return $query->paginate($perPage);
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
     * deterministic (C29).
     *
     * @param  Builder<AttendanceRecord>  $query
     */
    private function applySort(Builder $query, ?string $sort): void
    {
        if ($sort === null) {
            $query->orderByDesc('recorded_at')->orderByDesc('id');

            return;
        }

        $descending = str_starts_with($sort, '-');
        $column = self::SORTABLE[ltrim($sort, '-')];

        $query->orderBy($column, $descending ? 'desc' : 'asc')->orderBy('id', $descending ? 'desc' : 'asc');
    }

    /**
     * Every accepted `sort` value: each key and its descending form.
     *
     * @return array<int, string>
     */
    public static function sortKeys(): array
    {
        $keys = array_keys(self::SORTABLE);

        return [...$keys, ...array_map(static fn (string $key): string => "-{$key}", $keys)];
    }
}
