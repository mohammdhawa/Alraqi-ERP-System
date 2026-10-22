<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Role Model
 *
 * A named bundle of permissions (e.g. "super_admin"). Users are granted roles,
 * and a role's permissions determine what its holders may do. The R in RBAC.
 *
 * `is_system` marks a built-in role the application depends on (super_admin).
 * System roles are protected from rename/delete in RoleService. `is_system` is
 * fillable so the seeder can set it, but the role form requests never accept it,
 * so it can't be flipped through the API.
 *
 * WHY HasAuditLog: what a role grants is the security posture of the system.
 * RoleService already writes action-level logs (role_created, …); the trait adds
 * the model-level rows WITH old/new values, so a rename or description change
 * is reconstructable, not just noted.
 */
class Role extends Model
{
    use HasAuditLog;

    /**
     * WHY SoftDeletes (C23): Stage 2's shared approval engine will reference
     * roles by id, and a hard delete would strand those rows. Access is not
     * affected — the global scope applies through the belongsToMany, so a
     * trashed role vanishes from $user->roles() and grants nothing, while its
     * user_roles and role_permissions rows survive so a restore is faithful.
     */
    use SoftDeletes;

    protected $fillable = [
        'name',
        'label',
        'description',
        'is_system',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /**
     * Permissions granted by this role.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    /**
     * Users who hold this role.
     *
     * Configured exactly like `User::roles()`, and it must stay that way: the
     * scope guard lives in `UserRole`'s saving hook, and a plain belongsToMany
     * here would let `$role->users()->attach(...)` insert raw pivot rows past
     * it — a unit grant with no unit, from the one direction nobody checked.
     * A user granted this role over two units appears twice.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')
            ->using(UserRole::class)
            ->withPivot(['id', 'scope_type', 'scope_department_id']);
    }
}
