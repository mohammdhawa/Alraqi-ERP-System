<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * SearchTerm
 *
 * The ONE definition of "match this user-typed text against a column". Every
 * `?search=` filter in the system goes through it.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * WHY THIS IS NOT THREE LINES INLINE IN EACH SERVICE
 * ──────────────────────────────────────────────────────────────────────────
 * It was — EmployeeService, JobTitleService and AuditTrailService each carried
 * `'%'.str_replace(['%','_'], ['\%','\_'], $term).'%'`, and all three were
 * wrong in the same two ways, which is what a copied rule does:
 *
 * 1. THE ESCAPE CHARACTER WAS NEVER ITSELF ESCAPED. A backslash in the term
 *    passed through untouched, so on MySQL `?search=\` produced `LIKE '%\%'`
 *    — where `\%` is a LITERAL percent — and the search for a backslash matched
 *    every row containing '%'. Escaping `%` and `_` while leaving the escape
 *    character alone does not escape anything; it just moves the wildcard.
 *
 * 2. NO `ESCAPE` CLAUSE WAS EMITTED, so the escaping was a no-op on SQLite —
 *    which has NO default escape character for LIKE — and the test suite runs
 *    on SQLite. `?search=%` searched for a literal backslash there and matched
 *    nothing, so the assertion "a literal % must not match everything" passed
 *    for entirely the wrong reason and would have kept passing with the
 *    escaping deleted.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * WHY THE ESCAPE CHARACTER IS `!` AND NOT `\`
 * ──────────────────────────────────────────────────────────────────────────
 * Because the clause has to say so out loud, and `ESCAPE '\'` cannot be
 * written once for both drivers: MySQL processes backslashes inside string
 * literals (so it needs `ESCAPE '\\'`), while SQLite does not (so `'\\'` is a
 * two-character string and an error — ESCAPE takes exactly one character).
 * `!` is a single character in every dialect's string literal, so one SQL
 * fragment is correct everywhere. It has no meaning to LIKE, so a `!` a user
 * actually typed is escaped like any other and still matches itself.
 */
final class SearchTerm
{
    /**
     * The LIKE escape character, named in the emitted `ESCAPE` clause.
     */
    public const ESCAPE = '!';

    /**
     * A user's term as a "contains" pattern with every wildcard neutralised.
     *
     * The escape character is replaced FIRST — replacing it after `%` and `_`
     * would double-escape the escapes this method had just written.
     */
    public static function contains(string $term): string
    {
        $escaped = str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'],
            $term,
        );

        return '%'.$escaped.'%';
    }

    /**
     * `WHERE <column> LIKE '%term%'`, wildcards escaped.
     *
     * @param  Builder<covariant Model>  $query
     */
    public static function where(Builder $query, string $column, string $term): void
    {
        $query->whereRaw(self::clause($query, $column), [self::contains($term)]);
    }

    /**
     * The same, OR-ed — for a search that spans several columns. Callers group
     * it themselves, so it cannot escape an active filter.
     *
     * @param  Builder<covariant Model>  $query
     */
    public static function orWhere(Builder $query, string $column, string $term): void
    {
        $query->orWhereRaw(self::clause($query, $column), [self::contains($term)]);
    }

    /**
     * The raw fragment. The TERM is always a binding — only the column name,
     * which is a constant chosen by the calling service and never client input,
     * is interpolated, and it goes through the grammar's own quoting.
     *
     * @param  Builder<covariant Model>  $query
     */
    private static function clause(Builder $query, string $column): string
    {
        return sprintf(
            "%s LIKE ? ESCAPE '%s'",
            $query->getQuery()->getGrammar()->wrap($column),
            self::ESCAPE,
        );
    }
}
