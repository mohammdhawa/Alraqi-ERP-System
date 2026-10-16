<?php

declare(strict_types=1);

namespace App\Modules\HR\Import;

/**
 * Reads one sheet of a workbook as records keyed by header — the shape
 * EmployeeImporter::run() takes. An interface so the importer's command can
 * be tested without a file, and so the library behind it is one class.
 */
interface WorkbookReader
{
    /**
     * The data rows below $headerRow, as sheet row number => header => value.
     *
     * - Headers are trimmed; a column with no header (the workbook's empty
     *   column A) is not read.
     * - With $columns, only those headers are read, and every one of them
     *   must be in the sheet: a renamed column fails the read rather than
     *   arriving as blank on every row.
     * - A blank cell is null; a date is a DateTimeImmutable; a formula is the
     *   value Excel last computed for it; an Excel error (#N/A), typed or
     *   computed, is a CellError, so the mapper reports it instead of
     *   reading a blank.
     * - A row with nothing in any column read is not returned.
     *
     * @param  array<int, string>|null  $columns
     * @return array<int, array<string, mixed>>
     *
     * @throws WorkbookException the file, the sheet, the header row or a column is not there
     */
    public function rows(string $path, string $sheet, int $headerRow, ?array $columns = null): array;
}
