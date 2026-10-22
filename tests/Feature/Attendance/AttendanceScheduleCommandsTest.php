<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AttendanceRecordStatus;
use App\Modules\Attendance\Enums\RejectionReason;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Models\Notification;
use App\Shared\Support\NotificationEventCatalogue;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Attendance\Concerns\AttendanceTree;
use Tests\TestCase;

/**
 * The attendance day's schedule — legacy's AttendanceScheduleCommandsTest
 * for the reminder and the close, and the selfie purge the decision round
 * added (Q11). Legacy's monthly `attendance:cleanup` is not ported: records
 * are kept for good (D7).
 */
class AttendanceScheduleCommandsTest extends TestCase
{
    use AttendanceTree;
    use RefreshDatabase;

    private const DAY = '2026-06-21';

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        Storage::fake('local');
        Carbon::setTestNow(self::DAY.' 17:00:00');
        $this->buildTree();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array<int, int> */
    private function remindedEmployees(): array
    {
        $ids = Notification::where('type', NotificationEventCatalogue::ATTENDANCE_CHECKOUT_REMINDER)
            ->get()->map(fn (Notification $n): int => (int) $n->user->employee_id)->all();
        sort($ids);

        return $ids;
    }

    // ── The check-out reminder (17:00) ───────────────────────────────────

    public function test_the_reminder_reaches_everyone_still_checked_in_and_nobody_else(): void
    {
        $this->checkIn($this->people['a'], self::DAY.' 08:00:00');                     // still in
        $this->checkIn($this->people['a1'], self::DAY.' 08:00:00');
        $this->checkOut($this->people['a1'], self::DAY.' 16:00:00');                   // out already
        $this->checkIn($this->people['b'], self::DAY.' 08:00:00', ['rejected_at' => now(), 'rejection_reason' => RejectionReason::Other]);

        $this->artisan('attendance:checkout-reminder')->assertSuccessful();

        $this->assertSame([$this->people['a']->id], $this->remindedEmployees());
        $notice = Notification::sole();
        $this->assertSame('تذكير بتسجيل الانصراف', $notice->title);
        $this->assertSame('لقد سجلت حضورك اليوم ولم تسجل انصرافك بعد. يرجى تسجيل الانصراف.', $notice->body);
    }

    public function test_a_disabled_account_is_not_reminded(): void
    {
        $this->checkIn($this->people['a'], self::DAY.' 08:00:00');
        $this->accountOf($this->people['a'])->update(['is_active' => false]);

        $this->artisan('attendance:checkout-reminder')->assertSuccessful();

        $this->assertSame(0, Notification::count());
    }

    public function test_the_reminder_takes_a_date_and_skips_a_day_off(): void
    {
        $this->checkIn($this->people['a'], '2026-06-20 08:00:00');

        $this->artisan('attendance:checkout-reminder', ['--date' => '2026-06-20'])->assertSuccessful();
        $this->assertSame([$this->people['a']->id], $this->remindedEmployees());

        config(['attendance.working_days' => [1, 2, 3, 4, 5, 6]]);   // Sunday off
        $this->checkIn($this->people['b'], self::DAY.' 08:00:00');
        $this->artisan('attendance:checkout-reminder')->expectsOutputToContain('not a working day')->assertSuccessful();
        $this->assertSame(1, Notification::count());
    }

    // ── Closing the day (23:59) ──────────────────────────────────────────

    public function test_closing_the_day_flags_open_check_ins_and_invents_nothing(): void
    {
        $open = $this->checkIn($this->people['a'], self::DAY.' 08:00:00');
        $done = $this->checkIn($this->people['a1'], self::DAY.' 08:00:00');
        $this->checkOut($this->people['a1'], self::DAY.' 16:00:00');
        $refused = $this->checkIn($this->people['b'], self::DAY.' 08:00:00', ['rejected_at' => now(), 'rejection_reason' => RejectionReason::Location]);

        $this->artisan('attendance:auto-close')->expectsOutputToContain('Flagged 1')->assertSuccessful();

        $this->assertSame(AttendanceRecordStatus::MissingCheckout, $open->fresh()->status);
        $this->assertNull($done->fresh()->status);
        $this->assertNull($refused->fresh()->status, 'a refused day is not put back on the correction queue');
        $this->assertSame(1, AttendanceRecord::checkOuts()->count(), 'no check-out is invented');
        $this->assertNull($open->fresh()->work_hours);
        $this->assertSame(0, Notification::count(), 'legacy notifies nobody at the close');
    }

    public function test_closing_twice_changes_nothing_more_and_a_day_off_is_skipped(): void
    {
        $this->checkIn($this->people['a'], self::DAY.' 08:00:00');
        $this->artisan('attendance:auto-close')->assertSuccessful();
        $this->artisan('attendance:auto-close')->expectsOutputToContain('Flagged 0')->assertSuccessful();

        config(['attendance.working_days' => [1, 2, 3, 4, 5, 6]]);
        $other = $this->checkIn($this->people['b'], self::DAY.' 09:00:00');
        $this->artisan('attendance:auto-close')->expectsOutputToContain('not a working day')->assertSuccessful();
        $this->assertNull($other->fresh()->status);
    }

    public function test_closing_a_past_day_by_date(): void
    {
        $open = $this->checkIn($this->people['a'], '2026-06-20 08:00:00');

        $this->artisan('attendance:auto-close', ['--date' => '2026-06-20'])->assertSuccessful();

        $this->assertSame(AttendanceRecordStatus::MissingCheckout, $open->fresh()->status);
    }

    // ── Purging selfie files after three months (Q11) ────────────────────

    private function withSelfie(string $recordedAt): AttendanceRecord
    {
        $path = "attendance/selfies/{$this->people['a']->id}/".substr($recordedAt, 0, 10).'/'.uniqid().'.jpg';
        Storage::disk('local')->put($path, 'jpeg');

        return $this->checkIn($this->people['a'], $recordedAt, ['selfie_path' => $path]);
    }

    public function test_files_older_than_three_months_go_and_their_records_stay(): void
    {
        $old = $this->withSelfie('2026-03-20 08:00:00');
        $edge = $this->withSelfie('2026-03-21 08:00:00');   // exactly three months: kept
        $recent = $this->withSelfie('2026-06-01 08:00:00');
        $oldPath = $old->selfie_path;

        $this->artisan('attendance:purge-selfies')->expectsOutputToContain('Purged 1')->assertSuccessful();

        Storage::disk('local')->assertMissing($oldPath);
        $old = $old->fresh();
        $this->assertNotNull($old, 'the record is kept forever');
        $this->assertNull($old->selfie_path);
        $this->assertSame(self::DAY.' 17:00:00', $old->selfie_purged_at->format('Y-m-d H:i:s'));
        Storage::disk('local')->assertExists($edge->selfie_path);
        Storage::disk('local')->assertExists($recent->selfie_path);
        $this->assertNull($recent->fresh()->selfie_purged_at);
    }

    public function test_a_file_already_gone_is_still_marked_purged(): void
    {
        $old = $this->withSelfie('2026-01-10 08:00:00');
        Storage::disk('local')->delete($old->selfie_path);

        $this->artisan('attendance:purge-selfies')->assertSuccessful();

        $this->assertNull($old->fresh()->selfie_path);
        $this->assertNotNull($old->fresh()->selfie_purged_at);
    }

    public function test_the_retention_comes_from_the_config(): void
    {
        config(['attendance.selfie_retention_months' => 1]);
        $recent = $this->withSelfie('2026-05-10 08:00:00');

        $this->artisan('attendance:purge-selfies')->assertSuccessful();

        $this->assertNull($recent->fresh()->selfie_path);
    }

    public function test_a_purged_record_shows_no_selfie(): void
    {
        $old = $this->withSelfie('2026-01-10 08:00:00');
        $this->artisan('attendance:purge-selfies')->assertSuccessful();
        $this->actingAsScopedUser(['attendance.records.view'], ScopeType::Company);

        $this->getJson("/api/attendance/records/{$old->id}/selfie")->assertNotFound();
        $this->getJson('/api/attendance/records?date=2026-01-10')->assertJsonPath('data.0.has_selfie', false);
    }

    // ── The schedule ─────────────────────────────────────────────────────

    public function test_the_three_run_at_their_configured_times_in_the_attendance_timezone(): void
    {
        $events = collect(app(Schedule::class)->events());
        $event = fn (string $command): ?Event => $events->first(fn (Event $e): bool => str_ends_with((string) $e->command, $command));

        $this->assertSame('0 17 * * 0,1,2,3,4,5,6', $event('attendance:checkout-reminder')?->expression);
        $this->assertSame('59 23 * * 0,1,2,3,4,5,6', $event('attendance:auto-close')?->expression);
        $this->assertSame('0 3 * * *', $event('attendance:purge-selfies')?->expression);
        $this->assertSame('Asia/Damascus', $event('attendance:auto-close')?->timezone);
        $this->assertNull($event('attendance:cleanup'), 'legacy\'s cleanup is not ported');
    }
}
