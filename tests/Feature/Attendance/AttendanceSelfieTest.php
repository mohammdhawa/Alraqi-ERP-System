<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Auth\Enums\ScopeType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Attendance\Concerns\AttendanceTree;
use Tests\TestCase;

/**
 * Seeing a selfie (Q11). Legacy served them from the public disk, to anyone
 * with the URL. Here there is no URL: the file is streamed by an endpoint that
 * checks who is asking — the employee their own, a reviewer within the reach
 * of their attendance.records.view grant.
 */
class AttendanceSelfieTest extends TestCase
{
    use AttendanceTree;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->buildTree();
    }

    private function withSelfie(string $key): AttendanceRecord
    {
        $path = "attendance/selfies/{$this->people[$key]->id}/2026-06-21/x.jpg";
        Storage::disk('local')->put($path, 'jpeg-bytes');

        return $this->checkIn($this->people[$key], attributes: ['selfie_path' => $path]);
    }

    public function test_an_employee_sees_their_own_selfie(): void
    {
        $record = $this->withSelfie('a');
        Sanctum::actingAs($this->accountOf($this->people['a']));

        $response = $this->get("/api/me/attendance/{$record->id}/selfie")->assertOk();
        $this->assertSame('jpeg-bytes', $response->streamedContent());
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    public function test_an_employee_cannot_open_a_colleagues_selfie(): void
    {
        $record = $this->withSelfie('b');
        Sanctum::actingAs($this->accountOf($this->people['a']));

        $this->getJson("/api/me/attendance/{$record->id}/selfie")->assertNotFound();
    }

    public function test_a_reviewer_sees_a_selfie_inside_their_reach_only(): void
    {
        $inside = $this->withSelfie('a1');
        $outside = $this->withSelfie('b');
        $this->actingAsScopedUser(['attendance.records.view'], ScopeType::Unit, $this->units['a']->id);

        $this->get("/api/attendance/records/{$inside->id}/selfie")->assertOk();
        $this->getJson("/api/attendance/records/{$outside->id}/selfie")->assertForbidden();
    }

    public function test_no_permission_no_selfie(): void
    {
        $record = $this->withSelfie('a');
        $this->actingAsRolelessUser();

        $this->getJson("/api/attendance/records/{$record->id}/selfie")->assertForbidden();
    }

    public function test_a_record_without_a_selfie_is_404_with_a_message(): void
    {
        $record = $this->checkIn($this->people['a']);
        Sanctum::actingAs($this->accountOf($this->people['a']));

        $this->getJson("/api/me/attendance/{$record->id}/selfie")
            ->assertNotFound()
            ->assertJsonPath('message', 'لا توجد صورة لهذا السجل.');
    }

    public function test_a_selfie_whose_file_is_gone_is_404_too(): void
    {
        $record = $this->withSelfie('a');
        Storage::disk('local')->delete($record->selfie_path);
        Sanctum::actingAs($this->accountOf($this->people['a']));

        $this->getJson("/api/me/attendance/{$record->id}/selfie")->assertNotFound();
    }
}
