<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * The dev clock — a local environment's "now", moved for hand-run scenarios
 * that cannot wait for a shift to end or a guard's three days to pass
 * (`php artisan dev:clock`).
 *
 * It is an OFFSET from the real clock, kept in a file, not a frozen moment:
 * time keeps running from wherever it was moved to, in every process that
 * boots — each request, each scheduled command, a queue worker.
 *
 * ONLY A LOCAL ENVIRONMENT IS MOVED. boot() does nothing anywhere else, so a
 * file left behind, or copied to a server, changes nothing there; and the
 * test suite keeps its own clock (Carbon::setTestNow, travelTo) whatever a
 * developer has set for their running server.
 *
 * It moves Carbon, which is every "now" the application reads. It does not
 * move PHP's own time() or the database's NOW(); nothing in app/ reads either.
 */
final class DevClock
{
    public function __construct(private readonly string $path) {}

    /** Whether this environment may have its clock moved at all. */
    public static function movesIn(string $environment): bool
    {
        return $environment === 'local';
    }

    /** Called once as the application boots. */
    public function boot(string $environment): void
    {
        if (! self::movesIn($environment)) {
            return;
        }

        $offset = $this->offsetSeconds();

        if ($offset === 0) {
            return;
        }

        // A closure, so the moved clock ticks: Carbon hands it the real now
        // each time one is asked for.
        Carbon::setTestNow(static fn (CarbonInterface $real): CarbonInterface => $real->addSeconds($offset));
    }

    /** Seconds the moved clock runs ahead of the real one (negative: behind). 0 when off. */
    public function offsetSeconds(): int
    {
        if (! is_file($this->path)) {
            return 0;
        }

        $stored = json_decode((string) file_get_contents($this->path), true);

        return is_array($stored) ? (int) ($stored['offset_seconds'] ?? 0) : 0;
    }

    public function isOn(): bool
    {
        return $this->offsetSeconds() !== 0;
    }

    /** The moved clock's present moment, from the real clock and the stored offset. */
    public function now(string $timezone): Carbon
    {
        return Carbon::createFromTimestamp(time() + $this->offsetSeconds(), $timezone);
    }

    /** Move the clock so that it reads $moment now. */
    public function moveTo(CarbonInterface $moment): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($this->path, json_encode([
            'offset_seconds' => $moment->getTimestamp() - time(),
            'set_at'         => date(DATE_ATOM),
        ], JSON_PRETTY_PRINT));
    }

    /** Back to the real clock. */
    public function reset(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }
}
