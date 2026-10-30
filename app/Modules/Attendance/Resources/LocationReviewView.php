<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Resources;

use App\Modules\Attendance\Models\AttendanceLocationReview;
use App\Shared\Support\Actor;

final class LocationReviewView
{
    /** @return array<string, mixed>|null */
    public static function from(?AttendanceLocationReview $review, bool $employeeShape): ?array
    {
        if ($review === null) {
            return null;
        }

        $site = $review->work_site_id === null ? null : [
            'id' => $review->work_site_id,
            'name' => $review->site_name,
        ];
        $base = [
            'status' => $review->status->value,
            'reason' => $review->reason->value,
            'site' => $site,
            'distance_m' => $review->distance_m,
        ];
        if ($employeeShape) {
            return $base;
        }

        if ($site !== null) {
            $base['site']['latitude'] = $review->site_latitude;
            $base['site']['longitude'] = $review->site_longitude;
        }

        return [
            ...$base,
            'radius_m' => $review->radius_m,
            'accuracy_m' => $review->accuracy_m,
            'fix_at' => $review->fix_at?->toIso8601String(),
            'is_mocked' => $review->is_mocked,
            'approved_by' => $review->approved_by === null ? null : Actor::of($review->approvedBy),
            'approved_at' => $review->approved_at?->toIso8601String(),
            'note' => $review->note,
        ];
    }
}
