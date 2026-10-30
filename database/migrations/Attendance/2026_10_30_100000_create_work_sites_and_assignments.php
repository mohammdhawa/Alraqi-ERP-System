<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_sites', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('work_site_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_site_id')->constrained('work_sites')->restrictOnDelete();
            $table->string('name', 150);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('radius_m', 10, 2);
            $table->dateTime('effective_from');
            $table->dateTime('effective_to')->nullable();
            $table->timestamps();
            $table->unique(['work_site_id', 'effective_from']);
            $table->index(['work_site_id', 'effective_to']);
        });

        Schema::create('employee_work_site_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('work_site_id')->constrained('work_sites')->restrictOnDelete();
            $table->dateTime('effective_from');
            $table->dateTime('effective_to')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'effective_from'], 'employee_site_from_unique');
            $table->index(['employee_id', 'effective_to'], 'employee_site_to_index');
            $table->index(['work_site_id', 'effective_to'], 'site_assignment_to_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_work_site_assignments');
        Schema::dropIfExists('work_site_versions');
        Schema::dropIfExists('work_sites');
    }
};
