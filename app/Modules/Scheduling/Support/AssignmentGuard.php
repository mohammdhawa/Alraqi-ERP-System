<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Support;

use App\Modules\Scheduling\Enums\WorkScheduleKind;

/**
 * What a person's schedule span must satisfy (work shifts spec §3.1, §1.2) —
 * pure (C5): the model passes the person's other spans in.
 *
 * TODAY OR LATER. A span starts today or later, so a past day keeps the
 * schedule it had. Ending one is a change from a date too: the latest past
 * day it may end on is yesterday — "no schedule from today".
 */
final class AssignmentGuard
{
    /**
     * @param  list<array{from: string, to: ?string}>  $otherSpans  the person's other spans
     * @return list<string>
     */
    public static function violations(
        bool $creating,
        WorkScheduleKind $kind,
        bool $scheduleActive,
        string $from,
        ?string $to,
        ?string $cycleStart,
        string $today,
        array $otherSpans,
    ): array {
        $violations = [];

        if ($creating && ! $scheduleActive) {
            $violations[] = 'الجدول المحدد غير مفعّل.';
        }
        if ($creating && $from < $today) {
            $violations[] = 'لا يمكن أن يبدأ الجدول قبل اليوم.';
        }
        if ($to !== null && $to < $from) {
            $violations[] = 'تاريخ نهاية الجدول يجب ألا يسبق تاريخ بدايته.';
        }
        if ($to !== null && $to < date('Y-m-d', strtotime($today.' -1 day'))) {
            $violations[] = 'لا يمكن أن ينتهي الجدول قبل اليوم.';
        }
        if ($kind === WorkScheduleKind::Cycle && $cycleStart === null) {
            $violations[] = 'يجب تحديد تاريخ بداية الدورة لهذا الجدول.';
        }
        if ($kind === WorkScheduleKind::Weekly && $cycleStart !== null) {
            $violations[] = 'الجدول الأسبوعي لا يأخذ تاريخ بداية دورة.';
        }

        foreach ($otherSpans as $span) {
            $endsBefore = $to !== null && $to < $span['from'];
            $startsAfter = $span['to'] !== null && $from > $span['to'];
            if (! $endsBefore && ! $startsAfter) {
                $violations[] = 'تتداخل هذه الفترة مع جدول آخر مُسند للموظف.';

                break;
            }
        }

        return $violations;
    }
}
