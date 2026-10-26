<?php

declare(strict_types=1);

namespace App\Modules\HR\Import;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\ErrorCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Exception\IOException;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Reader\XLSX\RowIterator;
use OpenSpout\Reader\XLSX\Sheet;
use Throwable;

/**
 * WorkbookReader on openspout (xlsx only — every file HR sends is one).
 *
 * Streams the sheet rather than loading the workbook: Program_HR_10_2026 has
 * eighteen sheets, and only one of them is read.
 */
final class OpenSpoutWorkbookReader implements WorkbookReader
{
    public function rows(string $path, string $sheet, int $headerRow, ?array $columns = null): array
    {
        if (! is_file($path)) {
            throw new WorkbookException('تعذّر العثور على الملف.');
        }

        $options = new Options;
        // So the row iterator's key is the SHEET row number — the one HR sees
        // in Excel and the report quotes. Without it the key counts only the
        // non-empty rows read so far.
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;

        $reader = new Reader($options);
        try {
            $reader->open($path);
        } catch (IOException $e) {
            throw new WorkbookException('تعذّر فتح الملف — تأكد أنه ملف Excel ‏(xlsx) سليم.', previous: $e);
        }

        try {
            $names = [];
            foreach ($reader->getSheetIterator() as $candidate) {
                if (trim($candidate->getName()) === trim($sheet)) {
                    return $this->read($candidate, $headerRow, $columns);
                }
                $names[] = $candidate->getName();
            }
        } finally {
            $reader->close();
        }

        throw new WorkbookException("لا توجد ورقة باسم «{$sheet}». الأوراق الموجودة: ".implode('، ', $names));
    }

    /**
     * @param  array<int, string>|null  $columns
     * @return array<int, array<string, mixed>>
     */
    private function read(Sheet $sheet, int $headerRow, ?array $columns): array
    {
        $headers = null;
        $rows = [];
        $iterator = $sheet->getRowIterator();

        try {
            foreach ($iterator as $number => $row) {
                if ($number < $headerRow) {
                    continue;
                }

                $values = array_map($this->value(...), $row->getCells());

                if ($number === $headerRow) {
                    $headers = $this->headers($values, $columns, $sheet->getName(), $headerRow);

                    continue;
                }

                $record = [];
                foreach ($headers as $index => $header) {
                    $record[$header] = $values[$index] ?? null;
                }

                if (array_filter($record, static fn (mixed $value): bool => $value !== null) !== []) {
                    $rows[$number] = $record;
                }
            }
        } catch (Throwable $e) {
            $this->release($iterator);

            if ($e instanceof WorkbookException) {
                throw $e;
            }

            // A sheet openspout cannot parse — a corrupt file, not a missing
            // column: the reader's own Arabic refusal, not a server error.
            report($e);

            throw new WorkbookException('تعذّر قراءة الملف — قد يكون تالفاً؛ افتحه في Excel واحفظه ثم ارفعه مرة أخرى.');
        }

        if ($headers === null) {
            throw new WorkbookException("الورقة «{$sheet->getName()}» لا تحتوي على السطر {$headerRow} الذي تُقرأ منه العناوين.");
        }

        return $rows;
    }

    /**
     * Close a sheet left part-read. openspout closes it only once it is read
     * to the end, and an open sheet keeps the file locked on Windows — the
     * upload's cleanup then cannot delete it, and nothing ever would
     * (2026-10-23). Read to the end where the sheet allows it; where it
     * cannot be (it failed to parse), close its XML reader directly.
     */
    private function release(RowIterator $iterator): void
    {
        try {
            while ($iterator->valid()) {
                $iterator->next();
            }

            return;
        } catch (Throwable) {
        }

        try {
            (fn () => $this->xmlReader->close())->call($iterator);
        } catch (Throwable) {
        }
    }

    /**
     * Column index => header, for the columns to read.
     *
     * @param  array<int, mixed>  $values
     * @param  array<int, string>|null  $columns
     * @return array<int, string>
     */
    private function headers(array $values, ?array $columns, string $sheet, int $headerRow): array
    {
        $headers = [];
        foreach ($values as $index => $value) {
            $header = is_scalar($value) ? trim((string) $value) : '';
            if ($header === '' || ($columns !== null && ! in_array($header, $columns, true))) {
                continue;
            }
            if (in_array($header, $headers, true)) {
                throw new WorkbookException("العنوان «{$header}» مكرر في السطر {$headerRow} من الورقة «{$sheet}» — لا يمكن تحديد أيّهما يُقرأ.");
            }
            $headers[$index] = $header;
        }

        $missing = $columns === null ? [] : array_values(array_diff($columns, $headers));
        if ($missing !== []) {
            throw new WorkbookException("السطر {$headerRow} من الورقة «{$sheet}» ينقصه: ".implode('، ', $missing));
        }

        return $headers;
    }

    private function value(Cell $cell): mixed
    {
        if ($cell instanceof ErrorCell) {
            return new CellError($cell->getRawValue());
        }

        if ($cell instanceof FormulaCell) {
            // openspout keeps the result Excel saved beside the formula text,
            // and null when that result was an error — which is not a blank.
            $value = $cell->getComputedValue();
            if ($value === null) {
                return new CellError('');
            }
        } else {
            $value = $cell->getValue();
        }

        return is_string($value) && trim($value) === '' ? null : $value;
    }
}
