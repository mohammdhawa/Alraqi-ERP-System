<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Shared\Models\AuditLog;
use App\Shared\Support\AuditEventCatalogue;
use App\Shared\Support\CsvList;
use App\Shared\Support\SearchTerm;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * AuditTrailService
 *
 * The READER of `audit_logs`. `App\Shared\Services\AuditLogService` is the
 * WRITER, and the two never meet: this class has no insert, and that one has no
 * query. The names are deliberately different so nobody reaches for the wrong
 * one on autocomplete.
 *
 * NO WRITES, NO DELETES, NO RETENTION. There is no update, purge or archive
 * method here and there must not be one — the table is append-only by design,
 * and no permission exists that could authorize a mutation. A "clear log" button
 * on an audit trail is not a feature, it is the thing an audit trail exists to
 * prevent.
 *
 * WHY THE SORT ALLOW-LIST HAS EXACTLY ONE KEY: `created_at` is the only indexed
 * column that orders this table, and this is the one table in the system that
 * grows without bound. Sorting a few hundred thousand rows by `event` or
 * `ip_address` is a filesort per page. The allow-list is the refusal (C30), and
 * an unknown key is a 422 from AuditLogIndexRequest rather than a silent
 * fallback, so a client never gets a correct-looking page in the wrong order.
 */
class AuditTrailService
{
    /**
     * The one sortable column, mapped from its public key (C30).
     *
     * ⚠️ ADDING A KEY HERE MEANS ADDING AN INDEX FIRST. AuditLogIndexRequest
     * reads this same constant, so the two cannot drift about what is legal.
     *
     * @var array<string, string>
     */
    public const SORTABLE = [
        'created_at' => 'created_at',
    ];

    /**
     * Filters that are a plain equality match on one column.
     *
     * The rest — the CSV sets, the date range, the free-text search, the
     * polymorphic type and the actor kind — need shaping before they reach SQL
     * and are applied one by one in query().
     *
     * ⚠️ EVERY KEY HERE IS BACKED BY AN INDEX, and adding one means adding the
     * index in the same commit — the sort allow-list below is not the only
     * place that rule applies, and `ip_address` shipped in breach of it until
     * 2026-09-20, which made a filter the tests used on EVERY list assertion a
     * full scan of the one table that grows without bound.
     *
     * @var array<string, string>
     */
    public const FILTERABLE = [
        'user_id'      => 'user_id',
        'auditable_id' => 'auditable_id',
        'request_id'   => 'request_id',
        'ip_address'   => 'ip_address',
    ];

    /**
     * `actor_type` values, and the shape of the `user_id` column each one means.
     *
     * WHY THIS IS A FILTER AND NOT A DERIVED DISPLAY FIELD: AuditLogResource,
     * API_DOCUMENTATION §9.3 and the frontend work order all treat "system"
     * (a seeder, console command or queued job) as one of three first-class
     * actor cases — and it was the only one with no way to ask for it.
     * `user_id` matches an id, and no value of it expresses IS NULL, so
     * "what happened outside any user session" — the case where nobody is
     * accountable, and therefore the one most worth isolating — could only be
     * reached by paging the whole unbounded table and filtering client-side.
     *
     * @var array<string, bool> value => whether user_id must be null
     */
    public const ACTOR_TYPES = [
        'system' => true,
        'user'   => false,
    ];

    /**
     * Paginated slice of the trail.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<AuditLog>
     */
    public function paginate(int $perPage = 15, array $filters = [], ?string $sort = null): LengthAwarePaginator
    {
        return $this->query($filters, $sort)->paginate($perPage);
    }

    /**
     * The shared query: eager loads, filters, ordering.
     *
     * EAGER LOADS, AND WHY EACH IS BOUNDED. `user.employee` is two queries per
     * page and resolves the actor's name, which the account itself does not
     * store. `auditable` is a morphTo, so Eloquent issues one query per DISTINCT
     * TYPE on the page — at most nine, the size of the morph map, and in
     * practice one or two. Without them AuditLogResource would dereference
     * unloaded relations and every row would cost its own queries; a Resource
     * that lazy-loads is an N+1 that only shows up in production, so whoever
     * hands the model to the Resource owes it the relations it reads.
     *
     * "AT MOST NINE" IS TRUE BY CONSTRUCTION, not by luck: eager-loading a
     * morphTo instantiates one class per distinct stored type, and a stored type
     * with no live class behind it raises an Error rather than an exception —
     * uncatchable, and permanent on a table whose rows cannot be corrected. The
     * relation is resolved through AuditLog's `auditable_morph_type` accessor,
     * which is null unless the type maps to a class that exists, so the set
     * reaching `new $class` is a subset of the morph map. Do not repoint the
     * relation at the raw column; that accessor is load-bearing for this line.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<AuditLog>
     */
    private function query(array $filters = [], ?string $sort = null): Builder
    {
        $query = AuditLog::query()->with(['user.employee', 'auditable']);

        foreach (self::FILTERABLE as $key => $column) {
            if (($filters[$key] ?? null) !== null && $filters[$key] !== '') {
                $query->where($column, $filters[$key]);
            }
        }

        $this->applyEventFilters($query, $filters);
        $this->applyActorFilter($query, $filters);
        $this->applyTypeFilter($query, $filters);
        $this->applyDateRange($query, $filters);

        if (($filters['search'] ?? null) !== null && $filters['search'] !== '') {
            $this->applySearch($query, (string) $filters['search']);
        }

        return $this->applySort($query, $sort);
    }

    /**
     * `event` names the exact events; `event_group` names them by family and is
     * expanded to the same column.
     *
     * BOTH TOGETHER INTERSECT, they do not union: `event=created&
     * event_group=auth` asks for a created event in the auth group and there is
     * none, so the honest answer is an empty page. Two WHERE IN clauses on the
     * same column give that for free — worth stating, because a client combining
     * the two and getting rows back from either set would be reading a filter
     * that quietly did nothing.
     *
     * BOTH INPUTS ARE NORMALISED, like every other filter here. This was the one
     * helper that handed its value straight to `whereIn()`, so the service's
     * OTHER documented entry point — a console command passing
     * `['event' => 'created']`, the natural spelling and the shape the HTTP
     * layer itself receives before the FormRequest reshapes it — threw a
     * TypeError instead of filtering.
     *
     * @param  Builder<AuditLog>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyEventFilters(Builder $query, array $filters): void
    {
        $events = CsvList::parse($filters['event'] ?? null);

        if ($events !== []) {
            $query->whereIn('event', $events);
        }

        $groups = CsvList::parse($filters['event_group'] ?? null);

        if ($groups !== []) {
            $query->whereIn('event', AuditEventCatalogue::eventsIn($groups));
        }
    }

    /**
     * "Only what a person did", or "only what ran with nobody signed in".
     *
     * Composes with `user_id` rather than replacing it: `actor_type=user` plus
     * `user_id=7` is simply a narrower version of `user_id=7`, and
     * `actor_type=system` plus a `user_id` is a contradiction that correctly
     * returns nothing rather than quietly dropping one of the two.
     *
     * AN UNRECOGNISED VALUE RAISES rather than filtering nothing. An HTTP caller
     * never reaches this — AuditLogIndexRequest 422s first — so the only way to
     * get here is a console command or a job with a typo, and returning the
     * WHOLE trail to code that asked for one half of it is the silent fallback
     * this whole surface is built to refuse (C30, P3). A LogicException says so
     * at the call site instead.
     *
     * @param  Builder<AuditLog>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyActorFilter(Builder $query, array $filters): void
    {
        $actor = $filters['actor_type'] ?? null;

        if ($actor === null || $actor === '') {
            return;
        }

        $isSystem = self::ACTOR_TYPES[(string) $actor] ?? null;

        if ($isSystem === null) {
            throw new LogicException(sprintf(
                'Unknown actor_type "%s". Expected one of: %s.',
                (string) $actor,
                implode(', ', array_keys(self::ACTOR_TYPES)),
            ));
        }

        if ($isSystem) {
            $query->whereNull('user_id');

            return;
        }

        $query->whereNotNull('user_id');
    }

    /**
     * The client sends the public alias (`employee`); the column may hold either
     * that or — on rows predating the enforced morph map — the class name. Both
     * are matched, or the filter would silently drop the oldest history.
     *
     * `auditable_id` alone is refused upstream (422 in AuditLogIndexRequest): an
     * id with no type matches row 42 of every table at once, which is not a
     * question anyone means to ask.
     *
     * @param  Builder<AuditLog>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyTypeFilter(Builder $query, array $filters): void
    {
        $alias = $filters['auditable_type'] ?? null;

        if ($alias === null || $alias === '') {
            return;
        }

        $query->whereIn('auditable_type', AuditLog::storedTypesFor((string) $alias));
    }

    /**
     * An inclusive day range in Asia/Damascus.
     *
     * NO TIMEZONE ARITHMETIC IS NEEDED, and that is a fact about this schema
     * rather than an oversight: `AuditLogService` writes `now()`, which is app
     * time, and `config('app.timezone')` is Asia/Damascus — so the column holds
     * local wall-clock time and a local boundary compares against it directly.
     *
     * `date_to` extends to the END of its day, so `date_from=date_to=2026-09-16`
     * returns that whole day including its last hour. Asking for one day and
     * getting everything except the evening is the bug this sentence exists to
     * prevent.
     *
     * RANGE COMPARISONS, NOT whereDate(). `whereDate()` wraps the column in
     * DATE(), which discards the `created_at` index — on the one table that
     * grows without bound, that turns the commonest filter into a full scan.
     *
     * @param  Builder<AuditLog>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyDateRange(Builder $query, array $filters): void
    {
        if (($filters['date_from'] ?? null) !== null) {
            $query->where('created_at', '>=', Carbon::parse((string) $filters['date_from'])->startOfDay());
        }

        if (($filters['date_to'] ?? null) !== null) {
            $query->where('created_at', '<=', Carbon::parse((string) $filters['date_to'])->endOfDay());
        }
    }

    /**
     * Free-text search over `description` — the one human-written field on the
     * row. The values blobs are deliberately NOT searched: they are gated by
     * `auth.audit.viewValues`, and a search that matched inside them would let a
     * caller without that permission confirm a value by guessing it, which is
     * the gate defeated by a side channel.
     *
     * Wildcards in the term are escaped by SearchTerm — the one definition of
     * that rule, shared with the HR services. It used to be copied here, and the
     * copy neither escaped the escape character nor emitted an `ESCAPE` clause;
     * see that class for what each omission did.
     *
     * @param  Builder<AuditLog>  $query
     */
    private function applySearch(Builder $query, string $term): void
    {
        SearchTerm::where($query, 'description', $term);
    }

    /**
     * Newest first by default, and ALWAYS tie-broken on the primary key (C29).
     *
     * The tie-break is load-bearing here rather than theoretical: `created_at`
     * is a second-resolution timestamp, and a single user action writes the
     * `api_request` row plus one model row per touched model — several rows
     * sharing one value, by construction, on every request. Without the
     * tie-break two pages of the same query have no reason to agree on where
     * those rows fall, so a row can appear twice while another never appears.
     *
     * AN UNRESOLVED KEY TAKES THE WHOLE DEFAULT, direction included. Reading the
     * direction off the raw string independently of whether the key resolved
     * meant `applySort($q, 'ip_address')` fell back to `created_at` but kept the
     * ASCENDING it had inferred from the missing `-`, and answered with the
     * OLDEST rows first. HTTP callers are 422'd upstream and never saw it; the
     * console entry point this class advertises got a correct-looking page in
     * exactly the wrong order — the silent fallback the allow-list exists to
     * refuse (C30), arriving through the door the allow-list does not cover.
     *
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    private function applySort(Builder $query, ?string $sort): Builder
    {
        $column = self::SORTABLE[ltrim((string) $sort, '-')] ?? null;

        if ($column === null) {
            return $query->orderByDesc('created_at')->orderByDesc('id');
        }

        $direction = str_starts_with((string) $sort, '-') ? 'desc' : 'asc';

        return $query->orderBy($column, $direction)->orderBy('id', $direction);
    }
}
