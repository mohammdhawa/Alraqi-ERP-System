<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Auth\Models\User;
use App\Modules\Documents\Enums\LetterheadPart;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Documents\Pdf\PdfIntake;
use App\Modules\Documents\Pdf\PdfSession;
use App\Modules\Documents\Pdf\PdfWorkspace;
use App\Modules\Documents\Pdf\RefusingAssetFetcher;
use App\Modules\Documents\Pdf\RenderedTemplate;
use App\Modules\Documents\Pdf\TemplateHtml;
use App\Modules\Documents\Pdf\TemplatePrinter;
use App\Modules\Documents\Services\Letterhead;
use App\Modules\Documents\Support\SystemValues;
use App\Modules\Documents\Templates\TemplateBody;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Mpdf\AssetFetcher;
use Mpdf\Exception\AssetFetchingException;
use Normalizer;
use Tests\TestCase;
use Throwable;

/**
 * A template printed to a PDF in the company's letterhead (documents design
 * §11.2). mPDF fetches nothing: images reach it as variables only.
 */
class TemplatePrinterTest extends TestCase
{
    use RefreshDatabase;

    private const IMPORT = '@import url(http://169.254.169.254/x.css); @import "x.css";';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->user = User::factory()->create();
    }

    private function letterhead(LetterheadPart ...$parts): void
    {
        foreach ($parts as $part) {
            $height = $part === LetterheadPart::Header ? 200 : 120;
            app(Letterhead::class)->replace($part, UploadedFile::fake()->image("{$part->value}.png", 1200, $height), $this->user);
        }
    }

    /** A page as it is stored: through the sanitiser. */
    private function template(string $html, array $overrides = []): DocumentTemplate
    {
        return new DocumentTemplate($overrides + [
            'name'             => 'تعميم',
            'reference_prefix' => 'CIR',
            'fields'           => [],
            'body'             => (new TemplateBody)->sanitize($html),
            'show_letterhead'  => true,
            'for_employees'    => false,
        ]);
    }

    private function system(): SystemValues
    {
        return new SystemValues(12, null, 'CIR-2026-0001', CarbonImmutable::now(), 'محمد الأحمد', 'إدارة الموارد البشرية');
    }

    /**
     * Prints inside a session, and reads what it needs before the session's
     * folder is deleted.
     *
     * @return array{0: RenderedTemplate, 1: ?int, 2: string} the result, its pages, its bytes
     */
    private function print(DocumentTemplate $template, array $values = [], ?TemplatePrinter $printer = null): array
    {
        return app(PdfWorkspace::class)->run(function (PdfSession $session) use ($template, $values, $printer): array {
            $rendered = ($printer ?? app(TemplatePrinter::class))->print($template, $values, $this->system(), null, $session);

            $this->assertStringStartsWith($session->directory, $rendered->path);

            return [
                $rendered,
                app(PdfIntake::class)->pageCount($rendered->path, $session),
                (string) file_get_contents($rendered->path),
            ];
        });
    }

    private function images(string $pdf): int
    {
        return substr_count($pdf, '/Subtype /Image');
    }

    public function test_a_page_prints_as_a_pdf(): void
    {
        [$rendered, $pages, $bytes] = $this->print($this->template('<p>نص الصفحة مع Latin و 123.</p>'));

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertGreaterThanOrEqual(1, $pages);
        $this->assertNull($rendered->cardFloor);
    }

    public function test_the_letterhead_frames_the_page_and_sets_the_card_floor(): void
    {
        $page = '<p>'.str_repeat('سطر طويل من النص. ', 400).'</p>';

        $this->letterhead(LetterheadPart::Header);
        [$headerOnly, , $bytes] = $this->print($this->template($page));
        $this->assertNull($headerOnly->cardFloor);
        $this->assertSame(1, $this->images($bytes));

        $this->letterhead(LetterheadPart::Footer);
        [$framed, $pages, $bytes] = $this->print($this->template($page));
        $this->assertSame(32, $framed->cardFloor);
        $this->assertGreaterThan(1, $pages);
        $this->assertSame(2, $this->images($bytes), 'one image each, drawn on every page');

        [$plain, , $bytes] = $this->print($this->template($page, ['show_letterhead' => false]));
        $this->assertNull($plain->cardFloor);
        $this->assertSame(0, $this->images($bytes));
    }

    /**
     * Ruling R17: where the text of the last page ends, in mm from that
     * page's top (mPDF's y once the body is written), so the printed copy
     * can keep its cards off it.
     */
    public function test_it_records_where_the_last_pages_text_ends(): void
    {
        // Under the 20 mm top margin, a line or so down.
        [$short] = $this->print($this->template('<p>نص قصير</p>'));
        $this->assertGreaterThan(20, $short->contentBottom);
        $this->assertLessThan(45, $short->contentBottom);

        // The last of three pages holds one line: its own end, not the first page's.
        [$broken, $pages] = $this->print($this->template('<p>'.str_repeat('سطر طويل من النص. ', 150).'</p><div class="page-break"></div><p>ب</p><div class="page-break"></div><p>ج</p>'));
        $this->assertSame(3, $pages);
        $this->assertLessThan(45, $broken->contentBottom);

        // Text to the foot of the page, above a footer: the text area ends 30 mm above it.
        $this->letterhead(LetterheadPart::Footer);
        [$full, $pages] = $this->print($this->template(str_repeat('<p>سطر من النص</p>', 22)));
        $this->assertSame(1, $pages);
        $this->assertGreaterThan(240, $full->contentBottom);
        $this->assertLessThanOrEqual(267, $full->contentBottom);
    }

    public function test_two_page_breaks_make_three_pages(): void
    {
        [, $pages] = $this->print($this->template('<p>أ</p><div class="page-break"></div><p>ب</p><div class="page-break"></div><p>ج</p>'));

        $this->assertSame(3, $pages);
    }

    public function test_no_image_is_left_on_disk(): void
    {
        $this->letterhead(LetterheadPart::Header, LetterheadPart::Footer);

        $this->print($this->template('<p>نص</p>'));

        $this->assertSame([], Storage::disk('local')->allFiles('pdf-work'));
    }

    /**
     * Ruling R8: mPDF reads `@import` anywhere in the HTML it is given, and
     * the stored `&#64;` comes back as a bare `@` from the DOM and from
     * `e()`. A page and a value carrying one make no fetch at all.
     */
    public function test_an_import_in_a_page_or_a_value_fetches_nothing(): void
    {
        $fetcher = new class extends AssetFetcher
        {
            /** @var array<int, string> */
            public array $paths = [];

            public function __construct() {}

            public function fetchDataFromPath($path, $originalSrc = null)
            {
                $this->paths[] = (string) $path;

                throw new AssetFetchingException('refused');
            }
        };

        $printer = new class(app(Letterhead::class), app(TemplateHtml::class)) extends TemplatePrinter
        {
            public ?AssetFetcher $recorder = null;

            protected function fetcher(): AssetFetcher
            {
                return $this->recorder;
            }
        };
        $printer->recorder = $fetcher;

        // The control: the recorder is the fetcher mPDF uses.
        $this->print(new DocumentTemplate(['name' => 'x', 'fields' => [], 'body' => '<p><img src="http://169.254.169.254/x.png"></p>']), printer: $printer);
        $this->assertNotSame([], $fetcher->paths);
        $fetcher->paths = [];

        $subject = ['key' => 'subject', 'label' => 'الموضوع', 'type' => 'text', 'required' => false];
        $template = $this->template('<p>'.self::IMPORT.'</p><p><span data-placeholder="field.subject">الموضوع</span></p>', ['fields' => [$subject]]);
        $this->assertStringContainsString('&#64;import', (string) $template->body);

        [, $pages] = $this->print($template, ['subject' => self::IMPORT], $printer);

        $this->assertSame([], $fetcher->paths);
        $this->assertSame(1, $pages);
    }

    /**
     * mPDF replaces `{PAGENO}`, `{nb}` and `{nbpg}` anywhere in a page's
     * text with page numbers; typed in a value, they print as typed. mPDF
     * also drops a soft hyphen and a BOM from the text after the guard has
     * run, so a token they split must stay broken all the same. The page
     * streams are read inflated: mPDF writes body text there as UTF-16BE, as
     * its own replacement assumes, and a replaced token is no longer there.
     */
    public function test_page_number_tokens_in_a_value_print_as_typed(): void
    {
        $subject = ['key' => 'subject', 'label' => 'الموضوع', 'type' => 'text', 'required' => false];
        $template = $this->template('<p><span data-placeholder="field.subject">الموضوع</span></p>', ['fields' => [$subject]]);

        foreach ([
            'A {PAGENO} B {nb} C {nbpg} D'                                              => ['PAGENO}' => 1, 'nb}' => 1, 'nbpg}' => 1],
            "A {PAGE\u{00AD}NO} B {n\u{00AD}b} C {nb\u{00AD}pg} D {\u{00AD}nb} E"       => ['PAGENO}' => 1, 'nb}' => 2, 'nbpg}' => 1],
            "A {PAGE\u{FEFF}NO} B {n\u{FEFF}b} C {nbp\u{FEFF}g} D {\u{FEFF}nb} E"       => ['PAGENO}' => 1, 'nb}' => 2, 'nbpg}' => 1],
        ] as $typed => $expected) {
            [, , $bytes] = $this->print($template, ['subject' => $typed]);

            preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $streams);
            $content = implode("\n", array_map(static fn (string $s): string => (string) @gzuncompress($s), $streams[1]));

            foreach ($expected as $token => $count) {
                $this->assertSame($count, substr_count($content, mb_convert_encoding($token, 'UTF-16BE', 'UTF-8')), "{{$token} was replaced in «{$typed}»");
            }
        }
    }

    /**
     * Ruling R9: a template page prints in DejaVu Sans, whose Arabic copies
     * and searches as written. mPDF's automatic Arabic font (XB Riyaz) loses
     * the final ى and the medial ك to private-use characters. Read back with
     * pdftotext (xpdf or poppler) where it is installed.
     */
    public function test_arabic_text_extracts_as_written(): void
    {
        try {
            $probe = Process::run(['pdftotext', '-v']);
            $available = str_contains($probe->output().$probe->errorOutput(), 'pdftotext version');
        } catch (Throwable) {
            $available = false;
        }

        if (! $available) {
            $this->markTestSkipped('pdftotext is not installed here; nothing in the repository extracts text from a PDF.');
        }

        $text = app(PdfWorkspace::class)->run(function (PdfSession $session): string {
            $rendered = app(TemplatePrinter::class)->print($this->template('<p>مستشفى يكتب على مكتبة</p>'), [], $this->system(), null, $session);

            return Process::run(['pdftotext', '-enc', 'UTF-8', $rendered->path, '-'])->throw()->output();
        });

        // Presentation forms back to letters; the extractor's direction marks out.
        $text = (string) preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', (string) Normalizer::normalize($text, Normalizer::FORM_KC));

        foreach (['مستشفى', 'يكتب', 'على', 'مكتبة'] as $word) {
            $this->assertStringContainsString($word, $text);
        }
        $this->assertDoesNotMatchRegularExpression('/[\x{E000}-\x{F8FF}]/u', $text, 'a private-use character');
    }

    public function test_the_printer_itself_reads_no_file(): void
    {
        Storage::disk('local')->put('secret.png', UploadedFile::fake()->image('s.png', 50, 50)->getContent());
        $path = str_replace('\\', '/', Storage::disk('local')->path('secret.png'));

        // Never a stored page (the sanitiser removes images); the printer refuses it all the same.
        $template = new DocumentTemplate(['name' => 'x', 'fields' => [], 'body' => "<p><img src=\"{$path}\"><img src=\"file:///{$path}\"></p>"]);

        [, $pages, $bytes] = $this->print($template);

        $this->assertSame(1, $pages);
        $this->assertSame(0, $this->images($bytes));
    }

    public function test_the_refusing_fetcher_refuses_every_path(): void
    {
        foreach (['/etc/passwd', 'file:///etc/passwd', 'http://169.254.169.254/x.css', 'x.css'] as $path) {
            try {
                (new RefusingAssetFetcher)->fetchDataFromPath($path);
                $this->fail("{$path} was fetched");
            } catch (AssetFetchingException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_layout_prints(): void
    {
        $template = new DocumentTemplate([
            'name'             => 'طلب شراء',
            'reference_prefix' => 'PR',
            'layout_key'       => 'purchase_request',
            'show_letterhead'  => true,
            'fields'           => [['key' => 'vendor_name', 'label' => 'اسم المورد', 'type' => 'text', 'required' => true]],
        ]);

        [, $pages] = $this->print($template, ['vendor_name' => 'شركة النور']);

        $this->assertSame(1, $pages);
    }
}
