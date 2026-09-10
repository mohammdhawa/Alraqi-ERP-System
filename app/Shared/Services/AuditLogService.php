<?php

declare(strict_types=1);

namespace App\Shared\Services;

use App\Shared\Support\RequestContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
    /**
     * WHO/WHERE is INJECTED, never captured (C1).
     *
     * This class previously imported Illuminate\Support\Facades\Request and
     * called Request::ip() / Request::userAgent() inline, plus Auth::id() —
     * from a service reached by AuthService, UserService, RoleService and every
     * model via HasAuditLog (GAP-6). That made an HTTP context a hidden
     * requirement of auditing anything, and wrote silent nulls from the CLI.
     * RequestContext supplies the same four facts from one place and returns
     * honest values outside HTTP.
     */
    public function __construct(
        private readonly RequestContext $context,
    ) {}

    // Log an audit event for a model change.
    public function log(
        string $event,
        Model $auditable,
        array $oldValues = [],
        array $newValues = [],
        ?string $description = null,
    ): void {
        DB::table('audit_logs')->insert([
            'user_id'        => $this->context->userId(),
            'event'          => $event,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id'   => $auditable->getKey(),
            'old_values'     => $this->encode($oldValues),
            'new_values'     => $this->encode($newValues),
            'description'    => $description,
            'ip_address'     => $this->context->ip(),
            'user_agent'     => $this->context->userAgent(),
            'request_id'     => $this->context->requestId(),
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
            'user_id'        => $this->context->userId(),
            'event'          => $event,
            'auditable_type' => null,
            'auditable_id'   => null,
            'old_values'     => null,
            'new_values'     => $this->encode($metadata),
            'description'    => $description,
            'ip_address'     => $this->context->ip(),
            'user_agent'     => $this->context->userAgent(),
            'request_id'     => $this->context->requestId(),
            'created_at'     => now(),
        ]);
    }

    /**
     * Sanitize and JSON-encode a value set, or NULL when there is nothing to
     * record — the columns are nullable for exactly this (created has no old
     * values, deleted has no new ones).
     */
    private function encode(array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        return json_encode($this->sanitize($values), JSON_THROW_ON_ERROR);
    }

    /**
     * Remove sensitive fields from audit data.
     *
     * SECURITY: Passwords, tokens, and secrets must never appear in audit logs.
     * This list must be extended in the SAME COMMIT as any new sensitive field
     * on any model (C14).
     *
     * NOT ONLY CREDENTIALS. The standard's C11 names national id and account
     * numbers as sensitive alongside salary, and this list previously held
     * credentials only — so every employee create/update wrote `national_id` to
     * audit_logs.new_values in cleartext, on a table that is append-only by
     * design and therefore cannot be scrubbed afterwards. A field gated in the
     * Resource but recorded verbatim here is not gated.
     *
     * Batch 2 adds `sham_cash_account` (a 16-digit payment account) and
     * `agreed_salary`; both are pre-registered here so the import cannot land
     * before the redaction does.
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
            // Sensitive personal / financial data (C11, C14).
            'national_id',
            'salary',
            'agreed_salary',
            'sham_cash_account',
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
