<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Modules\Auth\Enums\DevicePlatform;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DeviceToken — one device's push token, owned by the session that registered
 * it. See the migration for why the token is unique across users.
 *
 * NOT AUDITED, like RefreshToken: a device re-registers on every app start,
 * and a row per registration would bury the log. The sign-ins and sign-outs
 * that create and end sessions are audited where they happen.
 */
class DeviceToken extends Model
{
    protected $fillable = [
        'user_id',
        'token',
        'platform',
        'session_id',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'platform'     => DevicePlatform::class,
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
