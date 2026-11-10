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

// The shift clock (work shifts spec §8): the check-out reminder at each
// duty's end and the close four hours after it, judged in the attendance
// timezone — replacing legacy's one reminder at 17:00 and one close at 23:59
// (decision round S5). Legacy's monthly attendance:cleanup is NOT scheduled:
// records are kept (D7).
$attendance = app(AttendanceCalendar::class);

Schedule::command('attendance:shift-clock')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Selfie files past their retention (three months, Q11); the records stay.
Schedule::command('attendance:purge-selfies')
    ->timezone($attendance->timezone())
    ->dailyAt('03:00');

// The upload import's files and report, an hour after the upload
// (EMPLOYEE_IMPORT_UPLOAD_DESIGN.md §4.6); the runs themselves are kept.
// Every five minutes, so nothing outlives its hour by more than that (U5).
Schedule::command('hr:expire-import-runs')->everyFiveMinutes();
