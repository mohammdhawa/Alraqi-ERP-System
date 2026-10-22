<?php

declare(strict_types=1);

namespace App\Modules\HR\Import;

/**
 * One workbook row, mapped: what the importer writes, and what it reports.
 *
 * `attributes` are `employees` columns only. The unit and the current job
 * title are handed on as text, because resolving them needs the database
 * (UnitResolver, the job-title catalogue); the mapper stays pure.
 *
 * A key that is null means the row cannot be imported this run — an `error`
 * issue says why — because without it a re-run could not find the person again.
 */
final readonly class MappedRow
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array{0: ?string, 1: ?string, 2: ?string}  $unit  division, section, office
     * @param  array{legacy_workplace: ?string, legacy_manager_name: ?string, legacy_job_title: ?string}  $openingPosting
     * @param  array<int, array{severity: string, field: ?string, message: string}>  $issues
     */
    public function __construct(
        public ?string $sourceKey,
        public string $displayName,
        public array $attributes,
        public array $unit,
        public ?string $currentTitle,
        public array $openingPosting,
        public array $issues,
    ) {}

    public function hasErrors(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue['severity'] === 'error') {
                return true;
            }
        }

        return false;
    }
}
