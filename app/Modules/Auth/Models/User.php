<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

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
 * - If HR needs employee-specific fields, it creates an Employee model with a
 *   `user_id` FK — it does NOT modify this User model.
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
        'name',
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
     * Whether the user holds the given permission through any of their roles.
     *
     * The presence of this method is what activates the CheckPermission
     * middleware: until RBAC existed it had no way to evaluate permissions and
     * allowed everything through. Now every `permission:` route is enforced.
     *
     * Correctness over speed for now — this runs a query per check. Eager
     * loading / caching of the user's permission set can be layered on later
     * without changing this contract.
     */
    public function hasPermission(string $permission): bool
    {
        return $this->roles()
            ->whereHas('permissions', fn ($q) => $q->where('name', $permission))
            ->exists();
    }
}