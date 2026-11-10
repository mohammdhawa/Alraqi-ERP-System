<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkSiteRemovalMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_removes_feature_request_audits_and_keeps_other_attendance_audits(): void
    {
        DB::table('audit_logs')->delete();   // the seeded schedules' own rows

        foreach ([
            ['api_request', null, 'GET api/attendance/work-sites → 200'],
            ['api_request', null, 'POST api/attendance/work-site-assignments → 201'],
            ['api_request', null, 'PATCH api/attendance/records/9/location-review/approve → 200'],
            ['api_request', null, 'POST api/attendance/records/location-review/approve → 200'],
            ['created', 'work_site', 'Created a site'],
            ['api_request', null, 'GET api/attendance/records → 200'],
            ['updated', 'attendance_record', 'Corrected a check-out'],
        ] as [$event, $type, $description]) {
            DB::table('audit_logs')->insert([
                'event' => $event,
                'auditable_type' => $type,
                'description' => $description,
            ]);
        }

        $migration = require database_path('migrations/Attendance/2026_11_02_100000_remove_attendance_work_sites.php');
        $migration->up();

        $this->assertSame(
            ['Corrected a check-out', 'GET api/attendance/records → 200'],
            DB::table('audit_logs')->orderBy('description')->pluck('description')->all(),
        );
    }
}
