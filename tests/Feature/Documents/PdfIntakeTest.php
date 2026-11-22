<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Documents\Enums\IntakeOutcome;
use App\Modules\Documents\Exceptions\UnreadablePdfException;
use App\Modules\Documents\Exceptions\UnsupportedUploadException;
use App\Modules\Documents\Pdf\AcceptedPdf;
use App\Modules\Documents\Pdf\PdfIntake;
use App\Modules\Documents\Pdf\PdfSession;
use App\Modules\Documents\Pdf\PdfWorkspace;
use App\Modules\Documents\Pdf\Repair\PdfRepairer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Pdfs;
use Tests\TestCase;

/**
 * The upload check (documents design §11.1, §11.5): a PDF is opened by the
 * printing routine when it is uploaded, so no signature can later fail on
 * its file. One the printing cannot open is converted on the server (the
 * owner's answer of 2026-11-22), and refused if it still cannot be opened.
 */
class PdfIntakeTest extends TestCase
{
    private const UNREADABLE = 'تعذّر تجهيز هذا الملف للتوقيع. يُرجى حفظه من جديد بصيغة PDF (مثل Microsoft Print to PDF) ثم رفعه.';

    private const UNSUPPORTED = 'يُرجى رفع الملف بصيغة PDF أو صورة.';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * @param  callable(AcceptedPdf, PdfSession): void  $then
     */
    private function accept(UploadedFile $file, ?callable $then = null): void
    {
        app(PdfWorkspace::class)->run(function (PdfSession $session) use ($file, $then): void {
            $accepted = app(PdfIntake::class)->accept($file, $session);

            if ($then !== null) {
                $then($accepted, $session);
            }
        });
    }

    /**
     * A repairer that "converts" by writing $writes to the target, or fails.
     */
    private function repairerWriting(?string $writes): void
    {
        $this->app->instance(PdfRepairer::class, new class($writes) implements PdfRepairer
        {
            public int $calls = 0;

            public function __construct(private readonly ?string $writes) {}

            public function repair(string $source, string $target): bool
            {
                $this->calls++;

                if ($this->writes === null) {
                    return false;
                }

                file_put_contents($target, $this->writes);

                return true;
            }
        });
    }

    public function test_a_readable_pdf_is_accepted_as_uploaded(): void
    {
        $this->repairerWriting(null);
        $bytes = Pdfs::plain();

        $this->accept(UploadedFile::fake()->createWithContent('عقد.pdf', $bytes), function (AcceptedPdf $accepted) use ($bytes): void {
            $this->assertSame(IntakeOutcome::AsUploaded, $accepted->outcome);
            $this->assertSame(1, $accepted->pageCount);
            $this->assertNull($accepted->uploadedPath);
            $this->assertSame($bytes, file_get_contents($accepted->printablePath), 'a readable PDF is kept byte for byte');
        });

        $this->assertSame(0, app(PdfRepairer::class)->calls, 'a readable PDF is never converted');
    }

    public function test_a_pdf_with_an_xref_stream_is_repaired(): void
    {
        $this->repairerWriting(Pdfs::plain());
        $original = Pdfs::withXrefStream();

        $this->accept(UploadedFile::fake()->createWithContent('scan.pdf', $original), function (AcceptedPdf $accepted, PdfSession $session) use ($original): void {
            $this->assertSame(IntakeOutcome::Repaired, $accepted->outcome);
            $this->assertSame(1, $accepted->pageCount);
            $this->assertNotNull($accepted->uploadedPath);
            $this->assertSame($original, file_get_contents($accepted->uploadedPath), 'the file as uploaded is kept beside the converted one');
            $this->assertSame(1, app(PdfIntake::class)->pageCount($accepted->printablePath, $session));
        });
    }

    public function test_a_pdf_that_stays_unreadable_after_repair_is_refused(): void
    {
        $this->repairerWriting(null);

        try {
            $this->accept(UploadedFile::fake()->createWithContent('scan.pdf', Pdfs::withXrefStream()));
            $this->fail('an unreadable PDF must be refused');
        } catch (UnreadablePdfException $e) {
            $this->assertSame(self::UNREADABLE, $e->getMessage());
            $this->assertSame(422, $e->statusCode());
        }
    }

    public function test_a_repair_that_writes_an_unreadable_file_is_refused(): void
    {
        $this->repairerWriting(Pdfs::withXrefStream());

        $this->expectException(UnreadablePdfException::class);
        $this->expectExceptionMessage(self::UNREADABLE);

        $this->accept(UploadedFile::fake()->createWithContent('scan.pdf', Pdfs::withXrefStream()));
    }

    public function test_a_file_that_is_not_a_pdf_inside_is_refused(): void
    {
        $this->repairerWriting(null);

        $this->expectException(UnreadablePdfException::class);

        $this->accept(UploadedFile::fake()->createWithContent('fake.pdf', 'not a pdf at all'));
    }

    /**
     * Final review, 2026-11-22: counting pages is not opening them. A PDF
     * whose page cannot be imported must be refused at upload, not fail when
     * the first manager signs (design §11.1).
     */
    public function test_a_pdf_whose_page_cannot_be_imported_is_refused(): void
    {
        $this->repairerWriting(null);

        foreach (['missing page' => Pdfs::withMissingPage(), 'empty page tree' => Pdfs::withEmptyKids()] as $case => $bytes) {
            try {
                $this->accept(UploadedFile::fake()->createWithContent('عقد.pdf', $bytes));
                $this->fail("a PDF with a {$case} must be refused");
            } catch (UnreadablePdfException $e) {
                $this->assertSame(self::UNREADABLE, $e->getMessage(), $case);
            }
        }
    }

    public function test_a_truncated_pdf_is_refused_not_a_500(): void
    {
        $this->repairerWriting(null);

        $this->expectException(UnreadablePdfException::class);

        $this->accept(UploadedFile::fake()->createWithContent('عقد.pdf', Pdfs::truncated(Pdfs::plain())));
    }

    public function test_a_truncated_photo_is_refused_not_a_500(): void
    {
        $this->repairerWriting(null);

        foreach (['p.png' => UploadedFile::fake()->image('p.png', 800, 600), 'p.jpg' => UploadedFile::fake()->image('p.jpg', 800, 600)] as $name => $photo) {
            $bytes = Pdfs::truncated((string) file_get_contents((string) $photo->getRealPath()));

            try {
                $this->accept(UploadedFile::fake()->createWithContent($name, $bytes));
                $this->fail("a truncated {$name} must be refused");
            } catch (UnsupportedUploadException $e) {
                $this->assertSame(self::UNSUPPORTED, $e->getMessage(), $name);
            }
        }
    }

    public function test_a_photo_becomes_a_one_page_pdf(): void
    {
        $this->repairerWriting(null);

        foreach ([UploadedFile::fake()->image('p.jpg', 1200, 1600), UploadedFile::fake()->image('p.png', 1600, 900)] as $photo) {
            $this->accept($photo, function (AcceptedPdf $accepted, PdfSession $session): void {
                $this->assertSame(IntakeOutcome::FromPhoto, $accepted->outcome);
                $this->assertSame(1, $accepted->pageCount);
                $this->assertNull($accepted->uploadedPath);
                $this->assertStringStartsWith('%PDF-', (string) file_get_contents($accepted->printablePath));
                $this->assertSame(1, app(PdfIntake::class)->pageCount($accepted->printablePath, $session));
            });
        }
    }

    public function test_anything_else_is_refused(): void
    {
        $this->repairerWriting(null);

        $files = [
            UploadedFile::fake()->create('عقد.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            UploadedFile::fake()->create('notes.txt', 1, 'text/plain'),
        ];

        foreach ($files as $file) {
            try {
                $this->accept($file);
                $this->fail('a '.$file->getClientOriginalName().' must be refused');
            } catch (UnsupportedUploadException $e) {
                $this->assertSame(self::UNSUPPORTED, $e->getMessage());
                $this->assertSame(422, $e->statusCode());
            }
        }
    }

    public function test_the_check_and_repair_leave_nothing_outside_the_session(): void
    {
        $this->repairerWriting(Pdfs::plain());

        $this->accept(UploadedFile::fake()->createWithContent('scan.pdf', Pdfs::withXrefStream()));
        $this->accept(UploadedFile::fake()->image('p.png', 300, 300));

        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
