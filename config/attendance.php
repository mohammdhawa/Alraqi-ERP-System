<?php

/*
|--------------------------------------------------------------------------
| Attendance — ported from legacy's config/attendance.php
|--------------------------------------------------------------------------
|
| Legacy's keys and values (work order §4.5), except where the work shifts
| decision round (2026-11-09, DECISION_ROUND_WORK_SHIFTS.md) replaced the
| company calendar with each person's schedule: the working days, the
| check-in window, the 17:00 reminder and the 23:59 close are per shift now
| (the work shifts spec §3.3, §8). Q8.2 (no holiday calendar) still stands.
| AttendanceCalendar and the Scheduling resolver are the readers.
|
*/

return [

    /*
    | All attendance rules (the working day, the window, the reminder and the
    | close) are judged in this one timezone, as legacy does.
    */
    'timezone' => env('ATTENDANCE_TIMEZONE', config('app.timezone')),

    /*
    | The shortest gap, in minutes, between a check-in and its check-out — a
    | guard against a check-out tapped by mistake. The boundary is allowed; 0
    | turns it off. HR corrections are exempt.
    */
    'min_checkout_gap_minutes' => (int) env('ATTENDANCE_MIN_CHECKOUT_GAP', 60),

    /*
    | How far ahead of the server's clock a recorded moment may be, in minutes
    | — room for a phone's clock drift, and no more: a phone set to tomorrow
    | can no longer check in for tomorrow (parity audit S-6, owner 2026-10-20;
    | legacy accepted any moment). Offline entries are always in the past.
    */
    'max_clock_ahead_minutes' => (int) env('ATTENDANCE_MAX_CLOCK_AHEAD', 10),

    /*
    | When false, approved leave on a day blocks checking in on it.
    */
    'allow_attendance_during_leave' => (bool) env('ATTENDANCE_ALLOW_DURING_LEAVE', false),

    /*
    | Work shifts (DECISION_ROUND_WORK_SHIFTS.md; the work shifts spec §3.3).
    |
    | default_schedule        the code of the schedule for anyone with none
    |                         assigned (S3: OFFICE, 09:00–16:00, Saturday to
    |                         Thursday). It must exist: SchedulingSeeder.
    | grace_minutes           late or early beyond this counts (S4).
    | check_in_opens_minutes  how early before a shift check-in opens (S10).
    | close_after_minutes     how long after a shift's end the day closes:
    |                         an open check-in becomes a missing check-out
    |                         (S5); a check-out recorded later finds no
    |                         check-in to close.
    */
    'default_schedule'       => env('ATTENDANCE_DEFAULT_SCHEDULE', 'OFFICE'),
    'grace_minutes'          => (int) env('ATTENDANCE_GRACE_MINUTES', 15),
    'check_in_opens_minutes' => (int) env('ATTENDANCE_CHECK_IN_OPENS_MINUTES', 60),
    'close_after_minutes'    => (int) env('ATTENDANCE_CLOSE_AFTER_MINUTES', 240),

    /*
    | Selfie FILES are kept this many months, then deleted by
    | attendance:purge-selfies; the attendance record itself is never deleted
    | (Q11). Stored on the private `local` disk under `selfie_directory`.
    */
    'selfie_retention_months' => (int) env('ATTENDANCE_SELFIE_RETENTION_MONTHS', 3),
    'selfie_directory'        => 'attendance/selfies',
];
