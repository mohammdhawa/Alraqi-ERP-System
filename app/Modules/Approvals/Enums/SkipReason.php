<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Enums;

/**
 * Why a step was never asked. The first two are the decision round's Q2
 * rules, applied at submission; the next two close the steps behind a
 * rejection (legacy's rule) or a withdrawal (§9 Q1). TopOfTree is the
 * owner's answer of 2026-10-20 (parity audit C-1): the head of the tree has
 * no direct manager, so that step is skipped rather than the submission
 * refused.
 */
enum SkipReason: int
{
    case SameApprover         = 1;
    case Requester            = 2;
    case ClosedByRejection    = 3;
    case ClosedByCancellation = 4;
    case TopOfTree            = 5;

    public function label(): string
    {
        return match ($this) {
            self::SameApprover         => 'المعتمد نفسه في الخطوة السابقة',
            self::Requester            => 'المعتمد هو مقدِّم الطلب',
            self::ClosedByRejection    => 'أُغلقت برفض الطلب',
            self::ClosedByCancellation => 'أُغلقت بسحب الطلب',
            self::TopOfTree            => 'لا يوجد مدير أعلى من مقدِّم الطلب',
        };
    }
}
