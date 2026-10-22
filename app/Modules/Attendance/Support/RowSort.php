<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Support;

/**
 * Sorting a report's rows in memory, as legacy's Sorts::applyToRows did: the
 * reports are computed, not queried, so there is no ORDER BY to give them.
 *
 * `$sort` is an allow-listed key (the request has already refused anything
 * else, C30), with a leading `-` for descending. `$keys` maps that key to a
 * function reading the value from a row. Ties keep the rows' existing order —
 * PHP's sort is stable — so the order is deterministic.
 */
final class RowSort
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, callable(array<string, mixed>): mixed>  $keys
     * @return array<int, array<string, mixed>>
     */
    public static function apply(array $rows, ?string $sort, array $keys): array
    {
        if ($sort === null || $sort === '') {
            return array_values($rows);
        }

        $descending = str_starts_with($sort, '-');
        $value = $keys[ltrim($sort, '-')];

        usort($rows, static function (array $a, array $b) use ($value, $descending): int {
            $order = $value($a) <=> $value($b);

            return $descending ? -$order : $order;
        });

        return $rows;
    }

    /**
     * Every accepted `sort` value for these keys: each key and its `-` form.
     *
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    public static function values(array $keys): array
    {
        return [...$keys, ...array_map(static fn (string $key): string => "-{$key}", $keys)];
    }
}
