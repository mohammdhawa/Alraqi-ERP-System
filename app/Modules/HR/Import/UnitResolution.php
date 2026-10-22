<?php

declare(strict_types=1);

namespace App\Modules\HR\Import;

/**
 * Where a workbook row lands in the org tree, and what the importer had to say
 * about getting it there.
 *
 * `departmentId` null = the row could not be placed at all (an `error` issue
 * says why). Issues use the import report's three severities: `info` for an
 * expected rule doing its job, `warning` for a fallback HR should look at,
 * `error` for a row that needs HR before it can land.
 */
final readonly class UnitResolution
{
    /**
     * @param  array<int, array{severity: string, message: string}>  $issues
     */
    public function __construct(
        public ?int $departmentId,
        public array $issues = [],
    ) {}
}
