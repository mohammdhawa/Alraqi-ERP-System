<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Enums;

/**
 * What one attendance row records. Legacy stored the strings `check_in` /
 * `check_out`; the ERP stores ints, house style, with `type_label` beside them.
 */
enum AttendanceEventType: int
{
    case CheckIn  = 1;   // حضور
    case CheckOut = 2;   // انصراف

    public function label(): string
    {
        return match ($this) {
            self::CheckIn  => 'حضور',
            self::CheckOut => 'انصراف',
        };
    }

    /**
     * @return array<int, int>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
