<?php

declare(strict_types=1);

namespace App\Shared\Traits;

use App\Shared\Services\AuditLogService;

/**
 * HasAuditLog Trait
 *
 * Attach to any Eloquent model that needs automatic audit logging.
 * Records created/updated/deleted events with old and new values.
 *
 * Usage: `use HasAuditLog;` in any model.
 *
 * WHY a trait on models:
 * - Declarative: adding audit logging is a one-line change.
 * - Leverages Eloquent's boot mechanism — no observer classes to register.
 * - Captures old/new values at the model level (most accurate source).
 * - Keeps AuditLogService as the single writer (trait delegates to it).
 *
 * WHY not observers:
 * - Observers require registration in a service provider.
 * - As the ERP grows to 30+ models, managing observer registrations becomes overhead.
 * - Traits are self-contained: the model declares its own behavior.
 *
 * SECURITY: Audit logs are append-only by convention. The audit_logs table
 * should have restricted DELETE/UPDATE permissions at the database level
 * in production (grant INSERT + SELECT only to the app user).
 */
trait HasAuditLog
{
    public static function bootHasAuditLog(): void
    {
        static::created(function ($model) {
            static::logAuditEvent($model, 'created', [], $model->getAttributes());
        });

        static::updated(function ($model) {
            // Only log if actual attribute values changed (not just timestamps)
            $changed = $model->getChanges();
            unset($changed['updated_at']);

            if (empty($changed)) {
                return;
            }

            $oldValues = collect($model->getOriginal())
                ->only(array_keys($changed))
                ->toArray();

            static::logAuditEvent($model, 'updated', $oldValues, $changed);
        });

        static::deleted(function ($model) {
            static::logAuditEvent($model, 'deleted', $model->getOriginal(), []);
        });
    }

    /**
     * Delegate to AuditLogService.
     *
     * Uses the container to resolve AuditLogService so it remains testable
     * and doesn't require static coupling.
     */
    private static function logAuditEvent(
        $model,
        string $event,
        array $oldValues,
        array $newValues,
    ): void {
        try {
            /** @var AuditLogService $auditService */
            $auditService = app(AuditLogService::class);

            $auditService->log(
                event: $event,
                auditable: $model,
                oldValues: $oldValues,
                newValues: $newValues,
            );
        } catch (\Throwable $e) {
            // Audit logging must NEVER break the main operation.
            // Log the failure and move on. In production, this triggers
            // an alert via your monitoring (Sentry, Datadog, etc.).
            report($e);
        }
    }
}