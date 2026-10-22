<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Enums;

/**
 * The moments the engine tells an approver about a subject — each a
 * different sentence in legacy, which is why the subject is asked for the
 * words (Approvable::describeForApprover()):
 *
 * - `Submitted` — the first approver(s), on submission («طلب إجازة جديد»).
 * - `YourTurn` — the next approver(s), when the chain advances («دورك لمراجعة طلب إجازة»).
 * - `ReassignedNow` — a reassigned step that is current («…بانتظار مراجعتك الآن»).
 * - `ReassignedAhead` — a reassigned later step («أصبحت ضمن مسار اعتماد…»).
 */
enum ApproverNotice
{
    case Submitted;
    case YourTurn;
    case ReassignedNow;
    case ReassignedAhead;
}
