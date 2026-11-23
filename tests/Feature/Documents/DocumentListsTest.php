<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\User;
use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Enums\Confirmation;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Enums\SignatureSource;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Services\Signatures;
use App\Modules\Documents\Services\UnitStamps;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Pdfs;
use Tests\TestCase;

/**
 * Seeing documents and the lists (documents design §7.7): three boxes, the
 * caller's turn first among the incoming, and the files streamed private.
 */
class DocumentListsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Employee> */
    private array $people = [];

    /** @var array<string, User> */
    private array $accounts = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $units = [
            'gm' => Department::create(['name' => 'الإدارة العامة', 'level' => 1]),
        ];
        $units['c'] = Department::create(['name' => 'إدارة المالية', 'level' => 2, 'parent_id' => $units['gm']->id, 'sector' => 1]);
        $units['a1'] = Department::create(['name' => 'قسم الحسابات', 'level' => 3, 'parent_id' => $units['c']->id]);
        $units['a2'] = Department::create(['name' => 'قسم الميزانية', 'level' => 3, 'parent_id' => $units['c']->id]);

        foreach ($units as $key => $unit) {
            $employee = Employee::factory()->create();
            $this->people[$key] = $employee;
            $this->accounts[$key] = User::factory()->create(['employee_id' => $employee->id, 'must_change_password' => false]);
            $unit->update(['manager_id' => $employee->id]);

            app(UnitStamps::class)->replace($unit, UploadedFile::fake()->image('stamp.png', 300, 300), $this->accounts[$key]);
            app(Signatures::class)->draw($employee, UploadedFile::fake()->image('s.png', 600, 200), SignatureSource::App, $this->accounts[$key]);
        }
    }

    /**
     * @param  array<int, string>  $approvers
     */
    private function send(string $title, array $approvers, string $as = 'c', ?int $importNumber = null): Document
    {
        Sanctum::actingAs($this->accounts[$as]);

        $this->post('/api/documents', array_filter([
            'title'         => $title,
            'file'          => UploadedFile::fake()->createWithContent('letter.pdf', Pdfs::plain()),
            'approvers'     => array_map(fn (string $key): int => $this->people[$key]->id, $approvers),
            'import_number' => $importNumber,
        ], static fn (mixed $value): bool => $value !== null), ['Accept' => 'application/json'])->assertCreated();

        return Document::query()->latest('id')->firstOrFail();
    }

    private function decide(Document $document, string $as, string $decision = 'approve'): TestResponse
    {
        Sanctum::actingAs($this->accounts[$as]);

        return $this->postJson("/api/documents/{$document->id}/decision", [
            'decision'     => $decision,
            'confirmed_by' => Confirmation::Biometric->value,
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function listed(string $as, string $query): array
    {
        Sanctum::actingAs($this->accounts[$as]);

        $response = $this->getJson("/api/documents?{$query}")->assertOk();

        return array_map('intval', array_column($response->json('data'), 'id'));
    }

    public function test_incoming_lists_what_i_sign_my_turn_first(): void
    {
        $waiting = $this->send('ينتظر قراري', ['a2']);
        $decided = $this->send('قررت فيه', ['a2']);
        $this->decide($decided, 'a2')->assertOk();
        $this->send('ليس لي', ['a1']);

        $ids = $this->listed('a2', 'box=incoming');

        $this->assertSame([$waiting->id, $decided->id], $ids);

        Sanctum::actingAs($this->accounts['a2']);
        $row = $this->getJson('/api/documents?box=incoming')->json('data.0');
        $this->assertTrue($row['can']['decide']);
        $this->assertNotNull($row['my_step_id']);
    }

    public function test_a_later_step_is_not_my_turn(): void
    {
        $chain = $this->send('على التسلسل', ['a1', 'a2']);
        $other = $this->send('وحيد', ['a2']);

        // a2 is behind a1 on the first, so the newer one leads either way;
        // after a1 decides, the first is a2's turn and leads.
        $this->assertSame([$other->id, $chain->id], $this->listed('a2', 'box=incoming'));

        $this->decide($chain, 'a1')->assertOk();
        $this->decide($other, 'a2')->assertOk();

        $this->assertSame([$chain->id, $other->id], $this->listed('a2', 'box=incoming'));
    }

    public function test_outgoing_lists_what_i_created(): void
    {
        $mine = $this->send('أرسلته', ['a1']);
        $this->send('أرسله غيري', ['a1'], 'a2');

        $this->assertSame([$mine->id], $this->listed('c', 'box=outgoing'));
        $this->assertSame([], $this->listed('a1', 'box=outgoing'));
    }

    public function test_all_needs_documents_view(): void
    {
        $document = $this->send('للجميع', ['a1']);

        Sanctum::actingAs($this->accounts['c']);
        $this->getJson('/api/documents?box=all')
            ->assertForbidden()
            ->assertJsonPath('message', 'لا تملك صلاحية عرض جميع المستندات.');

        $this->actingAsUserWithPermissions(['documents.view']);
        $this->getJson('/api/documents?box=all')->assertOk();

        $this->actingAsAdmin();
        $response = $this->getJson('/api/documents?box=all')->assertOk();
        $this->assertSame([$document->id], array_map('intval', array_column($response->json('data'), 'id')));
    }

    public function test_the_box_is_required_and_known(): void
    {
        Sanctum::actingAs($this->accounts['c']);

        $this->getJson('/api/documents')->assertUnprocessable()->assertJsonValidationErrors('box');
        $this->getJson('/api/documents?box=sideways')->assertUnprocessable()->assertJsonValidationErrors('box');
    }

    public function test_search_and_status_filter(): void
    {
        $budget = $this->send('ميزانية العام', ['a1']);
        $circular = $this->send('تعميم الإجازات', ['a1']);
        $this->decide($circular, 'a1')->assertOk();
        $this->decide($circular, 'gm')->assertOk();

        $this->assertSame([$budget->id], $this->listed('c', 'box=outgoing&search='.urlencode('ميزانية')));
        $this->assertSame([], $this->listed('c', 'box=outgoing&search='.urlencode('%')));
        $this->assertSame([$circular->id], $this->listed('c', 'box=outgoing&status='.DocumentStatus::Approved->value));
        $this->assertSame([$budget->id], $this->listed('c', 'box=outgoing&status='.DocumentStatus::Pending->value));
    }

    public function test_search_finds_the_import_number(): void
    {
        $numbered = $this->send('ميزانية العام', ['a1'], importNumber: 4242);
        $titled = $this->send('محضر الجلسة 4242', ['a1']);
        $circular = $this->send('تعميم الإجازات', ['a1'], importNumber: 77);

        // Digits match the وارد number exactly, or the title in part.
        $this->assertSame([$titled->id, $numbered->id], $this->listed('c', 'box=outgoing&search=4242'));
        $this->assertSame([$titled->id], $this->listed('c', 'box=outgoing&search=424'));
        $this->assertSame([$circular->id], $this->listed('c', 'box=outgoing&search='.urlencode('تعميم')));
    }

    public function test_show_and_file_for_a_party(): void
    {
        $document = $this->send('للعرض', ['a1']);

        Sanctum::actingAs($this->accounts['a1']);
        $this->getJson("/api/documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $document->id)
            ->assertJsonPath('data.title', 'للعرض')
            ->assertJsonPath('data.can.decide', true)
            ->assertJsonPath('data.my_step_id', $document->approval->steps()->firstOrFail()->id);

        Sanctum::actingAs($this->accounts['c']);
        $this->getJson("/api/documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('data.can.decide', false)
            ->assertJsonPath('data.my_step_id', null);

        $before = $this->get("/api/documents/{$document->id}/file")->assertOk();
        $this->assertStringContainsString('no-store', (string) $before->headers->get('Cache-Control'));
        $this->assertSame('application/pdf', $before->headers->get('Content-Type'));
        $this->assertSame(Storage::disk('local')->get($document->original_path), $before->streamedContent());

        $this->decide($document, 'a1')->assertOk();
        $document->refresh();
        $this->assertNotNull($document->printed_path);

        Sanctum::actingAs($this->accounts['c']);
        $after = $this->get("/api/documents/{$document->id}/file")->assertOk();
        $this->assertStringContainsString('no-store', (string) $after->headers->get('Cache-Control'));
        $this->assertSame(Storage::disk('local')->get($document->printed_path), $after->streamedContent());
    }

    public function test_nobody_off_the_document_reads_it(): void
    {
        $document = $this->send('سري', ['a1']);

        $names = Permission::query()->where('name', '!=', 'documents.view')->pluck('name')->all();
        $outsider = $this->actingAsUserWithPermissions($names);
        $this->assertFalse($outsider->hasPermission('documents.view'));

        $this->getJson("/api/documents/{$document->id}")->assertForbidden();
        $this->getJson("/api/documents/{$document->id}/file")->assertForbidden();
        $this->postJson("/api/documents/{$document->id}/decision", [
            'decision'     => 'approve',
            'confirmed_by' => Confirmation::Biometric->value,
        ])->assertForbidden();

        // A holder of `documents.view` reads it.
        $this->actingAsUserWithPermissions(['documents.view']);
        $this->getJson("/api/documents/{$document->id}")->assertOk();
    }

    public function test_a_deleted_document_is_gone_to_its_parties(): void
    {
        $document = $this->send('محذوف', ['a1']);
        $document->delete();

        Sanctum::actingAs($this->accounts['c']);
        $this->getJson("/api/documents/{$document->id}")->assertStatus(410)->assertJsonPath('message', 'حُذف هذا المستند.');
        $this->getJson("/api/documents/{$document->id}/file")->assertStatus(410)->assertJsonPath('message', 'حُذف هذا المستند.');

        $this->actingAsUserWithPermissions(['approvals.view']);
        $this->getJson("/api/documents/{$document->id}")->assertForbidden();

        $this->assertSame([], $this->listed('c', 'box=outgoing'));
    }
}
