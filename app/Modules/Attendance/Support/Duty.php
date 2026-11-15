<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A run of back-to-back expected shifts, each ending exactly when the next
 * begins — at most three (work shifts spec §2). One check-in and one
 * check-out belong to a duty: an office day is a duty of one shift, a
 * guard's 72 hours a duty of three.
 *
 * Its check-in window runs from `opensAt` (the start less the opening
 * margin) to its end; it closes — an open check-in becomes a missing
 * check-out — at `closesAt` (the end plus the close margin).
 */
final readonly class Duty
{
    /** @param  list<ExpectedShift>  $shifts */
    public function __construct(
        public array $shifts,
        public CarbonImmutable $opensAt,
        public CarbonImmutable $closesAt,
    ) {}

    /**
     * The window opens by its first shift's rules and closes by its last's
     * (per-shift rules spec §3.3).
     *
     * @param  list<ExpectedShift>  $shifts
     */
    public static function of(array $shifts): self
    {
        $first = $shifts[0];
        $last = $shifts[array_key_last($shifts)];

        return new self(
            $shifts,
            $first->startsAt->subMinutes($first->rules()->checkInOpensMinutes),
            $last->endsAt->addMinutes($last->rules()->checkOutClosesMinutes),
        );
    }

    /**
     * Group consecutive expected shifts into duties: a shift joins the
     * previous one when it starts exactly as that one ends, up to three. The
     * one definition of a duty — the resolver and the reports both use it.
     *
     * @param  list<ExpectedShift>  $shifts  in date order
     * @return list<self>
     */
    public static function runs(array $shifts): array
    {
        $duties = [];
        $run = [];
        foreach ($shifts as $shift) {
            $last = $run === [] ? null : $run[array_key_last($run)];
            $joins = $last !== null
                && $last->endsAt->equalTo($shift->startsAt) && count($run) < self::MAX_SHIFTS;

            if ($run !== [] && ! $joins) {
                $duties[] = self::of($run);
                $run = [];
            }
            $run[] = $shift;
        }
        if ($run !== []) {
            $duties[] = self::of($run);
        }

        return $duties;
    }

    public const MAX_SHIFTS = 3;

    /** The date the duty's records carry: its first shift's. */
    public function shiftDate(): string
    {
        return $this->shifts[0]->date;
    }

    public function startsAt(): CarbonImmutable
    {
        return $this->shifts[0]->startsAt;
    }

    public function endsAt(): CarbonImmutable
    {
        return $this->shifts[array_key_last($this->shifts)]->endsAt;
    }

    /** Whether a check-in at $moment is for this duty. */
    public function holds(CarbonInterface $moment): bool
    {
        return $moment->gte($this->opensAt) && $moment->lt($this->endsAt());
    }

    /** The shift $moment falls in; the first when it is before the start. */
    public function shiftOn(CarbonInterface $moment): ExpectedShift
    {
        foreach ($this->shifts as $shift) {
            if ($moment->gte($shift->startsAt) && $moment->lt($shift->endsAt)) {
                return $shift;
            }
        }

        return $moment->gte($this->endsAt()) ? $this->shifts[array_key_last($this->shifts)] : $this->shifts[0];
    }
}
