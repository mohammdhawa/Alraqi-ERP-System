<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Services;

use App\Modules\Approvals\Enums\RejectionPolicy;
use App\Modules\Approvals\Support\ApprovalRoute;
use App\Modules\Approvals\Support\StepDefinition;

/**
 * A duty exchange's chain (decision round S13): one step, the requester's
 * direct manager, who decides for both guards. A rejection ends it.
 */
final class ShiftSwapApprovalRoute
{
    public static function make(): ApprovalRoute
    {
        return ApprovalRoute::sequential(RejectionPolicy::Ends, StepDefinition::directManager());
    }
}
