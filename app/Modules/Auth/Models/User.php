<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
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
     * Check if user account is active.
     *
     * Disabled accounts cannot authenticate. This is separate from
     * email verification — an admin can disable an account at any time.
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }
}