<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Shared\Support\DevClock;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * The dev clock (App\Shared\Support\DevClock): move a LOCAL server's "now" to
 * run a scenario that would otherwise wait hours or days — a shift's close, a
 * guard's 72 hours — and, with --adb, an Android emulator's with it, since
 * the app draws its home card from the phone's clock.
 *
 *   php artisan dev:clock "2026-11-21 07:55"     an exact moment
 *   php artisan dev:clock "+3 days"              from where the clock is now
 *   php artisan dev:clock "tomorrow 08:05"
 *   php artisan dev:clock                        what the clock reads
 *   php artisan dev:clock --reset                the real clock again
 *
 * Moments are wall time in the attendance timezone. Refused outside a local
 * environment; and only a local boot reads the file at all.
 */
class MoveDevClock extends Command
{
    protected $signature = 'dev:clock
        {moment? : An exact moment (Y-m-d H:i) or a relative one ("+3 days", "tomorrow 08:05"), in the attendance timezone}
        {--reset : Return to the real clock}
        {--adb= : Also set this Android emulator\'s clock (its adb serial, e.g. emulator-5556)}';

    protected $description = 'LOCAL ONLY. Move the server\'s clock for manual test scenarios, or show or reset it.';

    public function handle(DevClock $clock, AttendanceCalendar $calendar): int
    {
        // 'testing' so the suite can run the command; a testing boot is never
        // moved (DevClock::boot).
        if (! $this->laravel->environment(['local', 'testing'])) {
            $this->error('dev:clock runs in a local environment only (APP_ENV=local). This one is "'.$this->laravel->environment().'".');

            return self::FAILURE;
        }

        $timezone = $calendar->timezone();
        $moment   = $this->argument('moment');

        if ($this->option('reset')) {
            $clock->reset();
            Cache::flush();
            $this->info('The dev clock is off. Server time: '.$this->reads($clock, $timezone).'.');

            return $this->moveEmulator($clock, automatic: true);
        }

        if ($moment === null) {
            $this->report($clock, $timezone);

            return $this->option('adb') ? $this->moveEmulator($clock, automatic: false) : self::SUCCESS;
        }

        // Relative moments count from the moved clock, not the real one, so
        // "+1 day" twice is two days.
        $from = $clock->now($timezone);

        try {
            $target = Carbon::withTestNow($from, fn () => Carbon::parse((string) $moment, $timezone));
        } catch (Throwable) {
            $this->error("Cannot read \"{$moment}\" as a moment. Try \"2026-11-21 07:55\", \"+3 days\" or \"tomorrow 08:05\".");

            return self::FAILURE;
        }

        $clock->moveTo($target);

        // Cached entries and rate limits carry expiries from the old clock.
        Cache::flush();

        $this->report($clock, $timezone);

        if ($target->lt($from)) {
            $this->warn('The clock moved BACK: records already written are now in its future. Prefer a fresh database (migrate:fresh --seed) for an earlier date.');
        }

        $this->line('A queue worker or schedule:work started before this keeps its old clock until restarted.');

        return $this->moveEmulator($clock, automatic: false);
    }

    private function report(DevClock $clock, string $timezone): void
    {
        if (! $clock->isOn()) {
            $this->info('The dev clock is off. Server time: '.$this->reads($clock, $timezone).'.');

            return;
        }

        $offset = $clock->offsetSeconds();
        $real   = Carbon::now()->locale('en');
        $by     = $real->copy()->subSeconds(abs($offset))->diffForHumans($real, ['syntax' => Carbon::DIFF_ABSOLUTE, 'parts' => 3]);

        $this->info('The dev clock is on. Server time: '.$this->reads($clock, $timezone).' — '.$by.($offset > 0 ? ' ahead of' : ' behind').' the real clock.');
    }

    private function reads(DevClock $clock, string $timezone): string
    {
        return $clock->now($timezone)->format('Y-m-d H:i:s').' ('.$timezone.')';
    }

    /**
     * Set the emulator named by --adb to the moved clock's moment (or, on a
     * reset, back to the real one with automatic time on).
     */
    private function moveEmulator(DevClock $clock, bool $automatic): int
    {
        $serial = $this->option('adb');

        if (! $serial) {
            return self::SUCCESS;
        }

        $adb   = [$this->adb(), '-s', (string) $serial, 'shell'];
        $epoch = time() + $clock->offsetSeconds();

        // The alarm service's own command needs no root, so it works on a
        // "Google Play" image too; `su … date` is for an image without it.
        $setTime = [[...$adb, 'cmd', 'alarm', 'set-time', $epoch.'000'], [...$adb, 'su', '0', 'date', '@'.$epoch]];
        $auto    = [[...$adb, 'settings', 'put', 'global', 'auto_time', $automatic ? '1' : '0']];

        // Automatic time off BEFORE the move, or the emulator puts the real
        // time back; on again only after the real time is restored.
        foreach ($automatic ? [$setTime, $auto] : [$auto, $setTime] as $alternatives) {
            $failure = '';

            foreach ($alternatives as $step) {
                $result = Process::timeout(20)->run($step);

                // adb exits 0 for some refusals and says so on the output.
                $said = trim($result->errorOutput().' '.$result->output());

                if ($result->successful() && ! preg_match('/exception|not found|inaccessible|denied/i', $said)) {
                    continue 2;
                }

                $failure = $said;
            }

            $this->error('The emulator\'s clock was NOT set ('.$failure.').');
            $this->line('The server\'s clock is as reported above. Set the device by hand: Settings → System → Date & time, automatic off.');

            return self::FAILURE;
        }

        $this->info("Emulator {$serial} set to the same moment".($automatic ? ', automatic time on.' : ', automatic time off.'));

        return self::SUCCESS;
    }

    private function adb(): string
    {
        if (filled(config('dev_clock.adb'))) {
            return (string) config('dev_clock.adb');
        }

        $name = PHP_OS_FAMILY === 'Windows' ? 'adb.exe' : 'adb';
        $sdks = array_filter([
            getenv('ANDROID_HOME') ?: null,
            getenv('ANDROID_SDK_ROOT') ?: null,
            getenv('LOCALAPPDATA') ? getenv('LOCALAPPDATA').'/Android/Sdk' : null,
            getenv('HOME') ? getenv('HOME').'/Android/Sdk' : null,
            getenv('HOME') ? getenv('HOME').'/Library/Android/sdk' : null,
        ]);

        foreach ($sdks as $sdk) {
            if (is_file($sdk.'/platform-tools/'.$name)) {
                return $sdk.'/platform-tools/'.$name;
            }
        }

        return 'adb';
    }
}
