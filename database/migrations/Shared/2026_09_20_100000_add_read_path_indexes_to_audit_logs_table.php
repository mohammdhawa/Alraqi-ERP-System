<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index the two queries the audit READ surface actually runs.
 *
 * The table was indexed for the write-era questions — one column at a time,
 * before anything queried it. Then `AuditTrailService` shipped a filter set and
 * `API_DOCUMENTATION.md` §9.5 told every client to open the screen with a
 * particular query, and neither was checked against the indexes. The service's
 * own rule ("adding a key here means adding an index first") had been applied to
 * the sort allow-list and to nothing else.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * 1. (event, created_at) — THE DEFAULT SCREEN
 * ──────────────────────────────────────────────────────────────────────────
 * §9.5 and the frontend work order both tell clients to default to
 * `event_group=model,auth`, which expands to
 *
 *   WHERE event IN (16 values) ORDER BY created_at DESC, id DESC LIMIT 15
 *
 * against a table that is ~99% `api_request` rows. With only the two
 * single-column indexes, MySQL's cheapest plan is to walk `created_at`
 * backwards and discard roughly 99 rows for every one it keeps — about 1,500
 * rows read to fill the first page, and worse on every page after it.
 *
 * The composite lets it read only matching rows. It does NOT remove the sort —
 * an IN over sixteen values is sixteen ranges, each ordered by `created_at`
 * internally but not against each other, so a filesort over the matches
 * remains. That is the honest limit of what an index can do for this shape, and
 * sorting the 1% beats scanning the 100%. If this ever stops being enough the
 * next step is partitioning by month, which the original migration already
 * names.
 *
 * THE STANDALONE `event` INDEX IS DROPPED, not kept beside it: `event` is the
 * leftmost column of the composite, so every lookup the old index served is
 * served by the new one. Keeping both would buy nothing and cost a second index
 * write on every INSERT — on the table that takes the most INSERTs in the
 * system, one per authenticated request.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * 2. ip_address — A DOCUMENTED FILTER ON AN UNINDEXED COLUMN
 * ──────────────────────────────────────────────────────────────────────────
 * `?ip_address=` has been a first-class filter since the read surface shipped,
 * on a column with no index, on the one table that grows without bound — so
 * "which requests came from this address" was a full scan. `AuditLogTest`
 * routes every one of its list assertions through that filter to scope them to
 * its own rows, which means the suite was exercising the slowest path in the
 * service as if it were the normal one.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * WHY NOT MORE
 * ──────────────────────────────────────────────────────────────────────────
 * `user_id`, `request_id` and `(auditable_type, auditable_id)` already have
 * theirs, and each is selective enough that the trailing `ORDER BY created_at`
 * sorts a handful of rows. Every index here is paid for on every INSERT, so
 * these are the two that were missing and not one more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['event', 'created_at'], 'audit_logs_event_created_at_index');
            $table->index('ip_address');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['event']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index('event');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_event_created_at_index');
            $table->dropIndex(['ip_address']);
        });
    }
};
