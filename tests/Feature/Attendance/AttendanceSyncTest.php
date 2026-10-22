<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Enums\AttendanceSource;
use App\Modules\Attendance\Exceptions\AttendanceException;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use League\Flysystem\UnableToWriteFile;
use Tests\TestCase;

/**
 * POST /api/me/attendance/sync — the offline queue (legacy's /attendance/sync
 * and its AttendanceSyncErrorLeakageTest, ported).
 *
 * Each entry stands alone: one refusal does not block the others. Only an
 * AttendanceException's message — written for the employee — reaches the
 * client; anything else is logged with its context and reported generically,
 * so SQL, bindings and server paths never leave the server. And since the
 * decision round (Q12), a retried entry carrying the same client_uuid comes
 * back as a success with the record already saved.
 */
class AttendanceSyncTest extends TestCase
{
    use RefreshDatabase;

    private const SUNDAY = '2026-06-21';

    private const GENERIC = 'تعذر حفظ هذا السجل، يرجى المحاولة لاحقاً.';

    private Employee $employee;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->employee = Employee::factory()->create();
        $this->user = User::factory()->create(['employee_id' => $this->employee->id, 'must_change_password' => false]);
    }

    /** @return array<string, mixed> */
    private function entry(string $recordedAt, AttendanceEventType $type = AttendanceEventType::CheckIn, ?string $uuid = null): array
    {
        return array_filter([
            'type'        => $type->value,
            'latitude'    => 33.5,
            'longitude'   => 36.3,
            'recorded_at' => $recordedAt,
            'client_uuid' => $uuid,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function selfie(): UploadedFile
    {
        return UploadedFile::fake()->create('selfie.jpg', 100, 'image/jpeg');
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @param  array<int, UploadedFile>|null  $selfies  one per entry by default
     */
    private function sync(array $entries, ?array $selfies = null): TestResponse
    {
        Sanctum::actingAs($this->user);

        return $this->postJson('/api/me/attendance/sync', [
            'records' => $entries,
            'selfies' => $selfies ?? array_map(fn (): UploadedFile => $this->selfie(), $entries),
        ]);
    }

    private function failRecordingWith(\Throwable $exception): void
    {
        $this->partialMock(AttendanceService::class, function ($mock) use ($exception): void {
            $mock->shouldReceive('record')->andThrow($exception);
        });
    }

    private function queryException(): QueryException
    {
        return new QueryException(
            'mysql',
            'insert into `attendance_records` (`employee_id`, `latitude`) values (?, ?)',
            [$this->employee->id, 33.5],
            new \PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'selfie_path'"),
        );
    }

    // ── The queue ────────────────────────────────────────────────────────

    public function test_a_day_queued_offline_is_saved_in_order(): void
    {
        $this->sync([
            $this->entry(self::SUNDAY.' 08:00:00'),
            $this->entry(self::SUNDAY.' 16:00:00', AttendanceEventType::CheckOut),
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data.succeeded')
            ->assertJsonCount(0, 'data.failed')
            ->assertJsonPath('data.succeeded.1.work_hours', 8);

        $this->assertSame(
            [AttendanceSource::OfflineSync, AttendanceSource::OfflineSync],
            AttendanceRecord::orderBy('id')->get()->pluck('source')->all(),
        );
    }

    public function test_one_refused_entry_does_not_block_the_others(): void
    {
        $this->sync([
            $this->entry(self::SUNDAY.' 08:00:00'),
            $this->entry(self::SUNDAY.' 09:00:00'),   // a second check-in: refused
            $this->entry(self::SUNDAY.' 16:00:00', AttendanceEventType::CheckOut),
        ])
            ->assertOk()
            ->assertJsonCount(2, 'data.succeeded')
            ->assertJsonPath('data.failed.0.index', 1)
            ->assertJsonPath('data.failed.0.error', 'لديك تسجيل حضور قائم بالفعل لهذا اليوم.');

        $this->assertCount(2, Storage::disk('local')->allFiles(), 'the refused entry left no selfie');
    }

    public function test_a_retried_entry_comes_back_as_saved(): void
    {
        $uuid = '0b8e9f3a-2c4d-4e5f-8a9b-0c1d2e3f4a5b';

        $first = $this->sync([$this->entry(self::SUNDAY.' 08:00:00', uuid: $uuid)])->assertOk();
        $retry = $this->sync([$this->entry(self::SUNDAY.' 08:00:00', uuid: $uuid)])
            ->assertOk()
            ->assertJsonCount(1, 'data.succeeded')
            ->assertJsonCount(0, 'data.failed');

        $this->assertSame($first->json('data.succeeded.0.id'), $retry->json('data.succeeded.0.id'));
        $this->assertSame(1, AttendanceRecord::count());
    }

    public function test_an_account_with_no_employee_cannot_sync(): void
    {
        $this->user = User::factory()->create(['must_change_password' => false]);

        $this->sync([$this->entry(self::SUNDAY.' 08:00:00')])->assertForbidden();
    }

    public function test_the_entries_are_validated(): void
    {
        $this->sync([['type' => 9, 'latitude' => 200]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['records.0.type', 'records.0.latitude', 'records.0.longitude', 'records.0.recorded_at']);
    }

    // ── Deliberate business messages still reach the client ──────────────

    public function test_an_attendance_exception_message_is_returned_for_the_entry(): void
    {
        $message = 'لديك تسجيل حضور قائم بالفعل لهذا اليوم.';
        $this->failRecordingWith(new AttendanceException($message));

        $this->sync([$this->entry(self::SUNDAY.' 08:00:00')])
            ->assertOk()
            ->assertJsonPath('data.failed.0.error', $message)
            ->assertJsonCount(0, 'data.succeeded');
    }

    public function test_a_missing_selfie_is_a_business_rule_not_a_generic_failure(): void
    {
        $this->sync([$this->entry(self::SUNDAY.' 08:00:00')], selfies: [])
            ->assertOk()
            ->assertJsonPath('data.failed.0.error', 'الصورة الشخصية مطلوبة لكل سجل.');
    }

    // ── Unexpected failures leak nothing ─────────────────────────────────

    public function test_a_query_exception_leaks_no_sql_or_bindings(): void
    {
        $this->failRecordingWith($this->queryException());

        $body = $this->sync([$this->entry(self::SUNDAY.' 08:00:00')])
            ->assertOk()
            ->assertJsonPath('data.failed.0.error', self::GENERIC)
            ->getContent();

        foreach (['insert into', 'attendance_records', 'Unknown column', '42S22'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }

    public function test_an_invalid_argument_exception_is_not_echoed(): void
    {
        $this->failRecordingWith(new \InvalidArgumentException('Unexpected data found. Unparsed text at /var/www/raqi/vendor/nesbot/carbon'));

        $body = $this->sync([$this->entry(self::SUNDAY.' 08:00:00')])
            ->assertOk()
            ->assertJsonPath('data.failed.0.error', self::GENERIC)
            ->getContent();

        $this->assertStringNotContainsString('/var/www', $body);
    }

    public function test_a_filesystem_exception_leaks_no_server_path(): void
    {
        $this->failRecordingWith(new UnableToWriteFile('Unable to write file at location: /var/www/erp/storage/app/private/attendance/selfies/1'));

        $body = $this->sync([$this->entry(self::SUNDAY.' 08:00:00')])
            ->assertOk()
            ->assertJsonPath('data.failed.0.error', self::GENERIC)
            ->getContent();

        $this->assertStringNotContainsString('/var/www', $body);
    }

    // ── The diagnostic log survives ──────────────────────────────────────

    public function test_an_unexpected_failure_is_logged_with_its_context(): void
    {
        $exception = $this->queryException();
        $this->failRecordingWith($exception);
        Log::spy();

        $this->sync([$this->entry(self::SUNDAY.' 08:00:00')])->assertOk();

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Attendance sync entry failed'
                && $context['employee_id'] === $this->employee->id
                && $context['index'] === 0
                && $context['exception'] === QueryException::class
                && str_contains($context['error'], 'insert into `attendance_records`')
                && $context['file'] === $exception->getFile().':'.$exception->getLine()
                && is_array($context['trace']) && $context['trace'] !== [])
            ->once();
    }

    public function test_a_business_refusal_is_not_logged_as_a_failure(): void
    {
        $this->failRecordingWith(new AttendanceException('لديك تسجيل حضور قائم بالفعل لهذا اليوم.'));
        Log::spy();

        $this->sync([$this->entry(self::SUNDAY.' 08:00:00')])->assertOk();

        Log::shouldNotHaveReceived('error');
    }
}
