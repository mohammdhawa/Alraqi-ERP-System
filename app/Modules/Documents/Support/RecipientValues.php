<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

/**
 * The employee a document is sent to, for a template's `recipient.*` chips
 * (documents design §8.2; sending is piece 4).
 */
final readonly class RecipientValues
{
    public function __construct(
        public string $name,
        public string $jobTitle,
        public string $unit,
        public string $employeeNumber,
    ) {}

    /**
     * Keyed by the `recipient.*` chip keys.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'recipient.name'            => $this->name,
            'recipient.job_title'       => $this->jobTitle,
            'recipient.unit'            => $this->unit,
            'recipient.employee_number' => $this->employeeNumber,
        ];
    }
}
