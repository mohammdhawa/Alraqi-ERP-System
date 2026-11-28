<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

use Carbon\CarbonImmutable;
use LogicException;

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
     * The creator's values for a page not yet numbered: no صادر number and no
     * reference, dated now until `numbered()` dates it at print.
     */
    public static function unnumbered(string $creatorName, string $creatorUnit, ?int $importNumber = null): self
    {
        return new self(
            exportNumber: null,
            importNumber: $importNumber,
            reference: null,
            date: CarbonImmutable::now(),
            creatorName: $creatorName,
            creatorUnit: $creatorUnit,
        );
    }

    /**
     * These values as printed on their page, numbered once: the صادر number
     * and the reference claimed for it, and the moment it is printed.
     *
     * @throws LogicException when the values carry numbers already
     */
    public function numbered(int $exportNumber, string $reference, CarbonImmutable $printedAt): self
    {
        if ($this->exportNumber !== null || $this->reference !== null) {
            throw new LogicException('A page is numbered once; these values carry numbers already.');
        }

        return new self(
            exportNumber: $exportNumber,
            importNumber: $this->importNumber,
            reference: $reference,
            date: $printedAt,
            creatorName: $this->creatorName,
            creatorUnit: $this->creatorUnit,
        );
    }

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
