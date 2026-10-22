<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

/**
 * One employee's balance for one year — legacy's balancePayload(), as a value.
 *
 * - `allocated` — the row's allocation plus adjustment, or the company
 *   default when HR set none (`isCustom` false).
 * - `used` — approved, balance-deducting days of leave starting that year.
 * - `remaining` — never below zero; `overBalance` is non-zero only after a
 *   forced excuse pushed someone past their allocation.
 * - `nonDeducting` — approved days under a type that costs nothing (sick, a
 *   mission): shown so HR sees the whole picture, never counted.
 */
final readonly class BalanceFigures
{
    public function __construct(
        public int $year,
        public int $allocated,
        public int $adjustment,
        public int $used,
        public int $remaining,
        public int $overBalance,
        public int $nonDeducting,
        public bool $isCustom,
        public ?string $note,
    ) {}

    /**
     * @return array{year: int, allocated_days: int, adjustment_days: int, used_days: int, remaining_days: int, over_balance_days: int, non_deducting_days: int, is_custom: bool, note: ?string}
     */
    public function toArray(): array
    {
        return [
            'year'               => $this->year,
            'allocated_days'     => $this->allocated,
            'adjustment_days'    => $this->adjustment,
            'used_days'          => $this->used,
            'remaining_days'     => $this->remaining,
            'over_balance_days'  => $this->overBalance,
            'non_deducting_days' => $this->nonDeducting,
            'is_custom'          => $this->isCustom,
            'note'               => $this->note,
        ];
    }
}
