<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Leave\Enums\LeaveOrigin;
use App\Modules\Leave\Models\LeaveRequest;
use App\Shared\Models\Attachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Leave\Concerns\LeaveFixtures;
use Tests\TestCase;

/**
 * GET /api/leave/requests/{leaveRequest} and its files (LEAVE_BUILD.md §4) —
 * what an approver opens from the inbox. Readers: the employee, anyone
 * holding a step on its chain, and `leave.requests.view` within reach of the
 * employee's current unit. Files are streamed to exactly those readers.
 */
class LeaveRequestReadTest extends TestCase
{
    use LeaveFixtures;
    use RefreshDatabase;

    private array $leave;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildLeaveTree();

        $this->actingAsPerson($this->people['staff']);
        $this->leave = $this->post('/api/me/leave-requests', [
            'leave_type_id' => $this->type('sick')->id,
            'start_date'    => '2026-06-21',
            'end_date'      => '2026-06-22',
            'reason'        => 'زكام',
            'attachments'   => [UploadedFile::fake()->create('تقرير.pdf', 100, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.leave_request');
    }

    private function read(?int $id = null)
    {
        return $this->getJson('/api/leave/requests/'.($id ?? $this->leave['id']));
    }

    private function file(?int $attachment = null)
    {
        $attachment ??= $this->leave['attachments'][0]['id'];

        return $this->get("/api/leave/requests/{$this->leave['id']}/attachments/{$attachment}", ['Accept' => 'application/json']);
    }

    public function test_the_employee_reads_their_own_with_its_chain_and_files(): void
    {
        $this->read()
            ->assertOk()
            ->assertJsonPath('data.id', $this->leave['id'])
            ->assertJsonPath('data.leave_type.code', 'sick')
            ->assertJsonPath('data.approval.steps.0.approver.id', $this->people['headA']->id)
            ->assertJsonPath('data.attachments.0.name', 'تقرير.pdf');
    }

    public function test_an_approver_on_the_chain_reads_it(): void
    {
        $this->actingAsPerson($this->people['headA']);
        $this->read()->assertOk()->assertJsonPath('data.approval.can_act', true);

        $this->actingAsPerson($this->people['hrHead']);
        $this->read()->assertOk()->assertJsonPath('data.approval.can_act', false);
    }

    public function test_someone_off_the_chain_is_refused(): void
    {
        $this->actingAsPerson($this->people['gm']);
        $this->read()->assertForbidden();

        $this->actingAsPerson($this->people['bStaff']);
        $this->read()->assertForbidden();
        $this->file()->assertForbidden();
    }

    public function test_leave_requests_view_reads_within_reach_only(): void
    {
        $this->actingAsScopedUser(['leave.requests.view'], ScopeType::Unit, $this->units['a']->id);
        $this->read()->assertOk();
        $this->file()->assertOk();

        $this->actingAsScopedUser(['leave.requests.view'], ScopeType::Unit, $this->units['b']->id);
        $this->read()
            ->assertForbidden()
            ->assertJsonPath('message', 'طلب الإجازة هذا لموظف خارج نطاق صلاحياتك.');
    }

    public function test_the_employee_streams_their_file_uncached(): void
    {
        $response = $this->file()->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('.pdf', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_a_file_of_another_request_is_not_found_under_this_one(): void
    {
        $other = LeaveRequest::factory()->forType('sick')->create(['employee_id' => $this->people['staff']->id, 'start_date' => '2026-07-01', 'end_date' => '2026-07-01']);
        $foreign = Attachment::create([
            'attachable_type' => 'leave_request', 'attachable_id' => $other->id, 'disk' => 'local',
            'path' => 'attachments/leave_request/'.$other->id.'/x.pdf', 'original_name' => 'x.pdf', 'mime_type' => 'application/pdf', 'size' => 1,
        ]);

        $this->file($foreign->id)->assertNotFound();
    }

    public function test_an_unknown_request_is_not_found(): void
    {
        $this->read(999999)->assertNotFound();
    }

    public function test_an_excuse_reads_with_no_approval(): void
    {
        $excuse = LeaveRequest::factory()->forType('sick')->excuse()->create([
            'employee_id' => $this->people['staff']->id, 'start_date' => '2026-02-01', 'end_date' => '2026-02-01', 'origin' => LeaveOrigin::HrExcuse,
        ]);

        $this->read($excuse->id)->assertOk()->assertJsonPath('data.approval', null)->assertJsonPath('data.is_excuse', true);
    }
}
