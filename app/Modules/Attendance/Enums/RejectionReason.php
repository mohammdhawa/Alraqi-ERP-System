<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Enums;

/**
 * Why HR refused an attendance event — legacy's three reasons and their exact
 * Arabic wording, so the employee's notification and every report name a
 * refusal identically. Used by slice 2's reject endpoint.
 */
enum RejectionReason: int
{
    case Location = 1;
    case Selfie   = 2;
    case Other    = 3;

    public function label(): string
    {
        return match ($this) {
            self::Location => 'الموقع المسجَّل لا يطابق موقع العمل',
            self::Selfie   => 'الصورة الشخصية غير صحيحة أو غير واضحة',
            self::Other    => 'مخالفة تعليمات تسجيل الحضور',
        };
    }
}
