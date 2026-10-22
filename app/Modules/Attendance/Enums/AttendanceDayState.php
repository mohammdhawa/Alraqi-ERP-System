<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Enums;

/**
 * What one person's DAY was — derived by the reports from the rows and from
 * approved leave, never stored (P1). Legacy's day strings, as ints.
 *
 * Legacy used two names for a completed day — `checked_out` on the daily
 * board and `present` in the day grid. They are one state here, CheckedOut.
 *
 * Off and the leave states depend on configuration and on the Leave module:
 * with every day a working day (Q8) a day is never Off, and until leave
 * exists nobody is OnLeave or Excused.
 */
enum AttendanceDayState: int
{
    case CheckedIn       = 1;   // checked in, not out yet
    case CheckedOut      = 2;   // checked in and out — a completed day
    case MissingCheckout = 3;   // the day closed with no check-out
    case OnLeave         = 4;   // approved leave
    case Excused         = 5;   // an absence HR excused
    case Absent          = 6;
    case Off             = 7;   // not a working day
    case Rejected        = 8;   // recorded, and refused by HR
    case NotYet          = 9;   // after today, or today before anything is recorded (parity audit S-1)

    public function label(): string
    {
        return match ($this) {
            self::CheckedIn       => 'حاضر (لم ينصرف بعد)',
            self::CheckedOut      => 'حاضر',
            self::MissingCheckout => 'انصراف مفقود',
            self::OnLeave         => 'في إجازة',
            self::Excused         => 'غياب بعذر',
            self::Absent          => 'غائب',
            self::Off             => 'عطلة',
            self::Rejected        => 'تسجيل مرفوض',
            self::NotYet          => 'لم يُسجَّل بعد',
        };
    }

    /**
     * @return array<int, int>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
