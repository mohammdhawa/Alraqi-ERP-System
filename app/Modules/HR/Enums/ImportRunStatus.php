<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

/**
 * Where an upload import stands (EMPLOYEE_IMPORT_UPLOAD_DESIGN.md §4.1).
 * Stored as an unsignedTinyInteger, by the house standard (C26).
 */
enum ImportRunStatus: int
{
    case Checked  = 1;   // the dry run ran; the files wait for the real one
    case Executed = 2;   // imported for real, once
    case Expired  = 3;   // the hour passed before the real run; files gone

    /**
     * Arabic display name.
     */
    public function label(): string
    {
        return match ($this) {
            self::Checked  => 'تم الفحص',
            self::Executed => 'تم الاستيراد',
            self::Expired  => 'منتهي الصلاحية',
        };
    }
}
