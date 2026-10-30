<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_location_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attendance_record_id')->unique()->constrained('attendance_records')->restrictOnDelete();
            $table->foreignId('work_site_id')->nullable()->constrained('work_sites')->restrictOnDelete();
            $table->foreignId('work_site_version_id')->nullable()->constrained('work_site_versions')->restrictOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('employee_work_site_assignments')->restrictOnDelete();
            $table->string('site_name', 150)->nullable();
            $table->decimal('site_latitude', 10, 7)->nullable();
            $table->decimal('site_longitude', 10, 7)->nullable();
            $table->decimal('radius_m', 10, 2)->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('distance_m', 12, 2)->nullable();
            $table->decimal('accuracy_m', 10, 2)->nullable();
            $table->dateTime('fix_at')->nullable();
            $table->boolean('is_mocked')->nullable();
            $table->string('reason', 40);
            $table->string('status', 20);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['status', 'attendance_record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_location_reviews');
    }
};
