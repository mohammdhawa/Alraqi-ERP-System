<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\LocationReviewReason;
use App\Modules\Attendance\Enums\LocationReviewStatus;
use App\Modules\Attendance\Enums\RejectionReason;
use App\Modules\Attendance\Models\AttendanceLocationReview;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Auth\Enums\ScopeType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Attendance\Concerns\AttendanceTree;
use Tests\TestCase;

class AttendanceLocationApprovalTest extends TestCase
{
    use AttendanceTree;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-21 18:00:00');
        $this->buildTree();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function review(AttendanceRecord $record, LocationReviewStatus $status = LocationReviewStatus::InProcess): AttendanceLocationReview
    {
        return AttendanceLocationReview::create([
            'attendance_record_id' => $record->id,
            'latitude' => $record->latitude,
            'longitude' => $record->longitude,
            'reason' => $status === LocationReviewStatus::ForReview ? LocationReviewReason::Outside : LocationReviewReason::Inside,
            'status' => $status,
        ]);
    }

    public function test_manager_approves_either_initial_status_and_repeat_is_idempotent(): void
    {
        $forReview = $this->review($this->checkIn($this->people['a']), LocationReviewStatus::ForReview);
        $inProcess = $this->review($this->checkOut($this->people['a']));
        $this->actingAsAdmin();

        foreach ([$forReview, $inProcess] as $review) {
            $url = "/api/attendance/records/{$review->attendance_record_id}/location-review/approve";
            $this->patchJson($url, ['note' => 'Checked on map'])->assertOk()
                ->assertJsonPath('data.location_review.status', 'approved')
                ->assertJsonPath('data.location_review.note', 'Checked on map');
            $this->patchJson($url)->assertOk()->assertJsonPath('data.location_review.status', 'approved');
            $this->assertSame(LocationReviewStatus::Approved, $review->fresh()->status);
        }
        $this->assertSame(2, DB::table('audit_logs')
            ->where('auditable_type', 'attendance_location_review')->where('event', 'updated')->count());
    }

    public function test_rejected_record_cannot_be_newly_approved(): void
    {
        $record = $this->checkIn($this->people['a']);
        $review = $this->review($record);
        $record->update(['rejected_at' => now()]);
        $this->actingAsAdmin();

        $this->patchJson("/api/attendance/records/{$record->id}/location-review/approve")
            ->assertUnprocessable();
        $this->assertSame(LocationReviewStatus::InProcess, $review->fresh()->status);
    }

    public function test_view_only_and_out_of_scope_managers_cannot_approve(): void
    {
        $record = $this->checkIn($this->people['b']);
        $this->review($record);
        $this->actingAsScopedUser(['attendance.records.view'], ScopeType::Company);
        $this->patchJson("/api/attendance/records/{$record->id}/location-review/approve")->assertForbidden();

        $this->actingAsScopedUser(['attendance.records.view', 'attendance.records.approveLocation'], ScopeType::Unit, $this->units['a']->id);
        $this->patchJson("/api/attendance/records/{$record->id}/location-review/approve")->assertForbidden();
    }

    public function test_open_queue_includes_older_days_and_excludes_rejected_rows(): void
    {
        $old = $this->checkIn($this->people['a'], '2026-06-20 08:00:00');
        $this->review($old, LocationReviewStatus::ForReview);
        $today = $this->checkIn($this->people['b']);
        $this->review($today);
        $rejected = $this->checkIn($this->people['a1']);
        $this->review($rejected);
        $rejected->update(['rejected_at' => now()]);
        $this->actingAsAdmin();

        $this->getJson('/api/attendance/records')->assertOk()->assertJsonPath('meta.total', 2);
        $open = $this->getJson('/api/attendance/records?open_location_review=1')
            ->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertEqualsCanonicalizing([$old->id, $today->id], array_column($open->json('data'), 'id'));
        $this->getJson('/api/attendance/records?open_location_review=1&location_review_status=approved')
            ->assertUnprocessable();
    }

    public function test_selected_bulk_approval_reports_each_skip_and_preserves_scope(): void
    {
        $a = $this->review($this->checkIn($this->people['a']), LocationReviewStatus::ForReview);
        $a1 = $this->review($this->checkIn($this->people['a1']));
        $b = $this->review($this->checkIn($this->people['b']));
        $approved = $this->review($this->checkOut($this->people['a']), LocationReviewStatus::Approved);
        $rejected = $this->review($this->checkOut($this->people['a1']));
        AttendanceRecord::findOrFail($rejected->attendance_record_id)->update(['rejected_at' => now()]);
        $this->actingAsScopedUser(['attendance.records.approveLocation'], ScopeType::Unit, $this->units['a']->id);

        $response = $this->postJson('/api/attendance/records/location-review/approve', [
            'record_ids' => [$a->attendance_record_id, $a1->attendance_record_id, $b->attendance_record_id,
                $approved->attendance_record_id, $rejected->attendance_record_id],
        ])->assertOk()->assertJsonCount(2, 'data.approved')->assertJsonCount(3, 'data.skipped');
        $this->assertEqualsCanonicalizing([$a->attendance_record_id, $a1->attendance_record_id], $response->json('data.approved'));
        $this->assertSame(LocationReviewStatus::InProcess, $b->fresh()->status);
        $this->assertSame(2, DB::table('audit_logs')->where('auditable_type', 'attendance_location_review')->where('event', 'updated')->count());
    }

    public function test_matching_bulk_approves_only_in_process_rows_in_approval_scope(): void
    {
        $a = $this->review($this->checkIn($this->people['a'], '2026-06-20 08:00:00'));
        $a1 = $this->review($this->checkIn($this->people['a1']));
        $b = $this->review($this->checkIn($this->people['b']));
        $flagged = $this->review($this->checkOut($this->people['a']), LocationReviewStatus::ForReview);
        $this->actingAsScopedUser(['attendance.records.approveLocation'], ScopeType::Unit, $this->units['a']->id);

        $this->postJson('/api/attendance/records/location-review/approve', ['matching' => ['open_location_review' => '0']])
            ->assertOk()->assertJsonCount(2, 'data.approved')
            ->assertJsonPath('data.remaining', 0);
        $this->assertSame(LocationReviewStatus::InProcess, $b->fresh()->status);
        $this->assertSame(LocationReviewStatus::ForReview, $flagged->fresh()->status);
        $this->assertSame(LocationReviewStatus::Approved, $a->fresh()->status);
        $this->assertSame(LocationReviewStatus::Approved, $a1->fresh()->status);
    }

    public function test_matching_bulk_refuses_a_status_other_than_in_process(): void
    {
        $flagged = $this->review($this->checkIn($this->people['a']), LocationReviewStatus::ForReview);
        $this->actingAsAdmin();

        foreach (['for_review', 'approved'] as $status) {
            $this->postJson('/api/attendance/records/location-review/approve', ['matching' => ['location_review_status' => $status]])
                ->assertUnprocessable()->assertJsonValidationErrors('matching.location_review_status');
        }
        $this->postJson('/api/attendance/records/location-review/approve', ['matching' => ['location_review_status' => 'in_process']])
            ->assertOk();
        $this->assertSame(LocationReviewStatus::ForReview, $flagged->fresh()->status);
    }

    public function test_bulk_selection_limit_is_enforced(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/attendance/records/location-review/approve', ['record_ids' => range(1, 101)])
            ->assertUnprocessable()->assertJsonValidationErrors('record_ids');
    }

    public function test_matching_bulk_stops_at_two_hundred_and_reports_remaining(): void
    {
        foreach (range(1, 201) as $day) {
            $record = $this->checkIn($this->people['a'], '2026-06-20 08:00:00');
            $this->review($record);
        }
        $this->actingAsAdmin();

        $this->postJson('/api/attendance/records/location-review/approve', ['matching' => []])
            ->assertOk()->assertJsonCount(200, 'data.approved')
            ->assertJsonPath('data.remaining', 1);
        $this->assertSame(1, AttendanceLocationReview::where('status', LocationReviewStatus::InProcess)->count());
    }

    public function test_status_filter_can_retrieve_rejected_history_and_missing_review_cannot_be_approved(): void
    {
        $record = $this->checkIn($this->people['a']);
        $this->review($record, LocationReviewStatus::ForReview);
        $record->update(['rejected_at' => now()]);
        $plain = $this->checkIn($this->people['b']);
        $this->actingAsAdmin();

        $this->getJson('/api/attendance/records?location_review_status=for_review')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $record->id);
        $this->patchJson("/api/attendance/records/{$plain->id}/location-review/approve")
            ->assertUnprocessable();
    }

    public function test_refusal_propagation_preserves_reviews_and_undo_does_not_approve(): void
    {
        $in = $this->checkIn($this->people['a']);
        $out = $this->checkOut($this->people['a']);
        $this->review($in, LocationReviewStatus::ForReview);
        $this->review($out);
        $this->actingAsAdmin();

        $this->patchJson("/api/attendance/records/{$in->id}/reject", [
            'reason' => RejectionReason::Location->value,
            'note' => 'Outside site',
        ])->assertOk();
        $this->assertTrue($in->fresh()->isRejected());
        $this->assertTrue($out->fresh()->isRejected());
        $this->patchJson("/api/attendance/records/{$out->id}/location-review/approve")
            ->assertUnprocessable();
        $this->getJson('/api/attendance/records?open_location_review=1')
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->deleteJson("/api/attendance/records/{$in->id}/reject")->assertOk();
        $this->assertSame(LocationReviewStatus::ForReview, $in->fresh()->locationReview->status);
        $this->assertSame(LocationReviewStatus::InProcess, $out->fresh()->locationReview->status);
        $this->getJson('/api/attendance/records?open_location_review=1')
            ->assertOk()->assertJsonPath('meta.total', 2);
    }
}
