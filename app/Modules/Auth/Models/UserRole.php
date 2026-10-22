<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Exceptions\GrantScopeException;
use App\Modules\Auth\Support\GrantScopeGuard;
use App\Modules\Departments\Models\Department;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * UserRole — one GRANT: a role, given to a user, over a scope (GAP-3).
 *
 * Until 2026-09-24 this was a bare pivot keyed `primary(user_id, role_id)`, and
 * a role meant the same thing for everyone who held it. Decision 18 put the
 * scope on the assignment, which changes what a row IS: the same role scoped to
 * two sections is two grants — a supervisor over two sections — and the old key
 * forbade it. Hence the surrogate `id` (a nullable column cannot sit in a
 * primary key) and the unique index over all four columns.
 *
 * ⚠️ THE UNIQUE INDEX DOES NOT CATCH EVERY DUPLICATE. `scope_department_id` is
 * NULL for company and managed grants, and SQL treats two NULLs as distinct, so
 * the database would accept the same company grant twice. `RoleService::assign`
 * checks before it writes, under a lock on the user row — a real guarantee on
 * MySQL, a no-op under SQLite. A duplicate that slipped past would be harmless
 * (the resolver unions scopes), which is why this is a note and not a trigger.
 *
 * WHY A PIVOT MODEL AT ALL: so the `saving` hook runs for EVERY writer. With
 * `using()` on `User::roles()`, Eloquent's `attach()` saves through this class
 * rather than inserting raw rows, so `$user->roles()->attach($id)` in a seeder
 * or a test crosses the guard exactly like the API does.
 *
 * NOT AUDITED AT THE MODEL LEVEL. `RoleService` writes `role_assigned` /
 * `role_unassigned` action rows naming the scope, which is what the trail has
 * always recorded for grants.
 */
class UserRole extends Pivot
{
    protected $table = 'user_roles';

    public $incrementing = true;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'role_id',
        'scope_type',
        'scope_department_id',
    ];

    /**
     * Company unless the writer says otherwise. Explicit here rather than left
     * to the column default so an unsaved grant already reads as what it will
     * be, and so the guard sees a value instead of a hole.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'scope_type' => ScopeType::Company->value,
    ];

    /**
     * The scope type, or NULL for a stored value `ScopeType` does not define.
     *
     * ⚠️ NOT AN ENUM CAST ON PURPOSE. The column is a bare tinyint, and a cast
     * throws a ValueError on any value outside the enum — so one hand-edited
     * or badly imported row made every permission check for that user a 500,
     * the login and user listings included. Read leniently, an undefined type
     * is a grant that reaches nothing: `PermissionCache` skips it and the
     * listing shows it with no scope. The guard below still refuses to WRITE
     * one.
     */
    protected function scopeType(): Attribute
    {
        return Attribute::make(
            get: static fn (mixed $value): ?ScopeType => is_numeric($value) ? ScopeType::tryFrom((int) $value) : null,
            set: static fn (mixed $value): mixed => $value instanceof ScopeType ? $value->value : $value,
        );
    }

    protected static function booted(): void
    {
        static::saving(function (self $grant): void {
            $attributes = $grant->getAttributes();
            $scopeType = isset($attributes['scope_type']) ? (int) $attributes['scope_type'] : null;

            // Only a narrowed grant needs the role's name, so the common
            // company-wide write costs no extra query.
            $superAdminRole = $scopeType !== ScopeType::Company->value
                && Role::whereKey($attributes['role_id'] ?? null)->value('name') === User::SUPER_ADMIN_ROLE;

            $violations = (new GrantScopeGuard)->violations(
                $scopeType,
                isset($attributes['scope_department_id']) ? (int) $attributes['scope_department_id'] : null,
                $superAdminRole,
            );

            if ($violations !== []) {
                throw new GrantScopeException($violations[0]->message);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The role granted. Trashed roles resolve to null — a deleted role grants
     * nothing, exactly as it does through `User::roles()`.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * The unit a `Unit` grant is anchored at; null for the other two types.
     *
     * With trashed: a unit deleted after the grant was made still anchors it
     * (the resolver walks trashed units too), so an admin screen must still be
     * able to name it.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'scope_department_id')->withTrashed();
    }
}
