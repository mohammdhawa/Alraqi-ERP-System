<?php

declare(strict_types=1);

namespace Tests\Support;

use Mpdf\Mpdf;

/**
 * PDFs for the documents tests, made in memory so no binary fixture is
 * committed.
 */
final class Pdfs
{
    /**
     * A one-page PDF FPDI's free parser opens: what mPDF itself writes.
     */
    public static function plain(): string
    {
        $mpdf = new Mpdf(['tempDir' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'mpdf-tests']);
        $mpdf->WriteHTML('<p>نص</p>');

        return $mpdf->Output('', 'S');
    }

    /**
     * A one-page PDF 1.5 whose cross-reference is an uncompressed xref
     * stream. FPDI's free parser refuses it with CrossReferenceException
     * 0x010B ("probably uses a compression technique…"), as it refuses the
     * PDFs Word and scanners save (documents design §11.5). Checked against
     * mPDF 8.3.1 / FPDI 2.6.6 on 2026-11-22.
     */
    public static function withXrefStream(): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] >>',
        ];
        $pdf = "%PDF-1.5\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $offsets[4] = strlen($pdf);
        $rows = pack('CNn', 0, 0, 65535);

        foreach ([1, 2, 3, 4] as $number) {
            $rows .= pack('CNn', 1, $offsets[$number], 0);
        }

        $pdf .= "4 0 obj\n<< /Type /XRef /Size 5 /W [1 4 2] /Root 1 0 R /Length ".strlen($rows)." >>\nstream\n{$rows}\nendstream\nendobj\n";

        return $pdf."startxref\n{$offsets[4]}\n%%EOF\n";
    }

    /**
     * A classic-xref PDF whose page tree counts one page that is not there:
     * object 3 is free in the table. FPDI reads the count; importing the
     * page fails (final review, 2026-11-22).
     */
    public static function withMissingPage(): string
    {
        return self::classic([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        ], size: 4);
    }

    /**
     * A classic-xref PDF that claims one page with no kids at all. Importing
     * it throws a TypeError inside FPDI, not an FPDI exception.
     */
    public static function withEmptyKids(): string
    {
        return self::classic([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [] /Count 1 >>',
        ], size: 3);
    }

    /**
     * The first half of a real file: a truncated upload.
     */
    public static function truncated(string $bytes): string
    {
        return substr($bytes, 0, intdiv(strlen($bytes), 2));
    }

    /**
     * @param  array<int, string>  $objects  number => dictionary; numbers below $size not given are free
     */
    private static function classic(array $objects, int $size): string
    {
        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $table = "xref\n0 {$size}\n0000000000 65535 f \n";

        for ($number = 1; $number < $size; $number++) {
            $table .= isset($offsets[$number])
                ? sprintf("%010d 00000 n \n", $offsets[$number])
                : "0000000000 00000 f \n";
        }

        $start = strlen($pdf);

        return $pdf.$table."trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$start}\n%%EOF\n";
    }
}
