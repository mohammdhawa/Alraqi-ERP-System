<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Contracts\ExpectedShifts;
use App\Modules\Attendance\Support\AttendanceCalendar;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The attendance clock, and the shipped default schedule that replaced the
 * company calendar (work shifts decision round, 2026-11-09: S3 — 09:00–16:00,
 * Saturday to Thursday — and S5 — the close per shift, not at 23:59).
 */
class AttendanceCalendarTest extends TestCase
{
    use RefreshDatabase;

    private function calendar(): AttendanceCalendar
    {
        return app(AttendanceCalendar::class);
    }

    public function test_the_shipped_default_is_office_saturday_to_thursday(): void
    {
        $this->assertSame('OFFICE', config('attendance.default_schedule'));

        // 2026-11-14 is a Saturday; 2026-11-20 a Friday. Employee id 0 has no
        // schedule of its own, so it reads the default.
        $week = app(ExpectedShifts::class)->shiftsFor([0], '2026-11-14', '2026-11-20')[0];

        $this->assertSame(['2026-11-14', '2026-11-15', '2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19'], array_keys($week));
        $this->assertSame(['09:00', '16:00'], [$week['2026-11-14']->startsAt->format('H:i'), $week['2026-11-14']->endsAt->format('H:i')]);
    }

    public function test_the_shipped_margins(): void
    {
        $this->assertSame([15, 60, 240], [
            config('attendance.grace_minutes'),
            config('attendance.check_in_opens_minutes'),
            config('attendance.close_after_minutes'),
        ]);
    }

    public function test_the_retired_calendar_settings_are_gone(): void
    {
        foreach (['working_days', 'check_in', 'reminder_at', 'auto_close_at'] as $key) {
            $this->assertNull(config("attendance.{$key}"), "attendance.{$key} is per shift now");
        }
    }

    public function test_today_is_in_the_attendance_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-21 22:30:00', 'UTC'));   // 01:30 on the 22nd in Damascus

        $this->assertSame('2026-06-22', $this->calendar()->today());

        Carbon::setTestNow();
    }
}
