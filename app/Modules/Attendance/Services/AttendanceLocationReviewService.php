<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\LocationReviewReason;
use App\Modules\Attendance\Enums\LocationReviewStatus;
use App\Modules\Attendance\Models\AttendanceLocationReview;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Support\AttendanceCalendar;
use Carbon\CarbonInterface;

final class AttendanceLocationReviewService
{
    public function __construct(
        private readonly WorkSiteAssignmentService $sites,
        private readonly AttendanceCalendar $calendar,
    ) {}

    /** Called inside the employee-row transaction that creates the attendance event. */
    public function classify(AttendanceRecord $record, ?float $accuracyM, ?CarbonInterface $fixAt, ?bool $mocked): AttendanceLocationReview
    {
        $eventAt = $record->recorded_at;
        $site = $this->sites->siteFor($record->employee, $eventAt);
        $version = $site?->version;
        $distance = $version === null ? null : $this->distanceMetres(
            (float) $record->latitude,
            (float) $record->longitude,
            (float) $version->latitude,
            (float) $version->longitude,
        );

        $maxAge = (int) config('attendance.location_review.max_fix_age_seconds', 120);
        $tolerance = (int) config('attendance.location_review.fix_clock_tolerance_seconds', 5);
        $invalidFix = $fixAt === null
            || $fixAt->gt($eventAt->copy()->addSeconds($tolerance))
            || $fixAt->lt($eventAt->copy()->subSeconds($maxAge));

        $atFix = $invalidFix ? null : $this->sites->siteFor($record->employee, $fixAt);
        $changed = $atFix === null
            || $atFix->assignment->id !== $site?->assignment->id
            || $atFix->version?->id !== $version?->id;

        $reason = match (true) {
            $site === null => LocationReviewReason::NoSite,
            ! $site->isActiveAt($eventAt) => LocationReviewReason::InactiveSite,
            $mocked === true => LocationReviewReason::MockLocation,
            $invalidFix => LocationReviewReason::FixTimeInvalid,
            $changed => LocationReviewReason::BoundaryChanged,
            $accuracyM === null || $accuracyM > (float) config('attendance.location_review.max_accuracy_m', 50) => LocationReviewReason::LowAccuracy,
            $distance + $accuracyM > (float) $version->radius_m
                && $distance - $accuracyM <= (float) $version->radius_m => LocationReviewReason::BoundaryUncertain,
            $distance - $accuracyM > (float) $version->radius_m => LocationReviewReason::Outside,
            default => LocationReviewReason::Inside,
        };

        return AttendanceLocationReview::create([
            'attendance_record_id' => $record->id,
            'work_site_id' => $site?->site->id,
            'work_site_version_id' => $version?->id,
            'assignment_id' => $site?->assignment->id,
            'site_name' => $version?->name,
            'site_latitude' => $version?->latitude,
            'site_longitude' => $version?->longitude,
            'radius_m' => $version?->radius_m,
            'latitude' => $record->latitude,
            'longitude' => $record->longitude,
            'distance_m' => $distance === null ? null : round($distance, 2),
            'accuracy_m' => $accuracyM,
            'fix_at' => $fixAt === null ? null : $this->calendar->parse($fixAt->toIso8601String()),
            'is_mocked' => $mocked,
            'reason' => $reason,
            'status' => $reason === LocationReviewReason::Inside ? LocationReviewStatus::InProcess : LocationReviewStatus::ForReview,
        ]);
    }

    private function distanceMetres(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 6371000.0 * 2 * asin(min(1.0, sqrt($a)));
    }
}
