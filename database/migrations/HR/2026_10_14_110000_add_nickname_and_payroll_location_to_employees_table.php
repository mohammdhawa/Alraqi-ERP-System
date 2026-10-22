<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اللقب and مكان الراتب on employees — needed in v1 (project owner,
 * 2026-10-14; work order §8). The workbook carries both and the ERP had a
 * column for neither, so the importer had nowhere to put them.
 *
 * `nickname` — اللقب, the kunya (أبو عبد الرحمن). Identity: it is how much of
 * the site staff is addressed, on the phone and on site. Free text.
 *
 * `payroll_location` — مكان الراتب, the payroll cost centre the salary is
 * drawn from: الآليات الثقيلة، مكتب رئيسي - هندسي، or a project site. Free text
 * until Finance or Projects provide a table of cost centres, as `workplace` is
 * until Projects lands. It is a pay fact, so EmployeeResource returns it only
 * inside the viewSalary-gated pay block, and EmployeeRequest gates the write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('nickname', 100)->nullable()->after('mother_name');
            $table->string('payroll_location', 150)->nullable()->after('sham_cash_account');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['nickname', 'payroll_location']);
        });
    }
};
