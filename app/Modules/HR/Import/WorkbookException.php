<?php

declare(strict_types=1);

namespace App\Modules\HR\Import;

use RuntimeException;

/**
 * A workbook the importer cannot read as asked: missing file, sheet, header
 * row or column. Raised before any row is imported, so nothing was written.
 */
final class WorkbookException extends RuntimeException {}
