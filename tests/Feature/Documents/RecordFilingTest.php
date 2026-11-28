<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Auth\Models\User;
use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Enums\DocumentKind;
use App\Modules\Documents\Enums\DocumentOrigin;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Services\DocumentOriginals;
use App\Modules\Documents\Services\RecordFiling;
use App\Modules\HR\Enums\EmployeeDocumentType;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeDocument;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * Filing an answered document on its recipient's record (documents to
 * employees, "Filing on the record"): one row, pointing at the document's own
 * file.
 */
class RecordFilingTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGINAL = "%PDF-1.4 the copy as sent\n";

    private const PRINTED = "%PDF-1.4 the copy as signed, a little longer\n";

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function document(DocumentStatus $status): Document
    {
        Storage::fake(DocumentOriginals::DISK);

        $root = Department::create(['name' => 'الإدارة العامة', 'level' => 1]);
        $unit = Department::create(['name' => 'إدارة الموارد البشرية', 'level' => 2, 'parent_id' => $root->id, 'sector' => 1]);
        $sender = Employee::factory()->create();
        $senderAccount = User::factory()->create(['employee_id' => $sender->id, 'must_change_password' => false]);
        $recipient = Employee::factory()->create();
        $folder = (string) Str::uuid();

        $disk = Storage::disk(DocumentOriginals::DISK);
        $disk->put("documents/{$folder}/original.pdf", self::ORIGINAL);
        $disk->put("documents/{$folder}/printed.pdf", self::PRINTED);

        return Document::create([
            'kind'             => DocumentKind::ToEmployee,
            'origin'           => DocumentOrigin::Uploaded,
            'title'            => 'قرار تعيين',
            'creator_id'       => $sender->id,
            'creator_unit_id'  => $unit->id,
            'register_unit_id' => $unit->id,
            'created_by'       => $senderAccount->id,
            'status'           => $status,
            'recipient_id'     => $recipient->id,
            'filing_type'      => EmployeeDocumentType::AppointmentDecision,
            'sent_at'          => now()->subDay(),
            'signed_at'        => $status === DocumentStatus::Signed ? now() : null,
            'refused_at'       => $status === DocumentStatus::Refused ? now() : null,
            'refusal_reason'   => $status === DocumentStatus::Refused ? 'لا أوافق على البنود' : null,
            'folder'           => $folder,
            'original_path'    => "documents/{$folder}/original.pdf",
            'printed_path'     => $status === DocumentStatus::Signed ? "documents/{$folder}/printed.pdf" : null,
        ]);
    }

    public function test_a_signed_document_is_filed_from_its_printed_copy(): void
    {
        // 22:30 UTC on the 26th is 01:30 on the 27th in Damascus (UTC+3).
        Carbon::setTestNow(Carbon::parse('2026-11-26 22:30:00', 'UTC'));
        $document = $this->document(DocumentStatus::Signed);

        $row = app(RecordFiling::class)->file($document);

        $this->assertTrue($row->exists);
        $this->assertSame($document->recipient_id, $row->employee_id);
        $this->assertSame(EmployeeDocumentType::AppointmentDecision, $row->type);
        $this->assertSame('قرار تعيين', $row->title);
        $this->assertSame($document->printed_path, $row->file_path);
        $this->assertSame('قرار تعيين.pdf', $row->original_name);
        $this->assertSame('application/pdf', $row->mime_type);
        $this->assertSame(strlen(self::PRINTED), $row->size_bytes);
        $this->assertSame($document->created_by, $row->uploaded_by);
        $this->assertSame('2026-11-27', $row->issued_at->format('Y-m-d'));
        $this->assertSame($document->id, $row->document_id);
        $this->assertNull($row->expires_at);

        $this->assertDatabaseCount('employee_documents', 1);
        $this->assertTrue($document->filedRecord()->first()->is($row));
    }

    public function test_a_refused_document_is_filed_from_the_copy_as_sent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-26 10:00:00', 'UTC'));
        $document = $this->document(DocumentStatus::Refused);

        $row = app(RecordFiling::class)->file($document);

        $this->assertSame($document->original_path, $row->file_path);
        $this->assertSame(strlen(self::ORIGINAL), $row->size_bytes);
        $this->assertSame('2026-11-26', $row->issued_at->format('Y-m-d'));
        $this->assertSame($document->recipient_id, $row->employee_id);
        $this->assertSame($document->id, $row->document_id);
        $this->assertSame('application/pdf', $row->mime_type);
    }

    public function test_a_pending_document_is_not_filed(): void
    {
        $document = $this->document(DocumentStatus::AwaitingSignature);

        try {
            app(RecordFiling::class)->file($document);
            $this->fail('An unanswered document must not be filed.');
        } catch (LogicException) {
            $this->assertDatabaseCount('employee_documents', 0);
        }
    }

    public function test_a_long_arabic_title_still_fits_the_original_name_column(): void
    {
        $document = $this->document(DocumentStatus::Signed);
        $document->update(['title' => str_repeat('ع', 255)]);

        $row = app(RecordFiling::class)->file($document->fresh())->fresh();

        $this->assertLessThanOrEqual(255, mb_strlen($row->original_name));
        $this->assertStringEndsWith('.pdf', $row->original_name);
        $this->assertSame(str_repeat('ع', 255), $row->title);
    }

    public function test_a_document_is_filed_only_once(): void
    {
        $document = $this->document(DocumentStatus::Signed);
        app(RecordFiling::class)->file($document);

        try {
            app(RecordFiling::class)->file($document->fresh());
            $this->fail('A filed document must not be filed again.');
        } catch (LogicException) {
            $this->assertDatabaseCount('employee_documents', 1);
        }
    }

    /**
     * One row per answered document holds in the schema as well as in the
     * guard above: `document_id` is unique, so a write that skips the guard
     * still cannot file a document twice, while the rows that file no
     * document (hand-filed, imported) all keep their null.
     */
    public function test_the_schema_files_a_document_only_once(): void
    {
        $unique = array_values(array_map(
            static fn (array $index): array => $index['columns'],
            array_filter(Schema::getIndexes('employee_documents'), static fn (array $index): bool => $index['unique']),
        ));
        $this->assertContains(['document_id'], $unique);

        $document = $this->document(DocumentStatus::Signed);
        $row = app(RecordFiling::class)->file($document);

        $row->replicate()->fill(['document_id' => null])->save();
        $row->replicate()->fill(['document_id' => null])->save();
        $this->assertSame(2, EmployeeDocument::query()->whereNull('document_id')->count());

        try {
            $row->replicate()->save();
            $this->fail('A second row for one document must not be written.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(1, EmployeeDocument::query()->where('document_id', $document->id)->count());
        }
    }

    public function test_the_filed_row_reads_its_document(): void
    {
        $document = $this->document(DocumentStatus::Refused);

        $row = app(RecordFiling::class)->file($document)->fresh();

        $this->assertTrue($row->document->is($document));
        $this->assertSame('لا أوافق على البنود', $row->document->refusal_reason);
        $this->assertSame(DocumentStatus::Refused, $row->document->status);
    }
}
