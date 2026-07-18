<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles');
    }
}
