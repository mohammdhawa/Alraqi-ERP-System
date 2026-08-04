<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `sector` (الجهة) to departments.
 *
 * A separate migration rather than an edit to create_departments_table: that
 * one has shipped, and rewriting a shipped migration means every environment
 * that already ran it silently disagrees with the file that claims to describe
 * it.
 *
 * WHY this is a column and not a tier: ratified decision #1
 * (docs/phase-1.5-foundation-truth-up.md §2). The reasoning lives in full on
 * the Sector enum; the short version is that a tier would need two invented
 * rows that exist in no org chart and would take the tree to five levels deep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            // NULLABLE IS THE NORMAL STATE, not an afterthought: of the ~118
            // rows in the company tree, only the 13 divisions carry a value.
            // Everything else — the root, مجلس الإدارة, مكتب المدير العام, and
            // every قسم and مكتب — is NULL and resolves its sector by walking up
            // to the nearest ancestor that has one (Department::sector()).
            //
            // The value is NEVER copied downward. A copy on a section would
            // survive that section being re-parented into the other sector, and
            // because the walk stops at the first non-null ancestor it would
            // then shadow the truth from the whole subtree beneath it. The rule
            // "sector may only be non-null at level 2" is enforced in
            // DepartmentHierarchyGuard, which every write path crosses — the
            // schema cannot express it.
            //
            // unsignedTinyInteger, backed by the Sector PHP enum: identical
            // treatment to `level`, for identical reasons — the int is the
            // fact, the enum is the lens, and a sector added later is one enum
            // case rather than an ALTER.
            //
            // Indexed: "every unit in the operational bodies" is a reporting
            // query, and it filters on this column alone.
            $table->unsignedTinyInteger('sector')->nullable()->index()->after('level');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropIndex(['sector']);
            $table->dropColumn('sector');
        });
    }
};
