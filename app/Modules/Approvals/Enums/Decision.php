<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Enums;

/**
 * What an approver does with their step.
 */
enum Decision
{
    case Approve;
    case Reject;

    public function stepStatus(): StepStatus
    {
        return match ($this) {
            self::Approve => StepStatus::Approved,
            self::Reject  => StepStatus::Rejected,
        };
    }
}
