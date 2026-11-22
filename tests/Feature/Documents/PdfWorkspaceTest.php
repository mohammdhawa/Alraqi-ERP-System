<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Documents\Pdf\PdfSession;
use App\Modules\Documents\Pdf\PdfWorkspace;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Every print runs in a folder of its own, deleted when the print ends,
 * success or failure (documents design §11.3): mPDF writes decrypted PNGs and
 * their masks to its temp directory, and a signature must never outlive the
 * print that used it.
 */
class PdfWorkspaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_a_print_runs_in_its_own_folder_that_is_gone_afterwards(): void
    {
        $seen = null;

        $result = app(PdfWorkspace::class)->run(function (PdfSession $session) use (&$seen): string {
            $seen = $session->directory;

            $this->assertTrue(is_dir($session->directory));
            $this->assertStringStartsWith(
                $this->normalise(Storage::disk('local')->path('pdf-work')),
                $this->normalise($session->directory),
            );

            return 'printed';
        });

        $this->assertSame('printed', $result);
        $this->assertNotNull($seen);
        $this->assertDirectoryDoesNotExist($seen);
    }

    public function test_the_folder_is_removed_when_the_print_fails(): void
    {
        $seen = null;

        try {
            app(PdfWorkspace::class)->run(function (PdfSession $session) use (&$seen): void {
                $seen = $session->directory;
                file_put_contents($session->path('x.png'), 'decrypted signature');

                throw new RuntimeException('printing failed');
            });

            $this->fail('the failure should propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('printing failed', $e->getMessage());
        }

        $this->assertNotNull($seen);
        $this->assertDirectoryDoesNotExist($seen);
    }

    public function test_mpdf_prints_arabic_in_the_session_folder(): void
    {
        app(PdfWorkspace::class)->run(function (PdfSession $session): void {
            $mpdf = $session->mpdf();
            $mpdf->WriteHTML('<p dir="rtl">قرأت ووافقت</p>');

            $this->assertStringStartsWith('%PDF-', $mpdf->Output('', 'S'));
            // mPDF 8.3 caches under {tempDir}/mpdf/ttfontdata: its existence
            // here proves tempDir is this print's folder.
            $this->assertDirectoryExists($session->directory.'/mpdf/ttfontdata');
        });
    }

    public function test_two_prints_do_not_share_a_folder(): void
    {
        $workspace = app(PdfWorkspace::class);

        $outer = $workspace->run(function (PdfSession $first) use ($workspace): array {
            $inner = $workspace->run(static fn (PdfSession $second): string => $second->directory);

            return [$first->directory, $inner];
        });

        $this->assertNotSame($outer[0], $outer[1]);
    }

    private function normalise(string $path): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', $path);
    }
}
