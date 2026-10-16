<?php

declare(strict_types=1);

namespace App\Modules\HR\Import;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\ErrorCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Exception\IOException;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Reader\XLSX\Sheet;

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
            throw new WorkbookException("File not found: {$path}");
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
            throw new WorkbookException("Cannot open {$path}: {$e->getMessage()}", previous: $e);
        }

        try {
            $names = [];
            foreach ($reader->getSheetIterator() as $candidate) {
                if (trim($candidate->getName()) === trim($sheet)) {
                    return $this->read($candidate, $headerRow, $columns, $path);
                }
                $names[] = $candidate->getName();
            }
        } finally {
            $reader->close();
        }

        throw new WorkbookException("No sheet «{$sheet}» in {$path}. Sheets: ".implode('، ', $names));
    }

    /**
     * @param  array<int, string>|null  $columns
     * @return array<int, array<string, mixed>>
     */
    private function read(Sheet $sheet, int $headerRow, ?array $columns, string $path): array
    {
        $headers = null;
        $rows = [];

        foreach ($sheet->getRowIterator() as $number => $row) {
            if ($number < $headerRow) {
                continue;
            }

            $values = array_map($this->value(...), $row->getCells());

            if ($number === $headerRow) {
                $headers = $this->headers($values, $columns, $path, $sheet->getName(), $headerRow);

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

        if ($headers === null) {
            throw new WorkbookException("Sheet «{$sheet->getName()}» in {$path} has no row {$headerRow} to read headers from.");
        }

        return $rows;
    }

    /**
     * Column index => header, for the columns to read.
     *
     * @param  array<int, mixed>  $values
     * @param  array<int, string>|null  $columns
     * @return array<int, string>
     */
    private function headers(array $values, ?array $columns, string $path, string $sheet, int $headerRow): array
    {
        $headers = [];
        foreach ($values as $index => $value) {
            $header = is_scalar($value) ? trim((string) $value) : '';
            if ($header === '' || ($columns !== null && ! in_array($header, $columns, true))) {
                continue;
            }
            if (in_array($header, $headers, true)) {
                throw new WorkbookException("Header «{$header}» appears twice in row {$headerRow} of «{$sheet}» in {$path} — cannot tell which to read.");
            }
            $headers[$index] = $header;
        }

        $missing = $columns === null ? [] : array_values(array_diff($columns, $headers));
        if ($missing !== []) {
            throw new WorkbookException("Row {$headerRow} of «{$sheet}» in {$path} lacks the column(s): ".implode('، ', $missing));
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
