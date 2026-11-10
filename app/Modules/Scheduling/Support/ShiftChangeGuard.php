<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Support;

use App\Modules\Scheduling\Enums\ShiftChangeOrigin;

/**
 * What a change must satisfy (work shifts piece 2, spec §3.1) — pure (C5):
 * the model passes the facts in.
 */
final class ShiftChangeGuard
{
    /**
     * @param  ?bool  $shiftActive  whether the shift being set is active; null when no shift is being set
     * @return list<string>
     */
    public static function violations(ShiftChangeOrigin $origin, ?int $swapId, ?string $reason, ?bool $shiftActive): array
    {
        $violations = [];

        if ($shiftActive === false) {
            $violations[] = 'الوردية المحددة غير مفعّلة.';
        }
        if (($origin === ShiftChangeOrigin::Swap) !== ($swapId !== null)) {
            $violations[] = 'مصدر التعديل لا يطابق التبديل.';
        }
        if ($origin === ShiftChangeOrigin::Hr && trim((string) $reason) === '') {
            $violations[] = 'سبب التعديل مطلوب.';
        }

        return $violations;
    }
}
