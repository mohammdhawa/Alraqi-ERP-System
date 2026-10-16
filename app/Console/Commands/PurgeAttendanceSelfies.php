<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Attendance\Services\AttendanceScheduleService;
use Illuminate\Console\Command;

/**
 * attendance:purge-selfies — new (Q11, 2026-10-16): selfie FILES are kept
 * `selfie_retention_months` (three), then deleted; the attendance record is
 * kept for good. Replaces nothing — legacy's attendance:cleanup, which
 * deleted the records too, is not ported (D7). Scheduled daily at 03:00 in
 * routes/console.php.
 */
class PurgeAttendanceSelfies extends Command
{
    protected $signature = 'attendance:purge-selfies';

    protected $description = 'Delete attendance selfie files past their retention; the records are kept.';

    public function handle(AttendanceScheduleService $schedule): int
    {
        $this->info('Purged '.$schedule->purgeSelfies().' selfie file(s).');

        return self::SUCCESS;
    }
}
