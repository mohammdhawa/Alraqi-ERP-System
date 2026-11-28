<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Auth\Models\User;
use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Enums\Confirmation;
use App\Modules\Documents\Enums\DocumentKind;
use App\Modules\Documents\Enums\DocumentOrigin;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Enums\MarkKind;
use App\Modules\Documents\Enums\SignatureSource;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\EmployeeSignature;
use App\Modules\Documents\Models\UnitStamp;
use App\Modules\Documents\Pdf\DocumentPrinter;
use App\Modules\Documents\Pdf\PdfIntake;
use App\Modules\Documents\Pdf\PdfWorkspace;
use App\Modules\Documents\Services\Signatures;
use App\Modules\Documents\Services\UnitStamps;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Normalizer;
use Tests\Support\Pdfs;
use Tests\TestCase;
use Throwable;

/**
 * The printed copy (documents design §11.4): the original's pages, then the
 * marks as cards on the last page. Always a new file; the original is never
 * touched, and no decrypted image outlives the print.
 */
class DocumentPrinterTest extends TestCase
{
    use RefreshDatabase;

    private Department $unit;

    private Employee $employee;

    private User $user;

    private Document $document;

    private UnitStamp $stamp;

    private EmployeeSignature $signature;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $root = Department::create(['name' => 'الإدارة العامة', 'level' => 1]);
        $this->unit = Department::create(['name' => 'إدارة الموارد البشرية', 'level' => 2, 'parent_id' => $root->id, 'sector' => 1]);
        $this->employee = Employee::factory()->create();
        $this->user = User::factory()->create(['employee_id' => $this->employee->id, 'must_change_password' => false]);

        $this->stamp = app(UnitStamps::class)->replace($this->unit, UploadedFile::fake()->image('stamp.png', 300, 300), $this->user);
        $this->signature = app(Signatures::class)->draw($this->employee, UploadedFile::fake()->image('sig.png', 600, 200), SignatureSource::Dashboard, $this->user);

        $folder = (string) Str::uuid();
        Storage::disk('local')->put("documents/{$folder}/original.pdf", Pdfs::plain());

        $this->document = Document::create([
            'kind'             => DocumentKind::Internal,
            'origin'           => DocumentOrigin::Uploaded,
            'title'            => 'تعميم',
            'creator_id'       => $this->employee->id,
            'creator_unit_id'  => $this->unit->id,
            'register_unit_id' => $this->unit->id,
            'created_by'       => $this->user->id,
            'status'           => DocumentStatus::Pending,
            'folder'           => $folder,
            'original_path'    => "documents/{$folder}/original.pdf",
        ]);
    }

    private function mark(int $position, MarkKind $kind = MarkKind::ApprovalCard, bool $decisive = false, ?string $note = null, ?UnitStamp $stamp = null): void
    {
        $this->document->marks()->create([
            'position'      => $position,
            'kind'          => $kind,
            'employee_id'   => $this->employee->id,
            'employee_name' => 'محمد الأحمد',
            'unit_id'       => $this->unit->id,
            'unit_name'     => $this->unit->name,
            'stamp_id'      => ($stamp ?? $this->stamp)->id,
            'signature_id'  => $this->signature->id,
            'decisive'      => $decisive,
            'note'          => $note,
            'confirmed_by'  => Confirmation::Password,
            'marked_at'     => now(),
        ]);
    }

    private function print(): string
    {
        return app(DocumentPrinter::class)->print($this->document->fresh());
    }

    private function pagesOf(string $path): ?int
    {
        return app(PdfWorkspace::class)->run(
            fn ($session) => app(PdfIntake::class)->pageCount(Storage::disk('local')->path($path), $session)
        );
    }

    public function test_a_card_is_printed_on_a_copy(): void
    {
        $this->mark(1, note: 'موافق');
        $originalBytes = Storage::disk('local')->get($this->document->original_path);

        $path = $this->print();

        $this->assertStringStartsWith("documents/{$this->document->folder}/printed/", $path);
        $this->assertStringEndsWith('.pdf', $path);
        Storage::disk('local')->assertExists($path);
        $this->assertSame($this->pagesOf($this->document->original_path), $this->pagesOf($path));
        $this->assertSame($originalBytes, Storage::disk('local')->get($this->document->original_path));
        $this->assertNull($this->document->fresh()->printed_path);
    }

    public function test_a_rejection_and_a_decider_card_print(): void
    {
        $this->mark(1, MarkKind::RejectionCard, note: str_repeat('سبب ', 60));
        $this->mark(2, decisive: true);

        $this->assertNotNull($this->pagesOf($this->print()));
    }

    public function test_eleven_cards_print(): void
    {
        foreach (range(1, 11) as $position) {
            $this->mark($position, decisive: $position === 11);
        }

        $this->assertSame(1, $this->pagesOf($this->print()));
    }

    public function test_a_rebuild_prints_each_marks_own_stamp_version(): void
    {
        $this->mark(1);
        $old = $this->stamp;

        $new = app(UnitStamps::class)->replace($this->unit, UploadedFile::fake()->image('new.png', 300, 300), $this->user);
        Storage::disk('local')->delete($new->path);

        $this->assertNotNull($this->pagesOf($this->print()));
        $this->assertNotSame($old->id, $new->id);
    }

    public function test_no_decrypted_image_is_left_on_disk(): void
    {
        $this->mark(1);

        $this->print();

        $this->assertSame([], Storage::disk('local')->allFiles('pdf-work'));
    }

    /** The employee's answer to a sent document: no stamp, no unit, no approval step. */
    private function employeeMark(): void
    {
        $this->document->marks()->create([
            'position'      => 1,
            'kind'          => MarkKind::EmployeeSignature,
            'employee_id'   => $this->employee->id,
            'employee_name' => 'سامر الخطيب',
            'signature_id'  => $this->signature->id,
            'decisive'      => false,
            'confirmed_by'  => Confirmation::Password,
            'marked_at'     => now(),
        ]);
    }

    /**
     * Images drawn straight onto each page (mPDF names them /I1, /I2 and
     * draws them with `Do` in the page's own content stream); the original's
     * pages are imported as forms and draw none. No text extractor needed.
     *
     * @return list<int>
     */
    private function imagesPerPage(string $path): array
    {
        $bytes = Storage::disk('local')->get($path);
        preg_match_all('/\d+ 0 obj\r?\n<<\r?\n?\/Type \/Page\r?\n(.*?)>>\r?\nendobj/s', $bytes, $pages);
        $counts = [];

        foreach ($pages[1] as $page) {
            preg_match('/\/Contents (\d+) 0 R/', $page, $contents);
            $this->assertNotEmpty($contents, 'a page without a content stream');
            preg_match('/\b'.$contents[1].' 0 obj\r?\n<<.*?>>\r?\nstream\r?\n(.*?)\r?\nendstream/s', $bytes, $stream);
            $counts[] = preg_match_all('/\/I\d+ Do/', (string) @gzuncompress($stream[1] ?? ''));
        }

        return $counts;
    }

    private function pdftotextAvailable(): bool
    {
        try {
            $probe = Process::run(['pdftotext', '-v']);

            return str_contains($probe->output().$probe->errorOutput(), 'pdftotext version');
        } catch (Throwable) {
            return false;
        }
    }

    public function test_the_employees_signature_prints_on_the_last_page(): void
    {
        if (! $this->pdftotextAvailable()) {
            $this->markTestSkipped('pdftotext is not installed here; nothing in the repository extracts text from a PDF.');
        }

        $this->employeeMark();

        $path = $this->print();

        $this->assertSame($this->pagesOf($this->document->original_path), $this->pagesOf($path));
        $this->assertSame([1], $this->imagesPerPage($path));

        $text = Process::run(['pdftotext', '-enc', 'UTF-8', Storage::disk('local')->path($path), '-'])->throw()->output();
        $text = (string) preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', (string) Normalizer::normalize($text, Normalizer::FORM_KC));

        foreach (['قرأت', 'ووافقت', 'سامر', 'الخطيب', 'رقم المستند: '.$this->document->id] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
    }

    public function test_the_signature_block_follows_the_text_rule(): void
    {
        $original = $this->pagesOf($this->document->original_path);
        $this->assertSame(1, $original);

        // The control: no mark, no image on any page.
        $this->assertSame([0], $this->imagesPerPage($this->print()));

        $this->employeeMark();

        // An upload: its text end is unknown, so the block is drawn on the last page.
        $this->assertNull($this->document->content_bottom);
        $this->assertSame([1], $this->imagesPerPage($this->print()));

        // A template document whose text ends below the block's top (266 mm): a new page holds it.
        $this->document->update(['content_bottom' => 270]);
        $this->assertSame([0, 1], $this->imagesPerPage($this->print()));

        // The letterhead's footer raises the block: the same text end now clears it.
        $this->document->update(['card_floor' => 32, 'content_bottom' => 200]);
        $this->assertSame([1], $this->imagesPerPage($this->print()));
    }

    public function test_no_decrypted_signature_is_left_on_disk(): void
    {
        $this->employeeMark();

        $path = $this->print();

        // The signature really was decrypted and drawn, and nothing of it stayed behind.
        $this->assertSame([1], $this->imagesPerPage($path));
        $this->assertSame([], Storage::disk('local')->allFiles('pdf-work'));
    }
}
