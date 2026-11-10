<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Enums;

/**
 * Who set a person's shift on a date (work shifts piece 2, spec §3.1): HR,
 * or an approved duty exchange.
 */
enum ShiftChangeOrigin: int
{
    case Hr   = 1;
    case Swap = 2;

    public function label(): string
    {
        return match ($this) {
            self::Hr   => 'تعديل من الموارد البشرية',
            self::Swap => 'تبديل مناوبة',
        };
    }

    /** The resolver's `source` for a shift this change gives. */
    public function source(): string
    {
        return match ($this) {
            self::Hr   => 'change',
            self::Swap => 'swap',
        };
    }
}
