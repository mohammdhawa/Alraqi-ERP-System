<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Support;

use Carbon\CarbonImmutable;

/**
 * A person's shift on a date (work shifts spec §2): the date it belongs to
 * (its start date), its start and end as full moments in the attendance
 * timezone, and where it came from — `change` or `swap` (a change on the
 * date, work shifts piece 2), `assignment` or `default`. `shiftId` is the
 * shift template's id.
 */
final readonly class ExpectedShift
{
    public function __construct(
        public string $date,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public string $code,
        public string $name,
        public string $source,
        public ?int $shiftId = null,
    ) {}

    /** @return array{code: string, name: string, starts_at: string, ends_at: string} */
    public function toArray(): array
    {
        return [
            'code'      => $this->code,
            'name'      => $this->name,
            'starts_at' => $this->startsAt->toIso8601String(),
            'ends_at'   => $this->endsAt->toIso8601String(),
        ];
    }
}
