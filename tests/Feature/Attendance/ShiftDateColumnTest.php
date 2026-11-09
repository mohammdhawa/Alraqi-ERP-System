<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Work shifts, step 1 (plan Task 1): every record carries the date of the
 * shift it belongs to. Until schedules exist that is the date it was
 * recorded on, in the attendance timezone — exactly today's grouping.
 */
class ShiftDateColumnTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/Attendance/2026_11_10_100000_add_shift_columns_to_attendance_records.php';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_backfill_matches_the_recorded_date_on_every_row(): void
    {
        $migration = require database_path(self::MIGRATION);
        $migration->down();

        $employee = Employee::factory()->create();
        foreach (['2026-06-21 08:00:00', '2026-06-21 23:59:00'] as $at) {
            DB::table('attendance_records')->insert([
                'employee_id' => $employee->id, 'type' => AttendanceEventType::CheckIn->value, 'source' => 1,
                'recorded_at' => $at, 'latitude' => 33.5, 'longitude' => 36.3,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $migration->up();

        $this->assertSame(['2026-06-21', '2026-06-21'], AttendanceRecord::query()->orderBy('id')->get()
            ->map(fn (AttendanceRecord $r): string => $r->shift_date->toDateString())->all());
        $this->assertSame(0, AttendanceRecord::query()->whereRaw('shift_date <> DATE(recorded_at)')->count());
    }

    public function test_a_record_created_without_a_shift_date_takes_its_recorded_date(): void
    {
        $record = AttendanceRecord::factory()->checkIn()->create(['recorded_at' => '2026-06-21 08:00:00']);

        $this->assertSame('2026-06-21', $record->fresh()->shift_date->toDateString());
        $this->assertFalse($record->fresh()->outside_schedule);
        $this->assertNull($record->fresh()->checkout_reminded_at);
    }

    public function test_a_check_in_sent_in_utc_near_midnight_is_dated_locally(): void
    {
        Storage::fake('local');
        Carbon::setTestNow('2026-11-15 00:40:00');
        $employee = Employee::factory()->create();
        Sanctum::actingAs(User::factory()->create(['employee_id' => $employee->id, 'must_change_password' => false]));

        $this->postJson('/api/me/attendance', [
            'type'        => AttendanceEventType::CheckIn->value,
            'latitude'    => 33.5,
            'longitude'   => 36.3,
            'selfie'      => UploadedFile::fake()->create('selfie.jpg', 100, 'image/jpeg'),
            'recorded_at' => '2026-11-14T21:30:00Z',
        ])->assertCreated();

        $this->assertSame('2026-11-15', AttendanceRecord::query()->sole()->shift_date->toDateString());
    }

    public function test_on_date_filters_by_shift_date(): void
    {
        $record = AttendanceRecord::factory()->checkIn()->create([
            'recorded_at' => '2026-06-22 06:00:00', 'shift_date' => '2026-06-21',
        ]);

        $this->assertSame([$record->id], AttendanceRecord::query()->onDate('2026-06-21')->pluck('id')->all());
        $this->assertSame([], AttendanceRecord::query()->onDate('2026-06-22')->pluck('id')->all());
        $this->assertSame([$record->id], AttendanceRecord::query()->inDateRange('2026-06-21', '2026-06-21')->pluck('id')->all());
    }
}
