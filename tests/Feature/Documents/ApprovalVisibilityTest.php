<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Approvals\Models\Approval;
use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Documents\Models\Document;
use App\Modules\Leave\Models\LeaveRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Leave\Concerns\LeaveFixtures;
use Tests\Support\Pdfs;
use Tests\TestCase;

/**
 * A document's chain is not HR's to read (documents design §7.4): its parties
 * and `documents.view` holders read it, `approvals.view` alone does not. The
 * leave request beside it is unchanged.
 *
 * headA (manager of A) sends a document with hrHead as first approver; staff
 * (in A1, under A) files a leave.
 */
class ApprovalVisibilityTest extends TestCase
{
    use LeaveFixtures;
    use RefreshDatabase;

    private Approval $document;

    private Approval $leave;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->buildLeaveTree();

        $this->actingAsPerson($this->people['staff']);
        $this->postJson('/api/me/leave-requests', [
            'leave_type_id' => $this->type('annual')->id, 'start_date' => '2026-06-21', 'end_date' => '2026-06-22',
        ])->assertCreated();

        $this->actingAsPerson($this->people['headA']);
        $this->post('/api/documents', [
            'title'     => 'تعميم داخلي',
            'file'      => UploadedFile::fake()->createWithContent('letter.pdf', Pdfs::plain()),
            'approvers' => [$this->people['hrHead']->id],
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->document = Document::query()->firstOrFail()->approval;
        $this->leave = Approval::query()->where('approvable_type', (new LeaveRequest)->getMorphClass())->firstOrFail();
    }

    /** @return array<int, int> */
    private function listed(): array
    {
        return collect($this->getJson('/api/approvals')->assertOk()->json('data'))->pluck('id')->all();
    }

    public function test_hr_s_approvals_list_leaves_documents_out(): void
    {
        $this->actingAsScopedUser(['approvals.view'], ScopeType::Unit, $this->units['a']->id);

        $this->assertSame([$this->leave->id], $this->listed());
    }

    public function test_the_approval_screen_refuses_a_document_s_chain(): void
    {
        $this->actingAsScopedUser(['approvals.view'], ScopeType::Unit, $this->units['a']->id);

        $this->getJson("/api/approvals/{$this->document->id}")->assertForbidden();
        $this->getJson("/api/approvals/{$this->leave->id}")->assertOk();
    }

    public function test_parties_and_documents_view_still_read_it(): void
    {
        $this->actingAsPerson($this->people['hrHead']);
        $this->getJson("/api/approvals/{$this->document->id}")->assertOk();

        $this->actingAsPerson($this->people['headA']);
        $this->getJson("/api/approvals/{$this->document->id}")->assertOk();

        $this->actingAsScopedUser(['approvals.view', 'documents.view'], ScopeType::Company);
        $this->getJson("/api/approvals/{$this->document->id}")->assertOk();
        $this->assertContains($this->document->id, $this->listed());
        $this->assertContains($this->leave->id, $this->listed());
    }

    public function test_a_super_admin_still_reads_everything(): void
    {
        Sanctum::actingAs($this->actingAsAdmin());

        $this->getJson("/api/approvals/{$this->document->id}")->assertOk();
    }
}
