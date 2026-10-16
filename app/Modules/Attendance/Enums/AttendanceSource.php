<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Enums;

/**
 * How a row arrived. New in the ERP: legacy inferred it from which endpoint
 * was hit, and the reports and the parallel-run comparison want it stored
 * (LEGACY_TO_ERP_MAPPING.md §4).
 */
enum AttendanceSource: int
{
    case Online       = 1;   // تسجيل مباشر
    case OfflineSync  = 2;   // مزامنة بعد انقطاع
    case HrCorrection = 3;   // تصحيح من الموارد البشرية

    public function label(): string
    {
        return match ($this) {
            self::Online       => 'تسجيل مباشر',
            self::OfflineSync  => 'مزامنة بعد انقطاع',
            self::HrCorrection => 'تصحيح من الموارد البشرية',
        };
    }
}
