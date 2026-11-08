<?php

declare(strict_types=1);

namespace Tests\Feature\Me;

use App\Modules\Attendance\Enums\RejectionReason;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Attendance\Concerns\AttendanceTree;
use Tests\TestCase;

/**
 * GET /api/me/attendance/{record}: the caller's own record by id. A
 * notification carries only the record's id (API §6), and this is how the
 * app finds the day it is about (app slice 6, N1).
 */
class MyAttendanceRecordTest extends TestCase
{
    use AttendanceTree;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildTree();
    }

    public function test_your_own_record_reads_in_the_documented_shape(): void
    {
        $record = $this->checkIn($this->people['a'], '2026-06-21 08:00:00');
        Sanctum::actingAs($this->accountOf($this->people['a']));

        $this->getJson("/api/me/attendance/{$record->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $record->id)
            ->assertJsonPath('data.recorded_at', $record->recorded_at->toIso8601String());
    }

    public function test_another_employees_record_is_not_found(): void
    {
        $record = $this->checkIn($this->people['b']);
        Sanctum::actingAs($this->accountOf($this->people['a']));

        $this->getJson("/api/me/attendance/{$record->id}")->assertNotFound();
    }

    public function test_a_refused_record_is_readable(): void
    {
        $record = $this->checkIn($this->people['a'], attributes: [
            'rejected_at'      => now(),
            'rejection_reason' => RejectionReason::Other,
        ]);
        Sanctum::actingAs($this->accountOf($this->people['a']));

        $this->getJson("/api/me/attendance/{$record->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $record->id);
    }

    public function test_an_unknown_id_is_not_found(): void
    {
        Sanctum::actingAs($this->accountOf($this->people['a']));

        $this->getJson('/api/me/attendance/999999')->assertNotFound();
    }

    public function test_an_account_with_no_employee_is_not_found(): void
    {
        $record = $this->checkIn($this->people['a']);
        Sanctum::actingAs(User::factory()->create(['employee_id' => null]));

        $this->getJson("/api/me/attendance/{$record->id}")->assertNotFound();
    }

    public function test_a_guest_is_refused(): void
    {
        $record = $this->checkIn($this->people['a']);

        $this->getJson("/api/me/attendance/{$record->id}")->assertUnauthorized();
    }
}
