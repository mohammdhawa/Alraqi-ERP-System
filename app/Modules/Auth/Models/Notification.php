<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Notification Model
 *
 * A user-facing notification with a title/body, an optional type, and an
 * optional polymorphic reference to the record it concerns.
 *
 * NOTE: No HasAuditLog trait. Notifications are high-frequency, system-created
 * records (and read-state toggles even more so) — model-level audit would
 * flood the audit log with noise, matching the rationale on RefreshToken.
 */
class Notification extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'body',
        'type',
        'reference_type',
        'reference_id',
        'is_read',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
        ];
    }

    /**
     * The user this notification belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The domain record this notification refers to (employee, department, …).
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
