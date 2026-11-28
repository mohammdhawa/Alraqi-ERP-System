<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * employee_documents.document_id: the sent document a row files on the
 * employee's record once they answer it (documents to employees). Null for
 * every row HR filed by hand or the importer loaded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_documents', function (Blueprint $table) {
            $table->foreignId('document_id')->nullable()->after('expires_at');

            // Unique: one row per answered document, in the schema as well as in
            // RecordFiling's guard. The many nulls never collide (MySQL and SQLite
            // alike). Named and created before the foreign key, so MySQL's
            // constraint reuses it instead of adding a second index; SQLite gets
            // none from a foreign key.
            $table->unique('document_id', 'employee_documents_document_id_unique');
            $table->foreign('document_id')->references('id')->on('documents')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_documents', function (Blueprint $table) {
            $table->dropForeign(['document_id']);
            $table->dropUnique('employee_documents_document_id_unique');
            $table->dropColumn('document_id');
        });
    }
};
