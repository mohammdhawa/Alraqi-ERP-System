<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Support\AttendanceCalendar;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The attendance calendar — the calendar half of legacy's WorkingCalendarTest
 * (its leave half ports with the Leave module).
 *
 * Decided 2026-10-16 (Q8, Q10): no weekly day off and no holiday calendar, as
 * legacy; the day closes at 23:59, in the config AND in the code fallback.
 */
class AttendanceCalendarTest extends TestCase
{
    private function calendar(): AttendanceCalendar
    {
        return app(AttendanceCalendar::class);
    }

    public function test_the_shipped_config_has_no_weekly_day_off(): void
    {
        $this->assertSame([0, 1, 2, 3, 4, 5, 6], config('attendance.working_days'));
    }

    public function test_every_day_of_the_week_is_a_working_and_chargeable_day(): void
    {
        // 2026-06-15 is a Monday; the next seven days cover the whole week.
        for ($day = 15; $day <= 21; $day++) {
            $date = Carbon::parse("2026-06-{$day}");
            $this->assertTrue($this->calendar()->isWorkingDay($date), $date->englishDayOfWeek);
            $this->assertTrue($this->calendar()->isChargeableDay($date), $date->englishDayOfWeek);
        }
    }

    /** @return array<string, array{0: mixed}> */
    public static function brokenConfigs(): array
    {
        return ['missing' => [null], 'empty' => [[]], 'not a list' => ['friday']];
    }

    #[DataProvider('brokenConfigs')]
    public function test_a_broken_config_falls_back_to_the_full_week(mixed $configured): void
    {
        config(['attendance.working_days' => $configured]);

        $this->assertSame([0, 1, 2, 3, 4, 5, 6], $this->calendar()->workingDays());
    }

    public function test_a_configured_day_off_is_still_honoured(): void
    {
        config(['attendance.working_days' => [0, 1, 2, 3, 4, 6]]);

        $this->assertFalse($this->calendar()->isWorkingDay(Carbon::parse('2026-06-19')), 'Friday');
        $this->assertSame(6, $this->calendar()->workingDaysBetween('2026-06-15', '2026-06-21'));
    }

    public function test_working_days_between_counts_the_whole_span_inclusively(): void
    {
        $this->assertSame(7, $this->calendar()->workingDaysBetween('2026-06-15', '2026-06-21'));
        $this->assertSame(1, $this->calendar()->workingDaysBetween('2026-06-15', '2026-06-15'));
        $this->assertSame(0, $this->calendar()->workingDaysBetween('2026-06-16', '2026-06-15'));
    }

    public function test_the_day_closes_at_23_59_even_without_the_setting(): void
    {
        $this->assertSame('23:59', $this->calendar()->autoCloseAt());

        config(['attendance.auto_close_at' => null]);
        $this->assertSame('23:59', $this->calendar()->autoCloseAt(), "legacy's code fell back to 22:00");
    }

    public function test_the_check_in_window_is_open_as_shipped(): void
    {
        $this->assertSame('', $this->calendar()->checkInStart());
        $this->assertSame('', $this->calendar()->checkInEnd());
    }

    public function test_today_is_in_the_attendance_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-21 22:30:00', 'UTC'));   // 01:30 on the 22nd in Damascus

        $this->assertSame('2026-06-22', $this->calendar()->today());

        Carbon::setTestNow();
    }
}
