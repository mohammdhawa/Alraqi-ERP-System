<?php

declare(strict_types=1);

namespace App\Modules\Leave\Enums;

/**
 * Where a leave request stands. For an employee's request it mirrors its
 * approval's outcome, written by the engine's completion hook; an HR excuse is
 * approved when filed. Return to work and an excuse's undo write `Cancelled`
 * on a decided row (LEAVE_BUILD.md §6).
 */
enum LeaveStatus: int
{
    case Pending   = 1;
    case Approved  = 2;
    case Rejected  = 3;
    case Cancelled = 4;

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'قيد الاعتماد',
            self::Approved  => 'معتمدة',
            self::Rejected  => 'مرفوضة',
            self::Cancelled => 'ملغاة',
        };
    }
}
