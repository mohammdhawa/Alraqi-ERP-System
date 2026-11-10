<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Scheduling\Contracts\BlockingLeave;

/**
 * Scheduling's BlockingLeave, from leave requests and excuses: pending or
 * approved over any of the days — the same collision a new leave meets.
 */
class BlockingLeaveFromRequests implements BlockingLeave
{
    public function blocksAny(int $employeeId, string $from, string $to): bool
    {
        return LeaveRequest::query()->where('employee_id', $employeeId)->blocking()->overlapping($from, $to)->exists();
    }
}
