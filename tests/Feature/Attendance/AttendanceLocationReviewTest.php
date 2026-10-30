<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Models\EmployeeWorkSiteAssignment;
use App\Modules\Attendance\Models\WorkSite;
use App\Modules\Attendance\Services\WorkSiteAssignmentService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceLocationReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 08:00:00');
        Storage::fake('local');
        config(['attendance.location_review.enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(): Employee
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->create(['employee_id' => $employee->id, 'must_change_password' => false]);
        Sanctum::actingAs($user);

        return $employee;
    }

    private function site(Employee $employee, float $radius = 100): int
    {
        $sites = app(WorkSiteAssignmentService::class);
        $site = $sites->createSite(['name' => 'Main site', 'latitude' => 33.5, 'longitude' => 36.3, 'radius_m' => $radius]);
        $sites->assign(['employee_id' => $employee->id, 'work_site_id' => $site->id]);

        return $site->id;
    }

    private function record(array $overrides = [], int $type = 1): TestResponse
    {
        return $this->postJson('/api/me/attendance', array_merge([
            'type' => $type,
            'latitude' => 33.5,
            'longitude' => 36.3,
            'recorded_at' => '2026-10-30T08:01:00+03:00',
            'location_accuracy_m' => 5,
            'location_fix_at' => '2026-10-30T08:00:50+03:00',
            'location_is_mocked' => false,
            'selfie' => UploadedFile::fake()->create('selfie.jpg', 100, 'image/jpeg'),
        ], $overrides));
    }

    public function test_inside_is_in_process_and_employee_sees_only_public_evidence(): void
    {
        $employee = $this->employee();
        $siteId = $this->site($employee);

        $this->record(['location_is_mocked' => 'false'])->assertCreated()
            ->assertJsonPath('data.location_review.status', 'in_process')
            ->assertJsonPath('data.location_review.reason', 'inside')
            ->assertJsonPath('data.location_review.site.id', $siteId)
            ->assertJsonPath('data.location_review.site.name', 'Main site')
            ->assertJsonMissingPath('data.location_review.site.latitude')
            ->assertJsonMissingPath('data.location_review.approved_by')
            ->assertJsonMissingPath('data.location_review.note');
        $this->assertDatabaseCount('attendance_location_reviews', 1);
    }

    public function test_outside_and_uncertain_fixes_are_saved_and_flagged(): void
    {
        $employee = $this->employee();
        $this->site($employee);

        $this->record(['latitude' => 33.502])->assertCreated()
            ->assertJsonPath('data.location_review.status', 'for_review')
            ->assertJsonPath('data.location_review.reason', 'outside');
        $this->assertSame(1, AttendanceRecord::accepted()->count());
    }

    public function test_no_site_and_disabled_feature_keep_the_record(): void
    {
        $this->employee();
        $this->record()->assertCreated()->assertJsonPath('data.location_review.reason', 'no_site');
        $this->assertDatabaseCount('attendance_location_reviews', 1);

        config(['attendance.location_review.enabled' => false]);
        Carbon::setTestNow('2026-10-31 08:02:00');
        $this->record(['recorded_at' => '2026-10-31T08:01:00+03:00'])->assertCreated()
            ->assertJsonPath('data.location_review', null);
        $this->assertDatabaseCount('attendance_location_reviews', 1);
    }

    public function test_retry_keeps_the_first_review_snapshot(): void
    {
        $employee = $this->employee();
        $this->site($employee);
        $uuid = '5ca4216c-a177-45e0-b2cf-69d588916932';

        $this->record(['client_uuid' => $uuid])->assertCreated()
            ->assertJsonPath('data.location_review.reason', 'inside');
        $this->record(['client_uuid' => $uuid, 'latitude' => 34.0])->assertOk()
            ->assertJsonPath('data.location_review.reason', 'inside');
        $this->assertDatabaseCount('attendance_location_reviews', 1);
    }

    public function test_check_out_receives_its_own_review(): void
    {
        $employee = $this->employee();
        $this->site($employee);
        $this->record()->assertCreated();
        Carbon::setTestNow('2026-10-30 16:01:00');
        $this->record([
            'recorded_at' => '2026-10-30T16:00:00+03:00',
            'location_fix_at' => '2026-10-30T15:59:50+03:00',
            'latitude' => 33.502,
        ], AttendanceEventType::CheckOut->value)->assertCreated()
            ->assertJsonPath('data.location_review.reason', 'outside');
        $this->assertDatabaseCount('attendance_location_reviews', 2);
        $this->assertSame(2, AttendanceRecord::accepted()->count());
    }

    public function test_reason_precedence_for_mock_time_accuracy_and_boundary(): void
    {
        $cases = [
            [['location_is_mocked' => true, 'location_fix_at' => null, 'location_accuracy_m' => null], 'mock_location'],
            [['location_fix_at' => null, 'location_accuracy_m' => null], 'fix_time_invalid'],
            [['location_fix_at' => '2026-10-30T08:01:06+03:00'], 'fix_time_invalid'],
            [['location_accuracy_m' => null], 'low_accuracy'],
            [['location_accuracy_m' => 80, 'latitude' => 33.50085], 'low_accuracy'],
            [['location_accuracy_m' => 10, 'latitude' => 33.50085], 'boundary_uncertain'],
        ];

        foreach ($cases as [$overrides, $reason]) {
            $employee = $this->employee();
            $this->site($employee);
            $this->record($overrides)->assertCreated()
                ->assertJsonPath('data.location_review.reason', $reason)
                ->assertJsonPath('data.location_review.status', 'for_review');
        }
    }

    public function test_inactive_site_takes_precedence_over_bad_gps(): void
    {
        $employee = $this->employee();
        $id = $this->site($employee);
        WorkSite::findOrFail($id)
            ->update(['archived_at' => '2026-10-30 08:00:30']);

        $this->record(['location_is_mocked' => true])->assertCreated()
            ->assertJsonPath('data.location_review.reason', 'inactive_site');
    }

    public function test_an_offline_event_uses_the_old_assignment_and_site_version(): void
    {
        $employee = $this->employee();
        $sites = app(WorkSiteAssignmentService::class);
        $firstId = $this->site($employee);
        $second = $sites->createSite(['name' => 'Second', 'latitude' => 34.0, 'longitude' => 37.0, 'radius_m' => 100]);
        $assignment = EmployeeWorkSiteAssignment::where('employee_id', $employee->id)->sole();

        Carbon::setTestNow('2026-10-30 08:10:00');
        $sites->updateSite(WorkSite::findOrFail($firstId), [
            'latitude' => 33.6, 'effective_from' => '2026-10-30T08:10:00+03:00',
        ]);
        $sites->endAssignment($assignment, '2026-10-30T08:10:00+03:00');
        $sites->assign(['employee_id' => $employee->id, 'work_site_id' => $second->id]);

        $this->record([
            'recorded_at' => '2026-10-30T08:05:00+03:00',
            'location_fix_at' => '2026-10-30T08:04:50+03:00',
        ])->assertCreated()
            ->assertJsonPath('data.location_review.site.id', $firstId)
            ->assertJsonPath('data.location_review.reason', 'inside');
        $this->assertDatabaseHas('attendance_location_reviews', [
            'site_name' => 'Main site', 'site_latitude' => 33.5,
        ]);
    }

    public function test_fix_crossing_a_site_version_change_is_flagged(): void
    {
        $employee = $this->employee();
        $siteId = $this->site($employee);
        Carbon::setTestNow('2026-10-30 08:01:00');
        app(WorkSiteAssignmentService::class)->updateSite(
            WorkSite::findOrFail($siteId),
            ['radius_m' => 200, 'effective_from' => '2026-10-30T08:01:00+03:00'],
        );
        Carbon::setTestNow('2026-10-30 08:01:02');

        $this->record([
            'recorded_at' => '2026-10-30T08:01:01+03:00',
            'location_fix_at' => '2026-10-30T08:00:59+03:00',
        ])->assertCreated()->assertJsonPath('data.location_review.reason', 'boundary_changed');
    }

    public function test_own_day_and_manager_day_expose_their_respective_review_shapes(): void
    {
        $employee = $this->employee();
        $this->site($employee);
        $this->record()->assertCreated();
        $day = 'from=2026-10-30&to=2026-10-30';
        $this->getJson('/api/me/attendance?date=2026-10-30')->assertOk()
            ->assertJsonPath('data.0.location_review.status', 'in_process')
            ->assertJsonMissingPath('data.0.location_review.site.latitude');
        $this->getJson("/api/me/attendance/days?{$day}")->assertOk()
            ->assertJsonPath('data.days.0.check_in_location_review.status', 'in_process')
            ->assertJsonMissingPath('data.days.0.check_in_location_review.radius_m')
            ->assertJsonMissingPath('data.days.0.check_in_location_review.approved_by');

        $this->actingAsAdmin();
        $this->getJson("/api/attendance/report/employee?employee_id={$employee->id}&{$day}")->assertOk()
            ->assertJsonPath('data.days.0.check_in_location_review.radius_m', 100)
            ->assertJsonPath('data.days.0.check_in_location_review.site.latitude', 33.5)
            ->assertJsonPath('data.summary.present_days', 1);
        $this->getJson("/api/attendance/records?date=2026-10-30&employee_id={$employee->id}")->assertOk()
            ->assertJsonPath('data.0.location_review.site.latitude', 33.5)
            ->assertJsonPath('data.0.location_review.radius_m', 100);
        $this->getJson("/api/attendance/report?date=2026-10-30&employee_id={$employee->id}")->assertOk()
            ->assertJsonPath('data.summary.present', 1);
        $this->getJson("/api/attendance/report/monthly?from=2026-10-30&to=2026-10-30&employee_id={$employee->id}")->assertOk()
            ->assertJsonPath('data.rows.0.present_days', 1);
    }

    public function test_partial_sync_classifies_only_saved_events(): void
    {
        $employee = $this->employee();
        $this->site($employee);
        $entry = [
            'type' => 1, 'latitude' => 33.5, 'longitude' => 36.3,
            'recorded_at' => '2026-10-30T08:01:00+03:00',
            'location_accuracy_m' => 5,
            'location_fix_at' => '2026-10-30T08:00:50+03:00',
            'location_is_mocked' => 'false',
        ];
        $this->postJson('/api/me/attendance/sync', [
            'records' => [$entry, [...$entry, 'recorded_at' => '2026-10-30T08:02:00+03:00']],
            'selfies' => [
                UploadedFile::fake()->create('a.jpg', 100, 'image/jpeg'),
                UploadedFile::fake()->create('b.jpg', 100, 'image/jpeg'),
            ],
        ])->assertOk()->assertJsonCount(1, 'data.succeeded')
            ->assertJsonCount(1, 'data.failed')
            ->assertJsonPath('data.succeeded.0.location_review.reason', 'inside');
        $this->assertDatabaseCount('attendance_location_reviews', 1);
    }
}
