<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Enums;

/**
 * What a rejection does — the one rule legacy's two engines disagree on,
 * carried as data and fixed by the consumer at submission.
 *
 * - `Ends` — leave: any rejection is final; the steps behind it are closed.
 * - `Advisory` — documents: a rejection is recorded and the approval goes on;
 *   only the `decisive` step (legacy's chief) decides the outcome.
 */
enum RejectionPolicy: int
{
    case Ends     = 1;
    case Advisory = 2;
}
