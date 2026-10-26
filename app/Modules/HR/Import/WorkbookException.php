<?php

declare(strict_types=1);

namespace App\Modules\HR\Import;

use RuntimeException;

/**
 * A workbook the importer cannot read as asked: missing file, sheet, header
 * row or column. Raised before any row is imported, so nothing was written.
 *
 * The message is Arabic and names no path (C12, C20): the upload screen shows
 * it as it is, and the path there is a stored file's, meaningless to HR. Whoever
 * read the file says which one it was — the command prefixes its path, the
 * upload service «ملف الموارد».
 */
final class WorkbookException extends RuntimeException {}
