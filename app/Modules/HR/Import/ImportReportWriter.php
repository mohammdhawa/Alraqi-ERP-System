<?php

declare(strict_types=1);

namespace App\Modules\HR\Import;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Writes an ImportReport as the two files HR gets (work order §4.4):
 *
 * - the REPORT — one row per source row: its row number, the name, what
 *   happened, the employee number and every issue — for review, and safe to
 *   pass around. No password is ever in it.
 * - the CREDENTIALS — employee number, name, temporary password — the one
 *   place a temporary password is written. Only after a real run.
 *
 * Both are Arabic and right-to-left, for the people who read them.
 */
final class ImportReportWriter
{
    private const OUTCOMES = [
        'created'  => 'جديد',
        'updated'  => 'تحديث',
        'restored' => 'استعادة',
        'skipped'  => 'لم يُستورد',
        'failed'   => 'مرفوض',
    ];

    private const SEVERITIES = [
        'error'   => 'خطأ',
        'warning' => 'تنبيه',
        'info'    => 'للعلم',
    ];

    /**
     * @param  array<string, string>  $source  label => value, shown on the summary sheet (file names, date)
     */
    public function writeReport(ImportReport $report, string $path, array $source): void
    {
        $writer = $this->open($path);

        $summary = $writer->getCurrentSheet();
        $summary->setName('الملخص');
        $summary->setSheetView((new SheetView)->setRightToLeft(true));
        $summary->setColumnWidth(28, 1);
        $summary->setColumnWidth(70, 2);

        $bold = (new Style)->setFontBold();
        $writer->addRow(Row::fromValues([
            $report->dryRun ? 'تجربة — لم يُحفظ شيء، وهذا ما كان سيحدث' : 'استيراد — حُفظ ما في هذا التقرير',
        ], $bold));
        $writer->addRow(Row::fromValues([]));
        foreach ($source as $label => $value) {
            $writer->addRow(Row::fromValues([$label, $value]));
        }
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['النتيجة', 'عدد السطور'], $bold));
        foreach (self::OUTCOMES as $outcome => $label) {
            $writer->addRow(Row::fromValues([$label, $report->count($outcome)]));
        }
        $writer->addRow(Row::fromValues(['المجموع', count($report->rows())], $bold));
        $writer->addRow(Row::fromValues([]));
        foreach (self::SEVERITIES as $severity => $label) {
            $writer->addRow(Row::fromValues(["عدد ملاحظات «{$label}»", $report->issueCount($severity)]));
        }

        $rows = $writer->addNewSheetAndMakeItCurrent();
        $rows->setName('السطور');
        $rows->setSheetView((new SheetView)->setRightToLeft(true)->setFreezeRow(2));
        $rows->setColumnWidth(8, 1);
        $rows->setColumnWidth(28, 2);
        $rows->setColumnWidth(12, 3);
        $rows->setColumnWidth(14, 4, 5);
        $rows->setColumnWidth(110, 6);

        $writer->addRow(Row::fromValues(['السطر', 'الاسم', 'النتيجة', 'الرقم الوظيفي', 'رقم كوبو', 'الملاحظات'], $bold));

        $top = (new Style)->setCellVerticalAlignment(CellVerticalAlignment::TOP)->setShouldWrapText();
        foreach ($report->rows() as $row) {
            $writer->addRow(Row::fromValues([
                $row['row'],
                $row['name'],
                self::OUTCOMES[$row['outcome']] ?? $row['outcome'],
                $this->employeeNumber($report, $row['outcome'], $row['employee_number']),
                $row['key'] === null ? '' : substr($row['key'], strlen('kobo:')),
                $this->issues($row['issues']),
            ], $top));
        }
        $rows->setAutoFilter(new AutoFilter(0, 1, 5, count($report->rows()) + 1));

        $writer->close();
    }

    /**
     * The temporary passwords of the accounts a real run created. Nothing
     * else goes in this file, and nothing in it goes anywhere else.
     */
    public function writeCredentials(ImportReport $report, string $path): void
    {
        $writer = $this->open($path);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName('كلمات المرور المؤقتة');
        $sheet->setSheetView((new SheetView)->setRightToLeft(true)->setFreezeRow(2));
        $sheet->setColumnWidth(16, 1);
        $sheet->setColumnWidth(30, 2);
        $sheet->setColumnWidth(22, 3);

        $writer->addRow(Row::fromValues(['الرقم الوظيفي', 'الاسم', 'كلمة المرور المؤقتة'], (new Style)->setFontBold()));
        foreach ($report->credentials() as $credential) {
            $writer->addRow(Row::fromValues([$credential['employee_number'], $credential['name'], $credential['password']]));
        }

        $writer->close();
    }

    private function open(string $path): Writer
    {
        $writer = new Writer;
        $writer->openToFile($path);

        return $writer;
    }

    /**
     * On a dry run a NEW employee's number was handed out inside a
     * transaction that was then rolled back; the real run may give another.
     * Showing it would invite HR to use it.
     */
    private function employeeNumber(ImportReport $report, string $outcome, ?string $number): string
    {
        return $report->dryRun && $outcome === 'created' ? '' : (string) $number;
    }

    /**
     * @param  array<int, array{severity: string, field: ?string, message: string}>  $issues
     */
    private function issues(array $issues): string
    {
        // Errors first, then warnings, then what is only for the record.
        $order = array_flip(array_keys(self::SEVERITIES));
        usort($issues, static fn (array $a, array $b): int => ($order[$a['severity']] ?? 9) <=> ($order[$b['severity']] ?? 9));

        return implode("\n", array_map(
            static fn (array $issue): string => (self::SEVERITIES[$issue['severity']] ?? $issue['severity']).': '.$issue['message'],
            $issues,
        ));
    }
}
