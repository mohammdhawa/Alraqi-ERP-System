<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Models;

use App\Modules\Attendance\Enums\LocationReviewReason;
use App\Modules\Attendance\Enums\LocationReviewStatus;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceLocationReview extends Model
{
    protected $fillable = [
        'attendance_record_id', 'work_site_id', 'work_site_version_id', 'assignment_id',
        'site_name', 'site_latitude', 'site_longitude', 'radius_m',
        'latitude', 'longitude', 'distance_m', 'accuracy_m', 'fix_at', 'is_mocked',
        'reason', 'status', 'approved_by', 'approved_at', 'note',
    ];

    protected function casts(): array
    {
        return [
            'site_latitude' => 'float', 'site_longitude' => 'float', 'radius_m' => 'float',
            'latitude' => 'float', 'longitude' => 'float', 'distance_m' => 'float',
            'accuracy_m' => 'float', 'fix_at' => 'datetime', 'is_mocked' => 'boolean',
            'reason' => LocationReviewReason::class, 'status' => LocationReviewStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
