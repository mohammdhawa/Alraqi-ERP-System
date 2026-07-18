<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

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
     * Roles that include this permission.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }
}
