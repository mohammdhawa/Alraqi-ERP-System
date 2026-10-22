<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Modules\Auth\Support\PermissionCache;
use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Permission Model
 *
 * A single capability named {module}.{resource}.{action} (e.g.
 * "hr.employees.view"). The `name` is what routes reference via the
 * `permission:` middleware. Permissions are grouped into roles.
 *
 * WHY HasAuditLog: permissions are the catalogue routes are guarded by; a
 * renamed or deleted permission silently changes who can do what, so those
 * writes carry old/new values in the audit trail like every other
 * security-relevant model.
 */
class Permission extends Model
{
    use HasAuditLog;

    protected $fillable = [
        'name',
        'label',
        'module',
        'description',
    ];

    /**
     * THE CATALOGUE CHANGED, SO EVERY CACHED PERMISSION SET IS STALE.
     *
     * PermissionCache's contract is that any change to roles, permissions or
     * their assignments must flush. That was implemented for exactly one caller
     * — PermissionSeeder called flush() itself — which left every other door
     * open: a permission created or renamed from the admin UI these Arabic
     * labels are seeded for, from a data migration, or from tinker still left
     * the cache stale for up to the full hour of TTL.
     *
     * It bites hardest on super admins, who are the people most likely to be
     * signed in while the catalogue changes: their cached entry IS the whole
     * catalogue (the bypass expands to whatever is seeded), so a new permission
     * leaves them without it, and therefore without the UI it gates, with
     * nothing on screen to suggest anything happened.
     *
     * ON THE MODEL, so the invariant holds for every write path rather than for
     * the one that remembered. `saved` fires on an updateOrCreate that changed
     * nothing, which is what makes a re-seed flush; the version bump is a single
     * cache increment, so doing it per row is cheap.
     *
     * The ROLE side is already covered by RoleService, which owns every write
     * that changes what a role grants (including the role_permissions pivot,
     * which no model event would see).
     */
    protected static function booted(): void
    {
        static::saved(static fn () => PermissionCache::flush());
        static::deleted(static fn () => PermissionCache::flush());
    }

    /**
     * Roles that include this permission.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }
}
