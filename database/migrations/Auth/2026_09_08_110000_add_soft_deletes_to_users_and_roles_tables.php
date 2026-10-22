<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add soft deletes to users and roles.
 *
 * WHY (C23 — "any table another module might FK into gets softDeletes()"):
 *
 * USERS was the costly omission. Four tables point at it, and the delete was a
 * HARD one, so removing an account had consequences nobody had chosen:
 *
 *   - audit_logs.user_id is nullOnDelete. Deleting a user therefore NULLED
 *     their user_id across the ENTIRE audit trail, turning every action they
 *     ever took anonymous. On a table that is append-only by design — the
 *     production database user is meant to hold INSERT and SELECT only — that
 *     is unrecoverable attribution loss, and it happens on exactly the people
 *     an audit is most often asked about: the ones who left.
 *   - notifications.user_id is cascadeOnDelete, so their notification history
 *     vanished with them.
 *   - refresh_tokens and user_roles cascaded too.
 *
 * The identity itself must also stay resolvable, for the same reason §5.9 gives
 * for never hard-deleting an employee: other modules hold references to it and
 * history has to keep making sense after someone leaves.
 *
 * ROLES is the cheaper, more forward-looking half. Its only current referents
 * are the two pivots, but Stage 2's shared approval engine will reference roles
 * ("this step is approved by role X"), and a hard delete would strand those
 * rows. Soft-deleting a role does NOT leave its holders with access: the
 * SoftDeletes global scope applies through the belongsToMany, so a trashed role
 * disappears from $user->roles() and contributes no permissions.
 *
 * ACCEPTED TRADE-OFF — the unique indexes on users.email, users.employee_id and
 * roles.name still count trashed rows, so a soft-deleted account's email cannot
 * be reused until the row is force-deleted. That is deliberate and left as-is:
 * silently letting a new person inherit a departed one's email would let them
 * inherit that identity's meaning in the audit trail too. Restoring the row is
 * the intended path, and force-deleting is the escape hatch. Validation is
 * unchanged for the same reason — Laravel's `unique` rule counts trashed rows,
 * which matches these indexes exactly, so a collision is still a clean 422
 * rather than a database error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
