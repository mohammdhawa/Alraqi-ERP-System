<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * CsvList
 *
 * `?event=created,updated` → `['created', 'updated']`. One definition, because
 * it is needed on BOTH sides of the same filter and the two sides must agree:
 *
 *   - A FormRequest splits BEFORE validation, so each item can be checked
 *     against its allow-list and one bad key in a list of five is still a 422.
 *   - The service splits again, because a service is also callable from a
 *     console command or a job with a plain filter array — and the natural
 *     spelling there is `['event' => 'created']` or `['event' => 'created,updated']`,
 *     neither of which has been through a FormRequest.
 *
 * Without the second split the service's documented non-HTTP entry point had a
 * different contract from its HTTP one: a scalar reached `whereIn()` and threw
 * a TypeError, and a CSV string reached it as a single unmatched value.
 *
 * EMPTY ITEMS ARE DROPPED, NOT PRESERVED — `a,,b` is two keys and a stray
 * comma, not three. That an ENTIRELY empty result (`?event=` or `?event=,,,`)
 * must be a 422 rather than a filter that quietly does nothing is a rule for
 * the FormRequest, not for this parser: it is the caller who knows whether an
 * absent filter is legal.
 */
final class CsvList
{
    /**
     * Normalise a CSV string, a scalar, or an already-split array into a list
     * of trimmed, non-empty strings. Positions are preserved and duplicates are
     * kept, so a validator's `event.1` still names the item the caller sent.
     *
     * @return array<int, string>
     */
    public static function parse(mixed $value): array
    {
        $items = [];

        foreach (is_array($value) ? $value : [$value] as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            foreach (explode(',', (string) $item) as $part) {
                $part = trim($part);

                if ($part !== '') {
                    $items[] = $part;
                }
            }
        }

        return $items;
    }
}
