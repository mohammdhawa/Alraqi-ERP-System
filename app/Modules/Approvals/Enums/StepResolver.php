<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Enums;

/**
 * How a step's approver is found at submission (design §4). Stored on the
 * step so a skip or a refusal can be explained later.
 *
 * - `DirectManager` — ManagementChain: the unit's manager, past a vacant post
 *   or past the requester to the nearest unit above (decision 15).
 * - `UnitManager` — the manager of the unit with a given code (`HR` for
 *   leave's second step, Q1 = B). A vacant post does not climb: it refuses
 *   the submission (§9 Q2).
 * - `NamedEmployee` — a person the requester chose (documents, November).
 */
enum StepResolver: int
{
    case DirectManager = 1;
    case UnitManager   = 2;
    case NamedEmployee = 3;

    public function label(): string
    {
        return match ($this) {
            self::DirectManager => 'المدير المباشر',
            self::UnitManager   => 'مدير وحدة محددة',
            self::NamedEmployee => 'موظف محدد',
        };
    }
}
