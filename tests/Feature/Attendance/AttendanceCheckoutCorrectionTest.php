<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Enums\AttendanceRecordStatus;
use App\Modules\Attendance\Enums\AttendanceSource;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Auth\Enums\ScopeType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Attendance\Concerns\AttendanceTree;
use Tests\TestCase;

/**
 * PATCH /api/attendance/records/{record}/checkout — HR records a check-out
 * the employee forgot (legacy's correctCheckout and its
 * AttendanceCheckoutAuthorizationTest).
 *
 * The check-in is left as it was; a new check-out row is written with the
 * corrector, the time and the note, the day's hours computed, and the
 * missing-check-out flag cleared. The 60-minute guard does not apply: a
 * correction records an authoritative time.
 */
class AttendanceCheckoutCorrectionTest extends TestCase
{
    use AttendanceTree;
    use RefreshDatabase;

    private const CORRECT = ['attendance.records.correct'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildTree();
    }

    private function correct(AttendanceRecord $record, string $at = '2026-06-21 16:30:00', ?string $note = 'نسي تسجيل الانصراف'): TestResponse
    {
        return $this->patchJson("/api/attendance/records/{$record->id}/checkout", array_filter([
            'checked_out_at' => $at,
            'note'           => $note,
        ]));
    }

    public function test_a_forgotten_check_out_is_recorded_with_its_corrector(): void
    {
        $corrector = $this->actingAsScopedUser(self::CORRECT, ScopeType::Company);
        $checkIn = $this->checkIn($this->people['a'], attributes: ['status' => AttendanceRecordStatus::MissingCheckout]);

        $this->correct($checkIn)
            ->assertOk()
            ->assertJsonPath('message', 'تم تصحيح الانصراف بنجاح.')
            ->assertJsonPath('data.check_in.id', $checkIn->id)
            ->assertJsonPath('data.check_in.status', null)
            ->assertJsonPath('data.check_out.type', AttendanceEventType::CheckOut->value)
            ->assertJsonPath('data.check_out.source', AttendanceSource::HrCorrection->value)
            ->assertJsonPath('data.check_out.work_hours', 8.5)
            ->assertJsonPath('data.check_out.is_corrected', true)
            ->assertJsonPath('data.check_out.correction_note', 'نسي تسجيل الانصراف')
            ->assertJsonPath('data.check_out.has_selfie', false);

        $checkOut = AttendanceRecord::checkOuts()->sole();
        $this->assertSame($corrector->id, $checkOut->corrected_by);
        $this->assertNull($checkIn->fresh()->status, 'the missing flag is cleared');
        $this->assertSame('2026-06-21 08:00:00', $checkIn->fresh()->recorded_at->format('Y-m-d H:i:s'), 'the check-in is untouched');
    }

    public function test_the_minimum_gap_does_not_bind_a_correction(): void
    {
        $this->actingAsScopedUser(self::CORRECT, ScopeType::Company);

        $this->correct($this->checkIn($this->people['a']), '2026-06-21 08:10:00')->assertOk();
    }

    public function test_only_a_check_in_can_be_corrected(): void
    {
        $this->actingAsScopedUser(self::CORRECT, ScopeType::Company);
        $this->checkIn($this->people['a']);

        $this->correct($this->checkOut($this->people['a']))
            ->assertStatus(422)->assertJsonPath('message', 'يجب اختيار سجل دخول لتصحيح الانصراف.');
    }

    public function test_a_day_that_already_has_its_check_out_needs_no_correction(): void
    {
        $this->actingAsScopedUser(self::CORRECT, ScopeType::Company);
        $checkIn = $this->checkIn($this->people['a']);
        $this->checkOut($this->people['a']);

        $this->correct($checkIn)->assertStatus(422)->assertJsonPath('message', 'هذا السجل لا يحتاج إلى تصحيح انصراف.');
    }

    public function test_the_check_out_must_follow_the_check_in(): void
    {
        $this->actingAsScopedUser(self::CORRECT, ScopeType::Company);

        $this->correct($this->checkIn($this->people['a']), '2026-06-21 07:00:00')
            ->assertStatus(422)->assertJsonPath('message', 'وقت الانصراف يجب أن يكون بعد وقت الحضور.');
        $this->assertSame(0, AttendanceRecord::checkOuts()->count());
    }

    public function test_the_time_is_required(): void
    {
        $this->actingAsScopedUser(self::CORRECT, ScopeType::Company);

        $this->patchJson("/api/attendance/records/{$this->checkIn($this->people['a'])->id}/checkout", [])
            ->assertStatus(422)->assertJsonValidationErrors('checked_out_at');
    }

    // ── Who may correct ──────────────────────────────────────────────────

    public function test_a_unit_grant_corrects_inside_its_subtree_only(): void
    {
        $this->actingAsScopedUser(self::CORRECT, ScopeType::Unit, $this->units['a']->id);

        $this->correct($this->checkIn($this->people['a1']))->assertOk();
        $this->correct($this->checkIn($this->people['b']))->assertForbidden()->assertJsonPath('success', false);
    }

    public function test_a_manager_corrects_their_own_units(): void
    {
        $this->actingAsScopedUser(self::CORRECT, ScopeType::Managed, employeeId: $this->people['headA']->id);

        $this->correct($this->checkIn($this->people['a']))->assertOk();
        $this->correct($this->checkIn($this->people['b']))->assertForbidden();
    }

    public function test_viewing_is_not_correcting(): void
    {
        $this->actingAsScopedUser(['attendance.records.view'], ScopeType::Company);

        $this->correct($this->checkIn($this->people['a']))->assertForbidden();
    }

    public function test_an_unknown_record_is_404(): void
    {
        $this->actingAsScopedUser(self::CORRECT, ScopeType::Company);

        $this->patchJson('/api/attendance/records/999999/checkout', ['checked_out_at' => '2026-06-21 16:00:00'])->assertNotFound();
    }
}
