<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Enums;

enum LocationReviewStatus: string
{
    case ForReview = 'for_review';
    case InProcess = 'in_process';
    case Approved = 'approved';
}
