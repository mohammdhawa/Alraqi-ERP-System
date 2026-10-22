<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Attendance\Services\AttendanceScheduleService;
use App\Modules\Attendance\Support\AttendanceCalendar;
use Illuminate\Console\Command;

/**
 * attendance:auto-close — legacy's, unchanged: at `auto_close_at` (23:59 —
 * Q10) on working days, a check-in with no check-out becomes a missing
 * check-out. Scheduled in routes/console.php.
 */
class CloseAttendanceDay extends Command
{
    protected $signature = 'attendance:auto-close {--date= : The day (Y-m-d); today by default}';

    protected $description = 'Close the attendance day: flag check-ins without a check-out as missing check-outs.';

    public function handle(AttendanceScheduleService $schedule, AttendanceCalendar $calendar): int
    {
        $date = (string) ($this->option('date') ?: $calendar->today());
        $flagged = $schedule->closeDay($date);

        $this->info($flagged === null
            ? "Skipped {$date}: not a working day."
            : "Flagged {$flagged} check-in(s) as missing check-out for {$date}.");

        return self::SUCCESS;
    }
}
