<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add request_id to audit_logs.
 *
 * WHY: the audit trail recorded who/what/when/where but carried no correlation
 * id, so an audit row could not be tied to the log lines, the response the
 * client saw, or the other rows written by the same request (GAP-5). A single
 * API call routinely writes several rows — the request-level `api_request` row
 * from AuditLogMiddleware plus one model-level row per touched model — and
 * nothing linked them.
 *
 * Nullable because rows written outside HTTP (seeders, console commands, queued
 * jobs) legitimately have no inbound request. Indexed because the query this
 * column exists to serve — "show me everything that happened during request X"
 * — is a lookup, and it is the query someone runs while an incident is open.
 *
 * The value is whatever SetRequestContext resolved and echoed to the client as
 * X-Request-Id, so a user reporting a failure can quote the header and have it
 * lead straight here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('request_id', 64)
                ->nullable()
                ->after('user_agent')
                ->index()
                ->comment('Correlation id echoed to the client as X-Request-Id. Null outside HTTP.');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['request_id']);
            $table->dropColumn('request_id');
        });
    }
};
