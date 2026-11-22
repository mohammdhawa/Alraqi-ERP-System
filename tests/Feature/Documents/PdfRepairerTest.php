<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Documents\Pdf\PdfIntake;
use App\Modules\Documents\Pdf\PdfSession;
use App\Modules\Documents\Pdf\PdfWorkspace;
use App\Modules\Documents\Pdf\Repair\GhostscriptRepairer;
use App\Modules\Documents\Pdf\Repair\NullRepairer;
use App\Modules\Documents\Pdf\Repair\PdfRepairer;
use App\Modules\Documents\Pdf\Repair\QpdfRepairer;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\Pdfs;
use Tests\TestCase;
use Throwable;

/**
 * What rewrites a PDF the printing cannot open (documents design §11.5): qpdf
 * by default, Ghostscript as the alternative, chosen in config/documents.php.
 * A missing or failing tool is a failed repair, never a 500: the upload is
 * then refused with the intake's message.
 */
class PdfRepairerTest extends TestCase
{
    public function test_qpdf_is_called_with_object_streams_disabled(): void
    {
        Process::fake();

        $this->assertTrue((new QpdfRepairer('qpdf', 60))->repair('in.pdf', 'out.pdf'));

        Process::assertRan(static fn (PendingProcess $process): bool => $process->command === ['qpdf', '--object-streams=disable', 'in.pdf', 'out.pdf']
            && $process->timeout === 60);
    }

    public function test_qpdf_warnings_still_succeed(): void
    {
        Process::fake(['*' => Process::result(exitCode: 3)]);
        $this->assertTrue((new QpdfRepairer('qpdf', 60))->repair('in.pdf', 'out.pdf'), 'exit 3 is "succeeded with warnings"');

        Process::fake(['*' => Process::result(exitCode: 2)]);
        $this->assertFalse((new QpdfRepairer('qpdf', 60))->repair('in.pdf', 'out.pdf'), 'exit 2 is an error');
    }

    public function test_qpdf_missing_counts_as_a_failed_repair(): void
    {
        Log::spy();

        Process::fake(['*' => Process::result(errorOutput: 'qpdf: not found', exitCode: 127)]);
        $this->assertFalse((new QpdfRepairer('qpdf', 60))->repair('in.pdf', 'out.pdf'));

        Process::fake(static function (): never {
            throw new RuntimeException('The command "qpdf" could not be started.');
        });
        $this->assertFalse((new QpdfRepairer('qpdf', 60))->repair('in.pdf', 'out.pdf'));

        Log::shouldHaveReceived('warning')->twice();
    }

    public function test_ghostscript_rewrites_to_pdf_1_4(): void
    {
        Process::fake();

        $this->assertTrue((new GhostscriptRepairer('gs', 45))->repair('in.pdf', 'out.pdf'));

        Process::assertRan(static fn (PendingProcess $process): bool => $process->command === [
            'gs', '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-sDEVICE=pdfwrite', '-dCompatibilityLevel=1.4', '-sOutputFile=out.pdf', 'in.pdf',
        ] && $process->timeout === 45);

        Process::fake(['*' => Process::result(exitCode: 1)]);
        $this->assertFalse((new GhostscriptRepairer('gs', 45))->repair('in.pdf', 'out.pdf'));
    }

    public function test_the_driver_is_chosen_from_config(): void
    {
        config(['documents.pdf.repair.driver' => 'none']);
        $this->assertInstanceOf(NullRepairer::class, app(PdfRepairer::class));
        $this->assertFalse(app(PdfRepairer::class)->repair('in.pdf', 'out.pdf'));

        config(['documents.pdf.repair.driver' => 'ghostscript', 'documents.pdf.repair.binary' => 'gs']);
        $this->assertInstanceOf(GhostscriptRepairer::class, app(PdfRepairer::class));

        config(['documents.pdf.repair.driver' => 'qpdf', 'documents.pdf.repair.binary' => 'qpdf']);
        $this->assertInstanceOf(QpdfRepairer::class, app(PdfRepairer::class));
    }

    public function test_a_real_qpdf_repairs_the_xref_stream_fixture(): void
    {
        try {
            $available = Process::run(['qpdf', '--version'])->successful();
        } catch (Throwable) {
            $available = false;
        }

        if (! $available) {
            $this->markTestSkipped('qpdf is not installed here; the production server must have it (documents design §11.5).');
        }

        Storage::fake('local');

        app(PdfWorkspace::class)->run(function (PdfSession $session): void {
            file_put_contents($source = $session->path('in.pdf'), Pdfs::withXrefStream());
            $intake = app(PdfIntake::class);

            $this->assertNull($intake->pageCount($source, $session), 'the fixture must be unreadable before the repair');
            $this->assertTrue((new QpdfRepairer('qpdf', 60))->repair($source, $target = $session->path('out.pdf')));
            $this->assertSame(1, $intake->pageCount($target, $session));
        });
    }
}
