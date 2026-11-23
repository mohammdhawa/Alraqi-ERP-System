<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\User;
use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Enums\SignatureSource;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Services\Signatures;
use App\Modules\Documents\Services\UnitStamps;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Pdfs;
use Tests\TestCase;

/**
 * Comments on a document and its supporting files (documents design §7.5).
 */
class DocumentCommentsTest extends TestCase
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

        foreach ($units as $key => $unit) {
            $employee = Employee::factory()->create();
            $this->people[$key] = $employee;
            $this->accounts[$key] = User::factory()->create(['employee_id' => $employee->id, 'must_change_password' => false]);
            $unit->update(['manager_id' => $employee->id]);

            app(UnitStamps::class)->replace($unit, UploadedFile::fake()->image('stamp.png', 300, 300), $this->accounts[$key]);
            app(Signatures::class)->draw($employee, UploadedFile::fake()->image('s.png', 600, 200), SignatureSource::App, $this->accounts[$key]);
        }
    }

    private function send(string $title, string $as = 'c', string $to = 'a1', int $attachments = 0): Document
    {
        Sanctum::actingAs($this->accounts[$as]);

        $payload = [
            'title'     => $title,
            'file'      => UploadedFile::fake()->createWithContent('letter.pdf', Pdfs::plain()),
            'approvers' => [$this->people[$to]->id],
        ];

        if ($attachments > 0) {
            $payload['attachments'] = [UploadedFile::fake()->createWithContent('note.pdf', 'attachment-bytes')];
        }

        $this->post('/api/documents', $payload, ['Accept' => 'application/json'])->assertCreated();

        return Document::query()->latest('id')->firstOrFail();
    }

    public function test_parties_comment_and_read_each_other(): void
    {
        $document = $this->send('مراسلة');

        Sanctum::actingAs($this->accounts['c']);
        $this->postJson("/api/documents/{$document->id}/comments", ['body' => '  هل وصلك؟  '])
            ->assertCreated()
            ->assertJsonPath('message', 'تمت إضافة التعليق.')
            ->assertJsonPath('data.body', 'هل وصلك؟')
            ->assertJsonPath('data.author.id', $this->people['c']->id);

        Sanctum::actingAs($this->accounts['a1']);
        $this->postJson("/api/documents/{$document->id}/comments", ['body' => 'نعم وصلني'])->assertCreated();

        foreach (['c', 'a1'] as $who) {
            Sanctum::actingAs($this->accounts[$who]);
            $response = $this->getJson("/api/documents/{$document->id}/comments")->assertOk();

            $this->assertSame(['هل وصلك؟', 'نعم وصلني'], array_column($response->json('data'), 'body'));
            $this->assertSame(
                [$this->people['c']->id, $this->people['a1']->id],
                array_column(array_column($response->json('data'), 'author'), 'id'),
            );
            $this->assertSame($this->people['c']->full_name, $response->json('data.0.author.full_name'));
            $this->assertNotNull($response->json('data.0.created_at'));
        }
    }

    public function test_a_comment_needs_a_body(): void
    {
        $document = $this->send('مراسلة');
        Sanctum::actingAs($this->accounts['c']);

        $this->postJson("/api/documents/{$document->id}/comments", [])
            ->assertStatus(422)
            ->assertJsonPath('errors.body.0', 'نص التعليق مطلوب.');

        $this->postJson("/api/documents/{$document->id}/comments", ['body' => "   \n "])
            ->assertStatus(422)
            ->assertJsonPath('errors.body.0', 'نص التعليق مطلوب.');

        $this->postJson("/api/documents/{$document->id}/comments", ['body' => str_repeat('ا', 2001)])
            ->assertStatus(422)
            ->assertJsonPath('errors.body.0', 'التعليق لا يزيد على 2000 حرف.');

        $this->postJson("/api/documents/{$document->id}/comments", ['body' => str_repeat('ا', 2000)])->assertCreated();
    }

    public function test_an_outsider_neither_reads_nor_writes_comments(): void
    {
        $document = $this->send('مراسلة');

        $names = Permission::query()->where('name', '!=', 'documents.view')->pluck('name')->all();
        $this->actingAsUserWithPermissions($names);

        $this->getJson("/api/documents/{$document->id}/comments")->assertForbidden();
        $this->postJson("/api/documents/{$document->id}/comments", ['body' => 'x'])->assertForbidden();
        $this->assertSame(0, $document->comments()->count());
    }

    public function test_a_deleted_document_answers_410(): void
    {
        $document = $this->send('مراسلة', attachments: 1);
        $attachment = $document->attachments()->firstOrFail();
        $document->delete();

        Sanctum::actingAs($this->accounts['c']);
        $this->getJson("/api/documents/{$document->id}/comments")->assertStatus(410);
        $this->postJson("/api/documents/{$document->id}/comments", ['body' => 'x'])->assertStatus(410);
        $this->getJson("/api/documents/{$document->id}/attachments/{$attachment->id}")->assertStatus(410);
    }

    public function test_an_attachment_is_streamed_to_parties_only(): void
    {
        $document = $this->send('مراسلة', attachments: 1);
        $other = $this->send('أخرى', attachments: 1);
        $attachment = $document->attachments()->firstOrFail();
        $foreign = $other->attachments()->firstOrFail();

        foreach (['c', 'a1'] as $who) {
            Sanctum::actingAs($this->accounts[$who]);
            $response = $this->get("/api/documents/{$document->id}/attachments/{$attachment->id}")->assertOk();

            $this->assertSame('attachment-bytes', $response->streamedContent());
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        }

        // Another document's file is not this document's.
        $this->getJson("/api/documents/{$document->id}/attachments/{$foreign->id}")->assertNotFound();

        $names = Permission::query()->where('name', '!=', 'documents.view')->pluck('name')->all();
        $this->actingAsUserWithPermissions($names);
        $this->getJson("/api/documents/{$document->id}/attachments/{$attachment->id}")->assertForbidden();
    }
}
