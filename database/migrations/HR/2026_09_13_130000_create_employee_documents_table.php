<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create employee_documents — the files held on a person.
 *
 * The new-hire intake form collects four images on every hire (ID front, ID
 * back, graduation certificate, Sham Cash account photo) and the HR folder
 * holds several hundred more PDFs and images against named staff.
 *
 * ⚠️ SCHEMA ONLY IN THIS BATCH — THERE ARE NO ENDPOINTS YET, ON PURPOSE.
 * The table exists now because the Batch 3/4 importer attaches the intake
 * form's images as it creates each employee, and a table that arrives after the
 * import is a second migration against real rows. Upload, storage disk, size
 * and type limits, and signed download URLs are the Documents module's
 * problem — Stage 2 of the merge plan, ported from the legacy system, which
 * already solves all of them in production. Inventing a second file pipeline
 * here and unifying it later is exactly the mistake the merge plan orders the
 * stages to avoid.
 *
 * ⚠️ ONE TYPE IS SENSITIVE. `ShamCash` is a photograph of a payment account,
 * which is the account number. Whoever writes the read endpoint must gate that
 * type behind hr.employees.viewSalary, exactly as the `sham_cash_account`
 * column is gated (C11). A document list that returns every type to anyone
 * holding hr.employees.view would walk straight around the column's gate.
 *
 * WHY NOT THE SHARED POLYMORPHIC `documents` TABLE the reference schema
 * proposes: that table belongs to the Documents module which has not landed
 * yet. A dedicated table with a real FK is cheaper to get right now, and
 * migrating rows into a polymorphic table later is a straightforward insert —
 * whereas guessing the shared table's shape before its own module defines it is
 * how two incompatible document stores get built.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->unsignedTinyInteger('type');   // EmployeeDocumentType
            $table->string('title')->nullable();

            // A path on the configured disk, never a URL and never the bytes.
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            // Who filed it. nullOnDelete, and nullable besides: the importer has
            // no authenticated user, and a null here honestly says "loaded by
            // the migration" rather than crediting it to whoever ran the script.
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
    }
};
