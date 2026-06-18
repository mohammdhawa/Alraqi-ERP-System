<?php

declare(strict_types=1);

namespace App\Shared\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * AuditLogService
 *
 * Central service for writing audit log entries. All audit writes
 * go through this class, whether triggered by the HasAuditLog trait,
 * AuditLogMiddleware, or manual calls from services.
 *
 * WHY a dedicated service:
 * - Single responsibility: one class owns the audit write format.
 * - Easy to swap storage (DB → queue → external service) later.
 * - Testable in isolation (mock this in unit tests).
 * - Consistent metadata (IP, user agent, user ID) on every entry.
 *
 * PERFORMANCE:
 * - Uses direct DB::table() inserts, not an Eloquent model, to avoid
 *   triggering model events (which would cause infinite recursion via HasAuditLog).
 * - In high-throughput scenarios, swap the insert for a queued job.
 *
 * MULTI-TENANT FUTURE:
 * - Add a `tenant_id` column to the audit_logs table.
 * - Inject the current tenant from a TenantContext service here.
 * - All existing callers remain unchanged.
 */
class AuditLogService
{
    // Log an audit event for a model change.
    public function log(
        string $event,
        Model $auditable,
        array $oldValues = [],
        array $newValues = [],
        ?string $description = null,
    ): void {
        DB::table('audit_logs')->insert([
            'user_id'        => Auth::id(),
            'event'          => $event,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id'   => $auditable->getKey(),
            'old_values'     => json_encode($this->sanitize($oldValues), JSON_THROW_ON_ERROR),
            'new_values'     => json_encode($this->sanitize($newValues), JSON_THROW_ON_ERROR),
            'description'    => $description,
            'ip_address'     => Request::ip(),
            'user_agent'     => Request::userAgent(),
            'created_at'     => now(),
        ]);
    }

    /**
     * Log a custom action (not tied to a model change).
     *
     * Used for events like "user_logged_in", "password_changed",
     * "tokens_revoked", etc.
     */
    public function logAction(
        string $event,
        ?string $description = null,
        array $metadata = [],
    ): void {
        DB::table('audit_logs')->insert([
            'user_id'        => Auth::id(),
            'event'          => $event,
            'auditable_type' => null,
            'auditable_id'   => null,
            'old_values'     => json_encode([], JSON_THROW_ON_ERROR),
            'new_values'     => json_encode($this->sanitize($metadata), JSON_THROW_ON_ERROR),
            'description'    => $description,
            'ip_address'     => Request::ip(),
            'user_agent'     => Request::userAgent(),
            'created_at'     => now(),
        ]);
    }

    /**
     * Remove sensitive fields from audit data.
     *
     * SECURITY: Passwords, tokens, and secrets must never appear in audit logs.
     * This list should be extended as new sensitive fields are added to models.
     */
    private function sanitize(array $data): array
    {
        $sensitiveFields = [
            'password',
            'password_hash',
            'remember_token',
            'token',
            'refresh_token',
            'secret',
            'two_factor_secret',
            'two_factor_recovery_codes',
        ];

        return collect($data)
            ->map(function ($value, $key) use ($sensitiveFields) {
                if (in_array($key, $sensitiveFields, true)) {
                    return '***REDACTED***';
                }
                return $value;
            })
            ->toArray();
    }
}