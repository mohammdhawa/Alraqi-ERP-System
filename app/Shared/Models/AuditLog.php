<?php

declare(strict_types=1);

namespace App\Shared\Models;

use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use LogicException;

/**
 * AuditLog
 *
 * The READ lens over `audit_logs`. It exists so the trail can be queried,
 * paginated and serialised like any other resource; it is not, and must never
 * become, a way to write one.
 *
 * WHY IT LIVES IN Shared AND NOT IN Auth: the table is shared infrastructure —
 * its migration is in `database/migrations/Shared/`, its writer is
 * `App\Shared\Services\AuditLogService`, and every module writes to it through
 * HasAuditLog. The READ SURFACE over it is an Auth-module feature (guarded by
 * `auth.audit.view`, mounted beside users and roles), the same direction as
 * Auth and Departments importing `HR\Resources\EmployeeStub`: the owner of the
 * thing and the owner of a view onto it are allowed to differ. Nothing in
 * Shared imports the Auth read surface back.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * WRITES THROUGH THIS MODEL ARE REFUSED, on purpose
 * ──────────────────────────────────────────────────────────────────────────
 * The table is append-only by design — in production the DB user should hold
 * INSERT and SELECT only. The one legitimate writer inserts through
 * `DB::table()` precisely so that model events cannot fire (a HasAuditLog model
 * writing its own audit row would recurse forever). So a `save()` or `delete()`
 * here is always a programming error, and P3 says it must fail loudly rather
 * than quietly succeed on a developer machine where the grants are looser than
 * production's. The hooks below are that failure.
 *
 * It deliberately carries NO HasAuditLog: auditing the audit table is a loop.
 * It is also not in the enforced morph map, because nothing points AT an audit
 * row — it points at everything else.
 *
 * NO SoftDeletes, and that is not a C23 oversight: nothing may FK into this
 * table, and a trashed audit row is a contradiction in terms.
 */
class AuditLog extends Model
{
    protected $table = 'audit_logs';

    /**
     * The table has `created_at` and no `updated_at` — an audit row is written
     * once and never touched. Letting Eloquent manage timestamps would have it
     * reach for a column that does not exist.
     */
    public $timestamps = false;

    /**
     * No $fillable and no $guarded override, so Eloquent's default ("nothing is
     * mass-assignable") stands. Combined with the hooks below there is no route
     * from this class to a row.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    /**
     * `auditable_type` ALWAYS READS AS A PUBLIC NAME, OR NULL. The column may
     * hold a fully-qualified class name — every row written before the morph map
     * was enforced (2026-09-08) does — and C12 forbids putting one of those on
     * the wire. publicType() is the translation, and this accessor makes it the
     * only way the attribute is ever read, so no caller has to remember.
     *
     * The raw column is still reachable through getRawOriginal(), which is what
     * tells "no target at all" apart from "a target we cannot name"; a filter
     * matches both spellings at the QUERY level through storedTypesFor().
     */
    protected function auditableType(): Attribute
    {
        return Attribute::get(static fn (?string $stored): ?string => self::publicType($stored));
    }

    /**
     * The type the `auditable` relation is resolved through: the same public
     * name, but ONLY when it maps to a class that still exists. Otherwise null.
     *
     * ──────────────────────────────────────────────────────────────────────
     * ⚠️ THIS IS NOT A COLUMN, AND IT IS WHAT KEEPS THE EAGER LOAD FROM DYING
     * ──────────────────────────────────────────────────────────────────────
     * Eager-loading a morphTo groups the page by its type attribute and calls
     * `new $class` once per distinct value — MorphTo::buildDictionary reads the
     * attribute (so an accessor applies), then createModelByType instantiates
     * whatever it finds. A stored value with no live class behind it therefore
     * reached `new 'App\Modules\HR\Models\OldName'` and raised an **Error**:
     * not an exception any renderer catches, so a 500. On an APPEND-ONLY table
     * the offending row cannot be corrected, so every page and every detail
     * fetch containing it would have been a 500 forever.
     *
     * Two stored values do that, and only the first was ever considered:
     *   - a class name for a model since renamed, moved or deleted — the legacy
     *     rows, and the legacy merge is going to move classes between modules;
     *   - an ALIAS whose model has been retired. `contract` is a public name,
     *     it is safe to publish, and `new 'contract'` is still an Error.
     *
     * So the wire value and the relation value are deliberately NOT the same
     * thing. `auditable_type` stays as informative as it can honestly be — an
     * investigator reading a retired type still sees what kind of record the row
     * was about, and API_DOCUMENTATION §9.4 tells clients to render that raw key
     * unlinked. This one answers a narrower question, "is there a class I may
     * instantiate", and is null whenever the answer is no; MorphTo then skips
     * the row and `auditable` serialises as null, which is the same statement a
     * soft-deleted target makes and exactly what AuditTargetStub documents.
     *
     * DO NOT COLLAPSE THE TWO. Pointing the relation back at `auditable_type`
     * restores the fatal; making `auditable_type` null whenever it is
     * unresolvable throws away the one fact a broken row still carries.
     */
    protected function auditableMorphType(): Attribute
    {
        return Attribute::get(static function (mixed $value, array $attributes): ?string {
            $alias = self::publicType($attributes['auditable_type'] ?? null);

            return $alias !== null && Relation::getMorphedModel($alias) !== null ? $alias : null;
        });
    }

    protected static function booted(): void
    {
        static::saving(static function (): void {
            throw new LogicException(
                'audit_logs is append-only: write through AuditLogService, never through the AuditLog model.',
            );
        });

        static::deleting(static function (): void {
            throw new LogicException('audit_logs is append-only: rows cannot be deleted.');
        });
    }

    /**
     * The actor. Null when the row was written with no authenticated user (a
     * seeder, a console command, a queued job) OR when the account has since
     * been soft-deleted — the global scope hides the latter, which is what makes
     * "مستخدم محذوف" a case a client can detect: `user_id` is set and `user` is
     * null.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The record the event was about. Null for action-level events (login,
     * role_assigned, api_request), and also null when the target has been
     * soft-deleted — to a client those are the same statement, since it cannot
     * fetch that row either way.
     *
     * RESOLVED THROUGH `auditable_morph_type`, NOT `auditable_type`: see that
     * accessor for why the relation must not read the column directly. The
     * foreign key is still the real `auditable_id`.
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo('auditable', 'auditable_morph_type', 'auditable_id');
    }

    /**
     * The PUBLIC alias for a stored `auditable_type`, or null when there is no
     * honest one.
     *
     * WHY THIS IS NOT JUST `$this->auditable_type`: rows written before the
     * morph map was enforced (2026-09-08) stored the fully-qualified class name,
     * and C12 forbids ever putting one of those on the wire — in any
     * environment, including the one where the oldest rows live. Newer rows
     * already hold the alias, so the first branch is the common case.
     *
     * A class name with no entry in the map (a model deleted or renamed since)
     * resolves to null rather than to a guess: the client then renders the row
     * with an unresolvable target, which is true, instead of a type name that
     * would be a leak and a fiction at once.
     *
     * The `auditableType` accessor above applies this to every read, so callers
     * hold an already-public value. It is IDEMPOTENT — an alias carries no
     * backslash and comes straight back — so calling it twice is harmless.
     */
    public static function publicType(?string $stored): ?string
    {
        if ($stored === null || ! str_contains($stored, '\\')) {
            return $stored;
        }

        $alias = array_search($stored, Relation::morphMap(), true);

        return $alias === false ? null : $alias;
    }

    /**
     * Every value the `auditable_type` column may hold for one public alias.
     *
     * The inverse of publicType(), and it exists for the same reason: a filter
     * on `auditable_type=employee` must also match the pre-morph-map rows that
     * spell the same type as a class name, or the drill-down silently loses the
     * oldest history — which is the history an audit is most often opened for.
     *
     * @return array<int, string>
     */
    public static function storedTypesFor(string $alias): array
    {
        $class = Relation::getMorphedModel($alias);

        return $class === null ? [$alias] : [$alias, $class];
    }
}
