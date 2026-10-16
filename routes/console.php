<?php

use App\Modules\Attendance\Support\AttendanceCalendar;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily cleanup of expired/revoked refresh tokens so the table does not
// grow unbounded. The command keeps revoked tokens for a retention window
// (default 7 days) for forensic analysis before deleting them.
Schedule::command('auth:prune-refresh-tokens')->daily();

// The attendance day (work order §4.5 slice 3), as legacy's Kernel scheduled
// it: the times and the working days come from config/attendance.php through
// AttendanceCalendar — whose fallbacks are the shipped values, so the close
// is 23:59 even without the setting (Q10) — judged in the attendance timezone.
// Legacy's monthly attendance:cleanup is NOT scheduled: records are kept (D7).
$attendance = app(AttendanceCalendar::class);

Schedule::command('attendance:checkout-reminder')
    ->days($attendance->workingDays())
    ->timezone($attendance->timezone())
    ->dailyAt($attendance->reminderAt());

Schedule::command('attendance:auto-close')
    ->days($attendance->workingDays())
    ->timezone($attendance->timezone())
    ->dailyAt($attendance->autoCloseAt());

// Selfie files past their retention (three months, Q11); the records stay.
Schedule::command('attendance:purge-selfies')
    ->timezone($attendance->timezone())
    ->dailyAt('03:00');
