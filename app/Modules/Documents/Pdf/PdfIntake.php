<?php

declare(strict_types=1);

namespace App\Modules\Documents\Pdf;

use App\Modules\Documents\Enums\IntakeOutcome;
use App\Modules\Documents\Exceptions\UnreadablePdfException;
use App\Modules\Documents\Exceptions\UnsupportedUploadException;
use App\Modules\Documents\Pdf\Repair\PdfRepairer;
use App\Modules\Documents\Support\CompleteImage;
use Illuminate\Http\UploadedFile;
use Mpdf\Output\Destination;
use Throwable;

/**
 * The upload check (documents design §11.1, §11.5). Every file a document is
 * made from passes here first, so no signature can later fail on its file:
 *
 * - a PDF the printing opens is kept byte for byte;
 * - one it cannot open (FPDI's free parser refuses compressed
 *   cross-reference streams, which Word and scanners write) is rewritten by
 *   the server's tool and kept beside the rewrite, or refused when the
 *   rewrite does not open either;
 * - a JPG or PNG becomes a one-page A4 PDF (I.12);
 * - anything else is refused (I.12: nothing can be printed on a Word file).
 *
 * The type is read from the file's content (fileinfo), not its name.
 */
final class PdfIntake
{
    /** The largest upload piece 2's request takes (design §18 call 14). */
    public const MAX_KB = 20480;

    private const MARGIN_MM = 10;

    private const A4_MM = [210, 297];

    public function __construct(private readonly PdfRepairer $repairer) {}

    public function accept(UploadedFile $file, PdfSession $session): AcceptedPdf
    {
        return match ((string) $file->getMimeType()) {
            'application/pdf' => $this->acceptPdf($file, $session),
            'image/jpeg'      => $this->acceptPhoto($file, $session, 'jpg'),
            'image/png'       => $this->acceptPhoto($file, $session, 'png'),
            default           => throw new UnsupportedUploadException,
        };
    }

    /**
     * How many pages the printing can use in this file, or null when it
     * cannot (or there are none).
     *
     * Counting is not opening (final review, 2026-11-22): FPDI's count reads
     * only the page tree's /Count, so every page is imported and the whole
     * copy written, as a print will. ANY failure is "cannot": a broken file
     * throws TypeErrors and warnings from inside FPDI as well as its own
     * exceptions, and every one of them is a refusal, never a 500.
     */
    public function pageCount(string $path, PdfSession $session): ?int
    {
        try {
            $mpdf = $session->mpdf();
            $pages = $mpdf->setSourceFile($path);

            if ($pages < 1) {
                return null;
            }

            for ($page = 1; $page <= $pages; $page++) {
                $template = $mpdf->importPage($page);
                $size = $mpdf->getTemplateSize($template);
                $mpdf->AddPageByArray(['orientation' => $size['orientation'], 'sheet-size' => [$size['width'], $size['height']]]);
                $mpdf->useTemplate($template);
            }

            $mpdf->Output('', Destination::STRING_RETURN);
        } catch (Throwable) {
            return null;
        }

        return $pages;
    }

    private function acceptPdf(UploadedFile $file, PdfSession $session): AcceptedPdf
    {
        $upload = $session->path('upload.pdf');
        copy((string) $file->getRealPath(), $upload);

        $pages = $this->pageCount($upload, $session);

        if ($pages !== null) {
            return new AcceptedPdf($upload, null, $pages, IntakeOutcome::AsUploaded);
        }

        $repaired = $session->path('repaired.pdf');

        if ($this->repairer->repair($upload, $repaired) && is_file($repaired)) {
            $pages = $this->pageCount($repaired, $session);

            if ($pages !== null) {
                return new AcceptedPdf($repaired, $upload, $pages, IntakeOutcome::Repaired);
            }
        }

        throw new UnreadablePdfException;
    }

    private function acceptPhoto(UploadedFile $file, PdfSession $session, string $extension): AcceptedPdf
    {
        $photo = $session->path('photo.'.$extension);
        copy((string) $file->getRealPath(), $photo);

        // A whole image, not only a valid header (final review, 2026-11-22).
        $type = CompleteImage::typeOf($photo, [$extension === 'png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG]);
        $size = $type === null ? false : @getimagesize($photo);

        if ($size === false) {
            throw new UnsupportedUploadException;
        }

        [$pageWidth, $pageHeight] = self::A4_MM;
        $boxWidth = $pageWidth - 2 * self::MARGIN_MM;
        $boxHeight = $pageHeight - 2 * self::MARGIN_MM;

        // Fit inside the margins, keeping the aspect ratio, centred.
        $scale = min($boxWidth / $size[0], $boxHeight / $size[1]);
        $width = $size[0] * $scale;
        $height = $size[1] * $scale;

        $printable = $session->path('printable.pdf');

        try {
            $mpdf = $session->mpdf([
                'margin_left'   => self::MARGIN_MM,
                'margin_right'  => self::MARGIN_MM,
                'margin_top'    => self::MARGIN_MM,
                'margin_bottom' => self::MARGIN_MM,
            ]);
            $mpdf->AddPage();
            $mpdf->Image($photo, ($pageWidth - $width) / 2, ($pageHeight - $height) / 2, $width, $height, '', '', true, false);
            $mpdf->Output($printable, Destination::FILE);
        } catch (Throwable) {
            // A whole file mPDF still cannot draw: refused, never a 500.
            throw new UnsupportedUploadException;
        }

        return new AcceptedPdf($printable, null, 1, IntakeOutcome::FromPhoto);
    }
}
