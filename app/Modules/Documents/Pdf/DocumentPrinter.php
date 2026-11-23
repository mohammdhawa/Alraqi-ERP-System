<?php

declare(strict_types=1);

namespace App\Modules\Documents\Pdf;

use App\Modules\Documents\Enums\MarkKind;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentMark;
use App\Modules\Documents\Services\Signatures;
use App\Modules\Documents\Services\UnitStamps;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use RuntimeException;

/**
 * Builds a document's printed copy (documents design §11.4): every page of
 * the original, then the marks as cards on the last page (a new page of the
 * same size when they do not fit), and under them the document's number.
 *
 * Always writes a new file and returns its path (a write the disk refuses
 * throws, so no path to nothing is returned); it never touches
 * `printed_path` and never deletes a file, so a failed rebuild leaves the
 * current copy as it was. Every image is the version its own mark names,
 * decrypted in memory and handed to mPDF as a variable; mPDF's temp
 * directory is the print's own folder (PdfSession), deleted when it ends.
 */
class DocumentPrinter
{
    private const NOTE_LIMIT = 120;

    private const TIMEZONE = 'Asia/Damascus';

    private const NORMAL = ['fill' => '#FFFFFF', 'border' => '#969696', 'note' => '#3C3C3C'];

    private const REJECTION = ['fill' => '#FFEBEB', 'border' => '#C85050', 'note' => '#B42828'];

    private const DECIDER = ['fill' => '#F5FAFF', 'border' => '#3C64B4', 'note' => '#3C3C3C'];

    public function __construct(
        private readonly UnitStamps $stamps,
        private readonly Signatures $signatures,
        private readonly PdfWorkspace $workspace,
        private readonly CardLayout $layout,
    ) {}

    public function print(Document $document): string
    {
        // An employee's signature mark (piece 4) is not drawn here: this
        // printer prints approval and rejection cards only.
        $marks = $document->marks()
            ->with(['stamp', 'signature'])
            ->whereIn('kind', [MarkKind::ApprovalCard->value, MarkKind::RejectionCard->value])
            ->get();

        $target = "documents/{$document->folder}/printed/".Str::uuid().'.pdf';
        $disk = Storage::disk(PdfWorkspace::DISK);

        $this->workspace->run(function (PdfSession $session) use ($document, $marks, $target, $disk): void {
            $mpdf = $session->mpdf();
            $mpdf->SetDirectionality('rtl');
            // The cards sit in the bottom margin by design (6 mm from the edge).
            $mpdf->SetAutoPageBreak(false);

            [$width, $height] = $this->importOriginal($mpdf, $disk->path((string) $document->original_path));

            $placement = $this->layout->place($marks->count(), $width, $height);

            if ($placement->newPage) {
                $mpdf->AddPageByArray(['orientation' => $width > $height ? 'L' : 'P', 'sheet-size' => [$width, $height]]);
            }

            foreach ($marks->values() as $index => $mark) {
                $position = $placement->positions[$index];
                $mpdf->WriteFixedPosHTML(
                    $this->cardHtml($mpdf, $mark),
                    $position['x'],
                    $position['y'],
                    CardLayout::CARD_WIDTH,
                    CardLayout::CARD_HEIGHT,
                    'auto',
                );
            }

            $mpdf->WriteFixedPosHTML(
                '<div dir="rtl" style="text-align: center; font-size: 7pt; color: #505050;">'.e('رقم المستند: '.$document->id).'</div>',
                0,
                $placement->blockBottom + 2,
                $width,
                5,
                'auto',
            );

            $file = $session->path('printed.pdf');
            $mpdf->Output($file, Destination::FILE);

            // The disk answers false to a write it could not make: a path to
            // nothing must never be returned as the new copy.
            $bytes = file_get_contents($file);

            if ($bytes === false || $disk->put($target, $bytes) === false) {
                throw new RuntimeException('The printed copy could not be written.');
            }
        });

        return $target;
    }

    /**
     * Every page of the original at its own size.
     *
     * @return array{float, float} the last page's width and height in mm
     */
    private function importOriginal(Mpdf $mpdf, string $path): array
    {
        $pages = $mpdf->setSourceFile($path);
        $size = ['width' => 210.0, 'height' => 297.0];

        for ($page = 1; $page <= $pages; $page++) {
            $template = $mpdf->importPage($page);
            $size = $mpdf->getTemplateSize($template);
            $mpdf->AddPageByArray(['orientation' => $size['orientation'], 'sheet-size' => [$size['width'], $size['height']]]);
            $mpdf->useTemplate($template);
        }

        return [(float) $size['width'], (float) $size['height']];
    }

    private function cardHtml(Mpdf $mpdf, DocumentMark $mark): string
    {
        $colours = match (true) {
            $mark->kind === MarkKind::RejectionCard => self::REJECTION,
            $mark->decisive                         => self::DECIDER,
            default                                 => self::NORMAL,
        };

        $stampKey = null;
        $signatureKey = null;

        if ($mark->stamp !== null) {
            $stampKey = 'stamp'.$mark->id;
            $mpdf->imageVars[$stampKey] = $this->stamps->bytes($mark->stamp);
        }

        if ($mark->signature !== null) {
            $signatureKey = 'signature'.$mark->id;
            $mpdf->imageVars[$signatureKey] = $this->signatures->bytes($mark->signature);
        }

        return view('documents.card', [
            'fill'         => $colours['fill'],
            'border'       => $colours['border'],
            'noteColour'   => $colours['note'],
            'name'         => $mark->employee_name,
            'unit'         => $mark->unit_name ?? '-',
            'date'         => $mark->marked_at->copy()->timezone(self::TIMEZONE)->format('Y-m-d H:i'),
            'note'         => $mark->note === null ? null : Str::limit($mark->note, self::NOTE_LIMIT),
            'stampKey'     => $stampKey,
            'signatureKey' => $signatureKey,
        ])->render();
    }
}
