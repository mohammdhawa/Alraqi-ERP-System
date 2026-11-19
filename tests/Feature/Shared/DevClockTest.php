<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Shared\Support\DevClock;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * dev:clock — the local test clock for hand-run scenarios that cannot wait
 * for a shift to end (docs/manual-test-scenarios, work shifts).
 *
 * Locks in that (a) the command stores an offset and a local boot applies it
 * to every Carbon "now", ticking; (b) nothing but a local environment is ever
 * moved, and production refuses the command; (c) a relative moment builds on
 * the moved clock; (d) --adb moves the emulator with it.
 *
 * The file lives in a temp path here: the real one under storage/ would move
 * the clock of a developer's running server.
 */
class DevClockTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dev-clock-'.uniqid('', true).'.json';
        config(['dev_clock.path' => $this->path, 'attendance.timezone' => 'Asia/Damascus']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        @unlink($this->path);

        parent::tearDown();
    }

    private function clock(): DevClock
    {
        return $this->app->make(DevClock::class);
    }

    public function test_a_moment_moves_every_now_once_a_local_boot_applies_it(): void
    {
        $this->artisan('dev:clock', ['moment' => '2030-01-05 07:30'])->assertSuccessful();

        $this->assertFileExists($this->path);
        $this->assertEqualsWithDelta(now()->getTimestamp(), time(), 5, 'A testing boot is never moved.');

        $this->clock()->boot('local');

        $this->assertSame('2030-01-05 07:30', now('Asia/Damascus')->format('Y-m-d H:i'));
        $this->assertSame('2030-01-05 07:30', Carbon::now('Asia/Damascus')->format('Y-m-d H:i'));
        $this->assertSame('2030-01-05', today('Asia/Damascus')->toDateString());
    }

    public function test_the_moved_clock_keeps_ticking(): void
    {
        $this->artisan('dev:clock', ['moment' => '2030-01-05 07:30:00'])->assertSuccessful();

        $stored  = $this->clock()->offsetSeconds();
        $target  = Carbon::parse('2030-01-05 07:30:00', 'Asia/Damascus')->getTimestamp();

        // An offset from the real clock, not a frozen moment.
        $this->assertEqualsWithDelta($target - time(), $stored, 5);
    }

    public function test_a_relative_moment_builds_on_the_moved_clock(): void
    {
        $this->artisan('dev:clock', ['moment' => '2030-01-05 07:30'])->assertSuccessful();
        $this->artisan('dev:clock', ['moment' => '+3 days'])->assertSuccessful();

        $this->clock()->boot('local');

        $this->assertSame('2030-01-08 07:30', now('Asia/Damascus')->format('Y-m-d H:i'));

        Carbon::setTestNow();
        $this->artisan('dev:clock', ['moment' => 'tomorrow 08:05'])->assertSuccessful();
        $this->clock()->boot('local');

        $this->assertSame('2030-01-09 08:05', now('Asia/Damascus')->format('Y-m-d H:i'));
    }

    public function test_reset_returns_to_the_real_clock(): void
    {
        $this->artisan('dev:clock', ['moment' => '2030-01-05 07:30'])->assertSuccessful();
        $this->artisan('dev:clock', ['--reset' => true])->assertSuccessful();

        $this->assertFileDoesNotExist($this->path);

        $this->clock()->boot('local');

        $this->assertEqualsWithDelta(time(), now()->getTimestamp(), 5);
    }

    public function test_only_a_local_boot_is_moved(): void
    {
        $this->artisan('dev:clock', ['moment' => '2030-01-05 07:30'])->assertSuccessful();

        foreach (['production', 'staging', 'testing'] as $environment) {
            $this->clock()->boot($environment);

            $this->assertEqualsWithDelta(time(), now()->getTimestamp(), 5, $environment);
        }
    }

    public function test_production_refuses_the_command(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('dev:clock', ['moment' => '2030-01-05 07:30'])->assertFailed();

        $this->assertFileDoesNotExist($this->path);
    }

    public function test_an_unreadable_moment_fails_and_changes_nothing(): void
    {
        $this->artisan('dev:clock', ['moment' => 'not a time'])->assertFailed();

        $this->assertFileDoesNotExist($this->path);
    }

    public function test_changing_the_clock_clears_the_cache(): void
    {
        // Cached entries and rate limits carry expiries from the old clock.
        Cache::put('kept-from-the-old-clock', 1, 600);

        $this->artisan('dev:clock', ['moment' => '2030-01-05 07:30'])->assertSuccessful();

        $this->assertNull(Cache::get('kept-from-the-old-clock'));
    }

    public function test_no_argument_reports_and_changes_nothing(): void
    {
        $this->artisan('dev:clock')->expectsOutputToContain('off')->assertSuccessful();
        $this->assertFileDoesNotExist($this->path);

        $this->artisan('dev:clock', ['moment' => '2030-01-05 07:30'])->assertSuccessful();
        $this->artisan('dev:clock')->expectsOutputToContain('2030-01-05 07:30')->assertSuccessful();
    }

    public function test_adb_moves_the_emulator_to_the_same_moment(): void
    {
        Process::fake();

        $this->artisan('dev:clock', ['moment' => '2030-01-05 07:30:00', '--adb' => 'emulator-5556'])
            ->assertSuccessful();

        $epoch = Carbon::parse('2030-01-05 07:30:00', 'Asia/Damascus')->getTimestamp();

        Process::assertRan(fn ($process) => $this->ran($process, ['-s', 'emulator-5556', 'shell', 'settings', 'put', 'global', 'auto_time', '0']));
        Process::assertRan(function ($process) use ($epoch) {
            $command = (array) $process->command;
            $at      = intdiv((int) end($command), 1000);

            return $this->ran($process, ['-s', 'emulator-5556', 'shell', 'cmd', 'alarm', 'set-time'])
                && abs($at - $epoch) <= 5;
        });

        // The alarm service took it: no root needed, so none asked for.
        Process::assertDidntRun(fn ($process) => $this->ran($process, ['-s', 'emulator-5556', 'shell', 'su']));
    }

    public function test_adb_falls_back_to_root_where_the_alarm_service_refuses(): void
    {
        Process::fake(fn ($process) => in_array('alarm', (array) $process->command, true)
            ? Process::result(output: 'java.lang.SecurityException: SET_TIME', exitCode: 0)
            : Process::result());

        $this->artisan('dev:clock', ['moment' => '2030-01-05 07:30', '--adb' => 'emulator-5556'])->assertSuccessful();

        Process::assertRan(fn ($process) => $this->ran($process, ['-s', 'emulator-5556', 'shell', 'su', '0', 'date']));
    }

    public function test_adb_reset_hands_the_emulator_back_to_the_network_time(): void
    {
        Process::fake();

        $this->artisan('dev:clock', ['--reset' => true, '--adb' => 'emulator-5556'])->assertSuccessful();

        Process::assertRan(fn ($process) => $this->ran($process, ['-s', 'emulator-5556', 'shell', 'settings', 'put', 'global', 'auto_time', '1']));
    }

    public function test_a_failing_adb_fails_the_command_but_keeps_the_server_clock(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'su: not found', exitCode: 1)]);

        $this->artisan('dev:clock', ['moment' => '2030-01-05 07:30', '--adb' => 'emulator-5556'])->assertFailed();

        $this->assertFileExists($this->path);
    }

    /** Whether the process's arguments, after the adb binary, start with $expected. */
    private function ran(object $process, array $expected): bool
    {
        $command = array_values((array) $process->command);

        return array_slice($command, 1, count($expected)) === $expected;
    }
}
