<?php

declare(strict_types=1);

use App\Modules\Auth\Support\PermissionCache;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_logs')) {
            DB::table('audit_logs')->whereIn('auditable_type', [
                'attendance_location_review',
                'employee_work_site_assignment',
                'work_site_version',
                'work_site',
            ])->delete();
        }

        Schema::dropIfExists('attendance_location_reviews');
        Schema::dropIfExists('employee_work_site_assignments');
        Schema::dropIfExists('work_site_versions');
        Schema::dropIfExists('work_sites');

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->whereIn('name', [
                'attendance.records.approveLocation',
                'attendance.work_sites.manage',
                'attendance.work_sites.view',
            ])->delete();
            PermissionCache::flush();
        }
    }

    public function down(): void
    {
        // The owner requested permanent deletion of these feature records.
    }
};
