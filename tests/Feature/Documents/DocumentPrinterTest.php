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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Pdfs;
use Tests\TestCase;

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
}
