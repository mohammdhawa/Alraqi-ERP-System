<?php

declare(strict_types=1);

namespace App\Modules\Documents\Pdf;

use App\Modules\Documents\Enums\LetterheadPart;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Documents\Services\Letterhead;
use App\Modules\Documents\Support\RecipientValues;
use App\Modules\Documents\Support\SystemValues;
use Illuminate\Support\Facades\Storage;
use Mpdf\AssetFetcher;
use Mpdf\Container\SimpleContainer;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use RuntimeException;

/**
 * Prints a template to a PDF in the company's letterhead (documents design
 * §11.2): A4, right to left, the letterhead's header and footer images on
 * every page when `show_letterhead` is on and that image is uploaded.
 *
 * mPDF fetches nothing (ruling R8): its fetcher refuses every file and URL,
 * and the letterhead reaches it as `imageVars`. The stylesheet is written on
 * its own; the body is written as body HTML only, with every `@` as `&#64;`,
 * so neither the page nor a value can make mPDF read an `@import` (inert()).
 *
 * Not final: a test replaces it with one that fails, or one that records.
 */
class TemplatePrinter
{
    /**
     * Where the cards stop above a footer image, in mm (Global Constraints,
     * "Cards above the footer"; ruling R14): the footer's box reaches 24 mm
     * up the page (6 mm from the foot, up to 18 mm high), and the printed
     * copy's «رقم المستند» line, 2 mm under the cards, must clear it.
     */
    public const CARD_FLOOR = 32;

    private const SIDE_MARGIN = 18;

    private const TOP_MARGIN = 20;

    private const TOP_MARGIN_WITH_HEADER = 45;

    private const HEADER_FROM_TOP = 8;

    private const BOTTOM_MARGIN = 20;

    private const BOTTOM_MARGIN_WITH_FOOTER = 30;

    private const FOOTER_FROM_BOTTOM = 6;

    /** The largest box each image is fitted into, width and height in mm. */
    private const HEADER_BOX = [174.0, 30.0];

    private const FOOTER_BOX = [174.0, 18.0];

    public function __construct(
        private readonly Letterhead $letterhead,
        private readonly TemplateHtml $html,
    ) {}

    /**
     * @param  array<string, mixed>  $fieldValues  keyed by field key, as FieldValues::check() returns them
     */
    public function print(DocumentTemplate $template, array $fieldValues, SystemValues $system, ?RecipientValues $recipient, PdfSession $session): RenderedTemplate
    {
        $header = $template->show_letterhead ? $this->image(LetterheadPart::Header) : null;
        $footer = $template->show_letterhead ? $this->image(LetterheadPart::Footer) : null;

        $mpdf = $session->mpdf([
            // Every script in DejaVu Sans (ruling R9): mPDF's automatic
            // Arabic font loses letters when the page is copied or searched.
            'autoLangToFont'       => false,
            // mPDF's default, stated: an annotation never embeds a file.
            'allowAnnotationFiles' => false,
            'default_font_size'    => 11,
            'margin_left'       => self::SIDE_MARGIN,
            'margin_right'      => self::SIDE_MARGIN,
            'margin_top'        => $header === null ? self::TOP_MARGIN : self::TOP_MARGIN_WITH_HEADER,
            'margin_bottom'     => $footer === null ? self::BOTTOM_MARGIN : self::BOTTOM_MARGIN_WITH_FOOTER,
            'margin_header'     => self::HEADER_FROM_TOP,
            'margin_footer'     => self::FOOTER_FROM_BOTTOM,
        ], new SimpleContainer(['assetFetcher' => $this->fetcher()]));
        $mpdf->SetDirectionality('rtl');

        // Before the first page, so the letterhead frames it too.
        if ($header !== null) {
            $mpdf->SetHTMLHeader($this->imageHtml($mpdf, 'letterhead_header', $header, self::HEADER_BOX));
        }

        if ($footer !== null) {
            $mpdf->SetHTMLFooter($this->imageHtml($mpdf, 'letterhead_footer', $footer, self::FOOTER_BOX));
        }

        $mpdf->WriteHTML(TemplateHtml::STYLESHEET, HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML($this->inert($this->html->render($template, $fieldValues, $system, $recipient)), HTMLParserMode::HTML_BODY);

        // Where the body ends on its last page, in mm from that page's top
        // (ruling R17); rounded as it is stored, so every database keeps the
        // same value and every rebuild places the cards alike.
        $contentBottom = round((float) $mpdf->y, 2);

        $path = $session->path('template.pdf');
        $mpdf->Output($path, Destination::FILE);

        return new RenderedTemplate($path, $footer === null ? null : self::CARD_FLOOR, $contentBottom);
    }

    /** mPDF's fetcher for this print: one that refuses everything. */
    protected function fetcher(): AssetFetcher
    {
        return new RefusingAssetFetcher;
    }

    /**
     * The body with nothing in its text that mPDF would act on:
     * - mPDF looks for `@import` anywhere in the HTML it is given, and a
     *   value or the DOM's output can carry a bare `@`. As `&#64;` it is not
     *   seen, and mPDF still prints it as `@`. The body carries no CSS of its
     *   own, so no `@` in it is ever meant for mPDF;
     * - mPDF replaces `{PAGENO}`, `{nb}` and `{nbpg}` anywhere in a page's
     *   text with page numbers. A word joiner (U+2060, invisible, which mPDF
     *   keeps) right after every brace breaks the alias, so a value typed
     *   with one prints as typed. Every brace, not only the tokens: mPDF
     *   drops a soft hyphen or a BOM from the text after this has run, and a
     *   token split by one would otherwise be whole again. The body's markup
     *   has no brace of its own.
     */
    private function inert(string $html): string
    {
        return str_replace(['@', '{'], ['&#64;', "{\u{2060}"], $html);
    }

    /**
     * The image's bytes, or null when none is uploaded.
     *
     * @throws RuntimeException when the image is recorded but cannot be read:
     *                          an official page is not printed without the letterhead it should carry
     */
    private function image(LetterheadPart $part): ?string
    {
        $path = $this->letterhead->pathOf($part);

        if ($path === null) {
            return null;
        }

        $bytes = Storage::disk(Letterhead::DISK)->get($path);

        if (! is_string($bytes) || $bytes === '') {
            throw new RuntimeException("The letterhead's {$part->value} image could not be read.");
        }

        return $bytes;
    }

    /**
     * The image, centred and fitted into its box with its proportions kept,
     * handed to mPDF in memory.
     *
     * @param  array{float, float}  $box
     */
    private function imageHtml(Mpdf $mpdf, string $key, string $bytes, array $box): string
    {
        $size = getimagesizefromstring($bytes);

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw new RuntimeException("The letterhead image {$key} is not an image.");
        }

        $ratio = $size[0] / $size[1];
        $width = min($box[0], $box[1] * $ratio);
        $height = $width / $ratio;

        $mpdf->imageVars[$key] = $bytes;

        return sprintf(
            '<div style="text-align: center;"><img src="var:%s" style="width: %.2Fmm; height: %.2Fmm;"></div>',
            $key,
            $width,
            $height,
        );
    }
}
