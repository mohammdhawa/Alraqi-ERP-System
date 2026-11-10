<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Enums;

/**
 * Where a duty exchange stands (work shifts piece 2, spec §3.2). Pending,
 * Approved, Rejected and Withdrawn mirror its approval; Cancelled is HR's,
 * after approval and before either duty opens.
 */
enum ShiftSwapStatus: int
{
    case Pending   = 1;
    case Approved  = 2;
    case Rejected  = 3;
    case Withdrawn = 4;
    case Cancelled = 5;

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'قيد الاعتماد',
            self::Approved  => 'معتمد',
            self::Rejected  => 'مرفوض',
            self::Withdrawn => 'مسحوب',
            self::Cancelled => 'ملغى',
        };
    }

    /** Still holding its duties: a new exchange of either collides with it. */
    public function isLive(): bool
    {
        return $this === self::Pending || $this === self::Approved;
    }
}
