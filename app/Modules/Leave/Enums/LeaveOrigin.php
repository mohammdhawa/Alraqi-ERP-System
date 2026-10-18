<?php

declare(strict_types=1);

namespace App\Modules\Leave\Enums;

/**
 * Who put the leave on file — legacy's `is_excuse`, as a value (mapping §5).
 *
 * - `Request` — the employee filed it; it goes through the approval chain.
 * - `HrExcuse` — HR recorded it to excuse an absence; approved at once, no
 *   chain (Q5).
 */
enum LeaveOrigin: int
{
    case Request  = 1;
    case HrExcuse = 2;

    public function label(): string
    {
        return match ($this) {
            self::Request  => 'طلب موظف',
            self::HrExcuse => 'عذر من الموارد البشرية',
        };
    }
}
