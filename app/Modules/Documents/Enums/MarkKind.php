<?php

declare(strict_types=1);

namespace App\Modules\Documents\Enums;

/**
 * What a printed mark is (documents design §6.2).
 */
enum MarkKind: int
{
    case ApprovalCard      = 1;
    case RejectionCard     = 2;
    case EmployeeSignature = 3;
}
