<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Support;

use Carbon\CarbonImmutable;

/**
 * A person's shift on a date (work shifts spec §2): the date it belongs to
 * (its start date), its start and end as full moments in the attendance
 * timezone, and where it came from — `assignment`, `default`, or `open`.
 *
 * THE OPEN DAY (spec §4.5, build steps 3–4 only) is today's rules expressed
 * as a shift: the whole date, check-in open all day, closed at 23:59:59, no
 * late or early departure. `isOpen` marks it; step 5 deletes it.
 */
final readonly class ExpectedShift
{
    public function __construct(
        public string $date,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public string $code,
        public string $name,
        public bool $isOpen,
        public string $source,
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
