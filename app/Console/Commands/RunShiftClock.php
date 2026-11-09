<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Attendance\Services\AttendanceShiftClock;
use App\Modules\Attendance\Support\AttendanceCalendar;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * The shift clock (work shifts spec §8): every five minutes, the check-out
 * reminder at each duty's end and the close four hours after it.
 */
class RunShiftClock extends Command
{
    protected $signature = 'attendance:shift-clock {--at= : A moment (Y-m-d H:i:s) to run as of; now by default}';

    protected $description = 'Remind open check-ins whose shift has ended, and flag those past their close as missing check-outs.';

    public function handle(AttendanceShiftClock $clock, AttendanceCalendar $calendar): int
    {
        $at = $this->option('at') ? Carbon::parse((string) $this->option('at'), $calendar->timezone()) : Carbon::now($calendar->timezone());
        $done = $clock->run($at);

        $this->info("Reminded {$done['reminded']}, closed {$done['closed']} as of {$at->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
