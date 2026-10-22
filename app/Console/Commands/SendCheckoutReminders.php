<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Attendance\Services\AttendanceScheduleService;
use App\Modules\Attendance\Support\AttendanceCalendar;
use Illuminate\Console\Command;

/**
 * attendance:checkout-reminder — legacy's, unchanged: at `reminder_at`
 * (17:00) on working days, everyone still checked in is reminded to check
 * out. Scheduled in routes/console.php.
 */
class SendCheckoutReminders extends Command
{
    protected $signature = 'attendance:checkout-reminder {--date= : The day (Y-m-d); today by default}';

    protected $description = 'Remind employees who checked in today and have not checked out yet.';

    public function handle(AttendanceScheduleService $schedule, AttendanceCalendar $calendar): int
    {
        $date = (string) ($this->option('date') ?: $calendar->today());
        $reminded = $schedule->remindCheckout($date);

        $this->info($reminded === null
            ? "Skipped {$date}: not a working day."
            : "Sent check-out reminders to {$reminded} employee(s) for {$date}.");

        return self::SUCCESS;
    }
}
