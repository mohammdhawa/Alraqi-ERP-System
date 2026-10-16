<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\AttendanceRecord;

/**
 * The result of one sync: the records saved (or found saved, for a retried
 * client_uuid), and each refused entry with its index, the entry as sent, and
 * a message fit to show the employee.
 */
final class SyncOutcome
{
    /** @var array<int, AttendanceRecord> */
    public array $succeeded = [];

    /** @var array<int, array{index: int, entry: array<string, mixed>, error: string}> */
    public array $failed = [];
}
