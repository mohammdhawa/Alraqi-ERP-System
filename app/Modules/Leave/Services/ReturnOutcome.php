<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Modules\Leave\Models\LeaveRequest;

/**
 * What a return to work did: the leave as it now stands, whether it was
 * cancelled outright (back on its first day), and how many balance days came
 * back — zero for a non-deducting type, which is why it is said rather than
 * assumed (legacy).
 */
final readonly class ReturnOutcome
{
    public function __construct(
        public LeaveRequest $leave,
        public bool $cancelled,
        public int $daysReturned,
    ) {}
}
