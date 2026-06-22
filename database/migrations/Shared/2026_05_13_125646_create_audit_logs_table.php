<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create audit_logs table.
 *
 * SCHEMA DESIGN:
 *
 *   This table is append-only by design. In production, restrict the
 *   database user to INSERT + SELECT only (no UPDATE or DELETE).
 *
 *   auditable_type + auditable_id (POLYMORPHIC):
 *   - Nullable because some events are action-level (login, logout)
 *     and not tied to a specific model instance.
 *   - Polymorphic index covers model-level audit queries.
 *
 *   old_values / new_values (JSON):
 *   - Stored as JSON for schema flexibility. Different models have different fields.
 *   - Sensitive values are redacted by AuditLogService before storage.
 *
 *   INDEXES:
 *   - (auditable_type, auditable_id): "show me all changes to this record"
 *   - (user_id): "show me all actions by this user"
 *   - (event): "show me all login events" or "show me all deletions"
 *   - (created_at): "show me everything in this time range" (compliance reports)
 *
 * SCALING:
 *   Audit logs grow fast in an ERP. Plan for:
 *   1. Table partitioning by created_at (monthly partitions).
 *   2. Archival: move records older than 1 year to cold storage.
 *   3. Or: route writes to a dedicated logging database/service.
 *
 * MULTI-TENANT:
 *   Add a `tenant_id` column when multi-tenancy is implemented.
 *   Add it to the composite indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('The user who performed the action. Null for system events.');

            $table->string('event', 50)
                ->index()
                ->comment('Event type: created, updated, deleted, user_logged_in, etc.');

            $table->string('auditable_type')
                ->nullable()
                ->comment('Model class (polymorphic). Null for action-level events.');

            $table->unsignedBigInteger('auditable_id')
                ->nullable()
                ->comment('Model ID (polymorphic). Null for action-level events.');

            $table->json('old_values')
                ->comment('Previous values before the change.');

            $table->json('new_values')
                ->comment('New values after the change.');

            $table->string('description')
                ->nullable()
                ->comment('Human-readable description of the event.');

            $table->ipAddress('ip_address')
                ->nullable();

            $table->string('user_agent')
                ->nullable();

            $table->timestamp('created_at')
                ->useCurrent()
                ->index();

            // Polymorphic index for "all changes to this model instance"
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};