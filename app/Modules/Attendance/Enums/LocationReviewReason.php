<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Enums;

enum LocationReviewReason: string
{
    case NoSite = 'no_site';
    case InactiveSite = 'inactive_site';
    case MockLocation = 'mock_location';
    case FixTimeInvalid = 'fix_time_invalid';
    case BoundaryChanged = 'boundary_changed';
    case LowAccuracy = 'low_accuracy';
    case BoundaryUncertain = 'boundary_uncertain';
    case Outside = 'outside';
    case Inside = 'inside';
}
