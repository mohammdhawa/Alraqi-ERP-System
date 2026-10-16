<?php

/*
|--------------------------------------------------------------------------
| Attendance — ported from legacy's config/attendance.php
|--------------------------------------------------------------------------
|
| The same keys and the same values legacy ships (work order §4.5), with the
| decision round's answers (2026-10-16, work order §8): no weekly day off and
| no holiday calendar (Q8), no "late" — the check-in window stays open (Q9),
| and the day closes at 23:59 (Q10). AttendanceCalendar is the only reader;
| its fallbacks match these values, so a missing key cannot change policy.
|
*/

return [

    /*
    | All attendance rules (the working day, the window, the reminder and the
    | close) are judged in this one timezone, as legacy does.
    */
    'timezone' => env('ATTENDANCE_TIMEZONE', config('app.timezone')),

    /*
    | Days on which attendance is recorded and expected, as Carbon dayOfWeek
    | integers (Sunday = 0 … Saturday = 6). Every day — there is no weekly day
    | off (Q8). The same calendar decides absent days and which leave days are
    | charged, so every surface reads one policy.
    */
    'working_days' => [0, 1, 2, 3, 4, 5, 6],

    /*
    | The check-in window, "H:i", inclusive. An empty bound is no bound; both
    | empty — as shipped — means check-in is open all day (Q9: no "late").
    */
    'check_in' => [
        'start' => env('ATTENDANCE_CHECKIN_START', ''),
        'end'   => env('ATTENDANCE_CHECKIN_END', ''),
    ],

    /*
    | The shortest gap, in minutes, between a check-in and its check-out — a
    | guard against a check-out tapped by mistake. The boundary is allowed; 0
    | turns it off. HR corrections are exempt.
    */
    'min_checkout_gap_minutes' => (int) env('ATTENDANCE_MIN_CHECKOUT_GAP', 60),

    /*
    | reminder_at   push a check-out reminder to everyone still checked in.
    | auto_close_at close the day: an open check-in becomes a missing check-out,
    |               with no invented check-out and no hours.
    */
    'reminder_at'   => env('ATTENDANCE_REMINDER_AT', '17:00'),
    'auto_close_at' => env('ATTENDANCE_AUTO_CLOSE_AT', '23:59'),

    /*
    | When false, approved leave on a day blocks checking in on it.
    */
    'allow_attendance_during_leave' => (bool) env('ATTENDANCE_ALLOW_DURING_LEAVE', false),

    /*
    | Selfie FILES are kept this many months, then deleted by
    | attendance:purge-selfies; the attendance record itself is never deleted
    | (Q11). Stored on the private `local` disk under `selfie_directory`.
    */
    'selfie_retention_months' => (int) env('ATTENDANCE_SELFIE_RETENTION_MONTHS', 3),
    'selfie_directory'        => 'attendance/selfies',
];
