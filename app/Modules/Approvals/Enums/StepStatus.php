<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Enums;

/**
 * One approver's step. `Skipped` always carries a SkipReason, so a step that
 * was never asked says why.
 */
enum StepStatus: int
{
    case Pending  = 1;
    case Approved = 2;
    case Rejected = 3;
    case Skipped  = 4;

    public function label(): string
    {
        return match ($this) {
            self::Pending  => 'بانتظار القرار',
            self::Approved => 'موافقة',
            self::Rejected => 'رفض',
            self::Skipped  => 'لم تُطلب',
        };
    }

    /**
     * Approved or rejected — a person decided it. A skip is not a decision,
     * which is what lets a request be withdrawn after a submission-time skip
     * (§9 Q1).
     */
    public function isDecision(): bool
    {
        return $this === self::Approved || $this === self::Rejected;
    }
}
