<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Role Model
 *
 * A named bundle of permissions (e.g. "admin"). Users are granted roles, and
 * a role's permissions determine what its holders may do. The R in RBAC.
 */
class Role extends Model
{
    protected $fillable = [
        'name',
        'description',
    ];

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
