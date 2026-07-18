<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Modules\Auth\Support\PermissionCache;
use App\Modules\HR\Models\Employee;
use App\Shared\Traits\HasAuditLog;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * User Model
 *
 * The central identity model for the ERP system.
 *
 * WHY it lives in Auth module:
 * - Authentication owns the user identity lifecycle.
 * - Other modules (HR, Finance) reference the user via relationships or user_id FK.
 *
 * IDENTITY / NAME (one fact, one place):
 * - This model holds NO `name` column. A user's display name is the name of the
 *   HR employee they are linked to, resolved through employee() (users.employee_id
 *   -> employees.id). Read it as `$user->employee?->name`; an account with no
 *   employee link simply has no name. Storing a copy here would let the two drift.
 *
 * MULTI-TENANT FUTURE:
 * - Add a `tenant_id` column + global scope.
 * - Or use a separate database per tenant with a connection switcher.
 * - The HasAuditLog trait will automatically include tenant context once
 *   AuditLogService is updated.
 */
class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use HasAuditLog;

    /**
     * The built-in role whose holders bypass every permission check. Its power
     * comes from the Gate::before bypass (see AppServiceProvider), NOT from
     * synced permission rows — the architecture forbids granting one role every
     * permission. Referenced by name so there is a single spelling of it.
     */
    public const SUPER_ADMIN_ROLE = 'super_admin';

    /**
     * Resolve the factory for this model.
     *
     * Required because the model lives outside App\Models, so Laravel's
     * convention-based factory discovery would look in the wrong namespace.
     */
    protected static function newFactory(): Factory
    {
        return UserFactory::new();
    }

    protected $fillable = [
        'email',
        'password',
        'is_active',
        'employee_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at'     => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
        ];
    }

    /**
     * Relationship to refresh tokens.
     *
     * A user can have multiple refresh tokens (e.g., one per device/session).
     * This enables "revoke all sessions" functionality.
     */
    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    /**
     * Notifications addressed to this user.
     *
     * CAVEAT: this deliberately SHADOWS the Notifiable trait's notifications()
     * relation. The ERP uses its own user_id-keyed notifications table
     * (erp-phase1-architecture.md §7.2) — Laravel's stock database channel and
     * `$user->notify()` are NOT wired up and would fail against this schema.
     * Create notification rows through NotificationService's sendTo* methods.
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * The HR employee profile linked to this login account, if any.
     *
     * The employee_id FK lives on this (users) table, so this is a belongsTo.
     * Inverse of Employee::user().
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Check if user account is active.
     *
     * Disabled accounts cannot authenticate. This is separate from
     * email verification — an admin can disable an account at any time.
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /**
     * Roles granted to this user (RBAC).
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    /**
     * Whether the user is a super admin (holds the built-in super_admin role).
     * Super admins bypass every permission check — see hasPermission() and the
     * Gate::before bypass in AppServiceProvider.
     */
    public function isSuperAdmin(): bool
    {
        return PermissionCache::isSuperAdmin($this);
    }

    /**
     * Whether the user holds the given permission.
     *
     * A super admin passes ANY ability (that is what the bypass means). Everyone
     * else is checked against their resolved permission set. The set is read
     * through PermissionCache, so this is served from cache rather than a query
     * per request, with invalidation handled by the global version stamp.
     *
     * This method being present on the identity model is also what makes the
     * CheckPermission middleware enforce (it calls hasPermission directly).
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return in_array($permission, PermissionCache::names($this), true);
    }

    /**
     * Every permission name the user effectively holds, de-duplicated — the
     * payload the login and /me flows expose so a client can build a
     * permission-aware UI in one round-trip. A super admin resolves to the whole
     * catalogue (their bypass grants everything), so the UI reflects that.
     *
     * @return array<int, string>
     */
    public function allPermissionNames(): array
    {
        return PermissionCache::names($this);
    }
}