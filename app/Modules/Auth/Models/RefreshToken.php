<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RefreshToken Model
 *
 * Stores hashed refresh tokens in the database for secure token rotation.
 *
 * WHY a separate table (not Sanctum's personal_access_tokens):
 * - Sanctum tokens are access tokens with a specific lifecycle (short-lived).
 * - Refresh tokens have a fundamentally different lifecycle (long-lived, rotated).
 * - Mixing them in one table creates confusion about expiration semantics.
 * - Separate tables allow independent cleanup strategies (prune expired differently).
 * - The refresh token needs its own `revoked` flag and `expires_at` — these
 *   don't belong on Sanctum's token model.
 *
 * SECURITY:
 * - Only the HASH of the token is stored. The plaintext is returned once at creation.
 * - Token rotation: every refresh invalidates the old token and issues a new one.
 * - If a revoked token is used, it's a replay attack indicator.
 * - The `revoked` field enables immediate invalidation without waiting for expiry.
 *
 * NOTE: No HasAuditLog trait here. Refresh token CRUD is high-frequency and
 * already logged at the action level in AuthService. Model-level audit would
 * create excessive noise.
 */
class RefreshToken extends Model
{
    public $timestamps = false;

    protected $table = 'refresh_tokens';

    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
        'revoked',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked'    => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Check if this refresh token is still valid.
    public function isValid(): bool
    {
        return ! $this->revoked && $this->expires_at->isFuture();
    }
}