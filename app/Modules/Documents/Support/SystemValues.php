<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

use Carbon\CarbonImmutable;

/**
 * The values the system gives a template's `system.*` chips (documents design
 * §8.2): the numbers, the creation day and who created the document.
 */
final readonly class SystemValues
{
    private const TIMEZONE = 'Asia/Damascus';

    public function __construct(
        public ?int $exportNumber,
        public ?int $importNumber,
        public ?string $reference,
        public CarbonImmutable $date,
        public string $creatorName,
        public string $creatorUnit,
    ) {}

    /**
     * Keyed by the `system.*` chip keys; the date as `Y/m/d` in Damascus, a
     * missing value as ''.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'system.export_number' => $this->exportNumber === null ? '' : (string) $this->exportNumber,
            'system.import_number' => $this->importNumber === null ? '' : (string) $this->importNumber,
            'system.reference'     => $this->reference ?? '',
            'system.date'          => $this->date->setTimezone(self::TIMEZONE)->format('Y/m/d'),
            'system.creator_name'  => $this->creatorName,
            'system.creator_unit'  => $this->creatorUnit,
        ];
    }
}
