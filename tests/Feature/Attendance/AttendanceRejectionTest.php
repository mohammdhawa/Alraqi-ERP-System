<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Enums\RejectionReason;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Models\Notification;
use App\Modules\Auth\Services\NotificationService;
use App\Shared\Support\NotificationEventCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Attendance\Concerns\AttendanceTree;
use Tests\TestCase;

/**
 * Refusing an attendance event, and undoing a refusal — legacy's
 * AttendanceRejectionTest, the half that does not need the reports (those
 * cases port with them, slice 2b).
 *
 * A refused row is kept as evidence; every rule looks straight through it, so
 * the employee may record the day again. Refusing a check-in refuses that
 * day's check-out with it, as ONE decision (one batch), and undoing it brings
 * back exactly that decision. The employee is notified of a refusal — not of
 * its undoing, as legacy.
 */
class AttendanceRejectionTest extends TestCase
{
    use AttendanceTree;
    use RefreshDatabase;

    private const REJECT = ['attendance.records.reject'];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->buildTree();
    }

    private function reject(AttendanceRecord $record, RejectionReason $reason = RejectionReason::Location, ?string $note = null): TestResponse
    {
        return $this->patchJson("/api/attendance/records/{$record->id}/reject", array_filter([
            'reason' => $reason->value,
            'note'   => $note,
        ]));
    }

    private function unreject(AttendanceRecord $record): TestResponse
    {
        return $this->deleteJson("/api/attendance/records/{$record->id}/reject");
    }

    // ── Refusing ─────────────────────────────────────────────────────────

    public function test_a_refused_check_in_is_kept_stamped_and_the_employee_told_why(): void
    {
        $reviewer = $this->actingAsScopedUser(self::REJECT, ScopeType::Company);
        $checkIn = $this->checkIn($this->people['a']);

        $this->reject($checkIn, RejectionReason::Selfie, 'الصورة لشخص آخر')
            ->assertOk()
            ->assertJsonPath('message', 'تم رفض التسجيل وإشعار الموظف.')
            ->assertJsonPath('data.record.is_rejected', true)
            ->assertJsonPath('data.record.rejection_reason', RejectionReason::Selfie->value)
            ->assertJsonPath('data.record.rejection_reason_label', 'الصورة الشخصية غير صحيحة أو غير واضحة')
            ->assertJsonPath('data.record.rejected_by.id', $reviewer->id);

        $notification = Notification::where('user_id', $this->accountOf($this->people['a'])->id)->sole();
        $this->assertSame(NotificationEventCatalogue::ATTENDANCE_RECORD_REJECTED, $notification->type);
        $this->assertSame('لم يتم قبول تسجيل حضورك', $notification->title);
        $this->assertSame('لم يتم قبول تسجيل حضورك بتاريخ 2026-06-21: الصورة الشخصية غير صحيحة أو غير واضحة. ملاحظة: الصورة لشخص آخر', $notification->body);
        $this->assertSame('attendance_record', $notification->reference_type);
        $this->assertSame($checkIn->id, (int) $notification->reference_id);
    }

    public function test_a_refused_check_out_says_so_in_the_notification(): void
    {
        $this->actingAsScopedUser(self::REJECT, ScopeType::Company);
        $this->checkIn($this->people['a']);

        $this->reject($this->checkOut($this->people['a']))->assertOk();

        $this->assertStringStartsWith('لم يتم قبول تسجيل انصرافك بتاريخ 2026-06-21', Notification::sole()->body);
    }

    public function test_refusing_a_check_in_refuses_that_days_check_out_as_one_decision(): void
    {
        $this->actingAsScopedUser(self::REJECT, ScopeType::Company);
        $checkIn = $this->checkIn($this->people['a']);
        $checkOut = $this->checkOut($this->people['a']);

        $this->reject($checkIn)->assertOk()->assertJsonCount(2, 'data.rejected_records');

        $this->assertTrue($checkOut->fresh()->isRejected());
        $this->assertNotNull($checkIn->fresh()->rejection_batch);
        $this->assertSame($checkIn->fresh()->rejection_batch, $checkOut->fresh()->rejection_batch);
        $this->assertSame(1, Notification::count(), 'one decision, one notification');
    }

    public function test_the_employee_may_record_the_day_again_after_a_refusal(): void
    {
        $this->actingAsScopedUser(self::REJECT, ScopeType::Company);
        $this->reject($this->checkIn($this->people['a']))->assertOk();

        Sanctum::actingAs($this->accountOf($this->people['a']));
        $this->postJson('/api/me/attendance', [
            'type'        => AttendanceEventType::CheckIn->value,
            'latitude'    => 33.5,
            'longitude'   => 36.3,
            'selfie'      => UploadedFile::fake()->create('selfie.jpg', 100, 'image/jpeg'),
            'recorded_at' => '2026-06-21 09:00:00',
        ])->assertCreated();
    }

    public function test_refusing_twice_is_refused(): void
    {
        $this->actingAsScopedUser(self::REJECT, ScopeType::Company);
        $checkIn = $this->checkIn($this->people['a']);
        $this->reject($checkIn)->assertOk();

        $this->reject($checkIn)->assertStatus(422)->assertJsonPath('message', 'تم رفض هذا السجل مسبقاً.');
        $this->assertSame(1, Notification::count());
    }

    public function test_the_reason_is_required_and_other_needs_a_note(): void
    {
        $this->actingAsScopedUser(self::REJECT, ScopeType::Company);
        $checkIn = $this->checkIn($this->people['a']);

        $this->patchJson("/api/attendance/records/{$checkIn->id}/reject", [])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->patchJson("/api/attendance/records/{$checkIn->id}/reject", ['reason' => 9])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->reject($checkIn, RejectionReason::Other)
            ->assertStatus(422)->assertJsonValidationErrors('note');
        $this->reject($checkIn, RejectionReason::Other, str_repeat('ن', 500))->assertOk();
    }

    public function test_a_refusal_and_its_notification_stand_or_fall_together(): void
    {
        $this->actingAsScopedUser(self::REJECT, ScopeType::Company);
        $checkIn = $this->checkIn($this->people['a']);
        $this->mock(NotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUser')->andThrow(new \RuntimeException('down')));

        $this->reject($checkIn)->assertStatus(500);

        $this->assertFalse($checkIn->fresh()->isRejected());
    }

    // ── Who may refuse ───────────────────────────────────────────────────

    public function test_a_unit_grant_refuses_inside_its_subtree_only(): void
    {
        $this->actingAsScopedUser(self::REJECT, ScopeType::Unit, $this->units['a']->id);

        $this->reject($this->checkIn($this->people['a1']))->assertOk();
        $this->reject($this->checkIn($this->people['b']))->assertForbidden();
    }

    public function test_out_of_scope_is_403_before_the_body_is_read(): void
    {
        $this->actingAsScopedUser(self::REJECT, ScopeType::Unit, $this->units['a']->id);

        $this->patchJson("/api/attendance/records/{$this->checkIn($this->people['b'])->id}/reject", [])->assertForbidden();
    }

    public function test_correcting_is_not_refusing(): void
    {
        $this->actingAsScopedUser(['attendance.records.view', 'attendance.records.correct'], ScopeType::Company);

        $this->reject($this->checkIn($this->people['a']))->assertForbidden();
    }

    // ── Undoing a refusal ────────────────────────────────────────────────

    public function test_undoing_restores_the_whole_decision_and_tells_nobody(): void
    {
        $this->actingAsScopedUser(self::REJECT, ScopeType::Company);
        $checkIn = $this->checkIn($this->people['a']);
        $checkOut = $this->checkOut($this->people['a']);
        $this->reject($checkIn)->assertOk();

        $this->unreject($checkIn)
            ->assertOk()
            ->assertJsonPath('message', 'تم التراجع عن رفض التسجيل.')
            ->assertJsonCount(2, 'data.restored_records');

        foreach ([$checkIn, $checkOut] as $row) {
            $row = $row->fresh();
            $this->assertFalse($row->isRejected());
            $this->assertNull($row->rejection_batch);
            $this->assertNull($row->rejected_by);
        }
        $this->assertSame(1, Notification::count(), 'legacy sends nothing on an undo');
    }

    public function test_undoing_from_the_check_out_restores_its_check_in_too(): void
    {
        $this->actingAsScopedUser(self::REJECT, ScopeType::Company);
        $checkIn = $this->checkIn($this->people['a']);
        $checkOut = $this->checkOut($this->people['a']);
        $this->reject($checkIn)->assertOk();

        $this->unreject($checkOut)->assertOk()->assertJsonPath('data.record.id', $checkIn->id);

        $this->assertFalse($checkIn->fresh()->isRejected());
    }

    public function test_undoing_restores_only_what_the_same_decision_refused(): void
    {
        $this->actingAsScopedUser(self::REJECT, ScopeType::Company);
        $checkIn = $this->checkIn($this->people['a']);
        $earlierCheckOut = $this->checkOut($this->people['a'], '2026-06-21 12:00:00');
        $this->reject($earlierCheckOut)->assertOk();   // its own decision
        $this->reject($checkIn)->assertOk();           // a second one

        $this->unreject($checkIn)->assertOk()->assertJsonCount(1, 'data.restored_records');

        $this->assertTrue($earlierCheckOut->fresh()->isRejected(), 'the earlier decision stands');
    }

    public function test_undoing_is_refused_when_the_day_was_recorded_again(): void
    {
        $this->actingAsScopedUser(self::REJECT, ScopeType::Company);
        $checkIn = $this->checkIn($this->people['a']);
        $this->reject($checkIn)->assertOk();
        $this->checkIn($this->people['a'], '2026-06-21 09:00:00');   // re-recorded

        $this->unreject($checkIn)
            ->assertStatus(422)->assertJsonPath('message', 'لا يمكن التراجع عن الرفض: يوجد تسجيل حضور قائم لهذا اليوم بالفعل.');
    }

    public function test_undoing_is_refused_when_it_would_bring_back_a_second_check_out(): void
    {
        $this->actingAsScopedUser(self::REJECT, ScopeType::Company);
        $this->checkIn($this->people['a']);
        $checkOut = $this->checkOut($this->people['a']);
        $this->reject($checkOut)->assertOk();
        $this->checkOut($this->people['a'], '2026-06-21 17:00:00');   // re-recorded

        $this->unreject($checkOut)
            ->assertStatus(422)->assertJsonPath('message', 'لا يمكن التراجع عن الرفض: يوجد تسجيل انصراف قائم لهذا اليوم بالفعل.');
    }

    public function test_undoing_a_record_that_was_not_refused_is_422(): void
    {
        $this->actingAsScopedUser(self::REJECT, ScopeType::Company);

        $this->unreject($this->checkIn($this->people['a']))
            ->assertStatus(422)->assertJsonPath('message', 'هذا السجل غير مرفوض.');
    }

    public function test_undoing_is_scoped_too(): void
    {
        $this->actingAsScopedUser(self::REJECT, ScopeType::Unit, $this->units['a']->id);
        $checkIn = $this->checkIn($this->people['b'], attributes: ['rejected_at' => now(), 'rejection_reason' => RejectionReason::Other]);

        $this->unreject($checkIn)->assertForbidden();
    }
}
