<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Modules\Approvals\Enums\RejectionPolicy;
use App\Modules\Approvals\Support\ApprovalRoute;
use App\Modules\Approvals\Support\StepDefinition;

/**
 * Leave's chain (Q1 = B): the direct manager, then the manager of the HR unit
 * — مدير الموارد — then done. Any rejection ends it, legacy's rule.
 *
 * The HR step is a unit CODE (`config('leave.hr_unit_code')`), so a new HR
 * manager needs no deploy. The engine applies the same-approver and requester
 * skips (Q2) and refuses a chain that resolves to nobody (§9 Q2, Q8).
 */
final class LeaveApprovalRoute
{
    public static function make(): ApprovalRoute
    {
        return ApprovalRoute::sequential(
            RejectionPolicy::Ends,
            StepDefinition::directManager(),
            StepDefinition::unitManager((string) config('leave.hr_unit_code', 'HR')),
        );
    }
}
