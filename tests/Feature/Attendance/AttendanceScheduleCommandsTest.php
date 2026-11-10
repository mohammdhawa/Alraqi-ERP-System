<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Auth\Enums\ScopeType;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Attendance\Concerns\AttendanceTree;
use Tests\TestCase;

/**
 * The selfie purge the decision round added (Q11), and its schedule. The
 * reminder and the close moved to the shift clock (ShiftClockTest). Legacy's monthly `attendance:cleanup` is not ported: records
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

    public function test_the_purge_runs_at_03_00_in_the_attendance_timezone(): void
    {
        // The reminder and the close are the shift clock's now (ShiftClockTest).
        $events = collect(app(Schedule::class)->events());
        $event = fn (string $command): ?Event => $events->first(fn (Event $e): bool => str_ends_with((string) $e->command, $command));

        $this->assertSame('0 3 * * *', $event('attendance:purge-selfies')?->expression);
        $this->assertSame('Asia/Damascus', $event('attendance:purge-selfies')?->timezone);
        $this->assertNull($event('attendance:cleanup'), 'legacy\'s cleanup is not ported');
    }
}
