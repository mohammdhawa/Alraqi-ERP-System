<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Enums;

/**
 * Where an approval stands. The engine writes it; the subject mirrors it
 * through its completion hook and nowhere else (design §5).
 *
 * `Cancelled` is the requester's withdrawal (§9 Q1 = B) — legacy has none.
 */
enum ApprovalStatus: int
{
    case Pending   = 1;
    case Approved  = 2;
    case Rejected  = 3;
    case Cancelled = 4;

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'قيد الاعتماد',
            self::Approved  => 'موافق عليه',
            self::Rejected  => 'مرفوض',
            self::Cancelled => 'مسحوب',
        };
    }

    public function isDecided(): bool
    {
        return $this !== self::Pending;
    }
}
