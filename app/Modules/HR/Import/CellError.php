<?php

declare(strict_types=1);

namespace App\Modules\HR\Import;

/**
 * A cell holding an Excel error (#N/A, #REF!) — typed in, or the result of
 * its formula. Distinct from a blank cell on purpose: the importer reports
 * it rather than reading it as empty.
 */
final readonly class CellError
{
    /** @param  string  $code  Excel's error text; '' for a formula's error, whose text openspout does not keep */
    public function __construct(
        public string $code,
    ) {}
}
