<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\AttendanceRecord;

/**
 * What a record call did: the row, and whether this call created it or found
 * it already saved under the same client_uuid (a retry, Q12). The controller
 * answers 201 for the first and 200 for the second.
 */
final readonly class RecordedAttendance
{
    public function __construct(
        public AttendanceRecord $record,
        public bool $created,
    ) {}
}
