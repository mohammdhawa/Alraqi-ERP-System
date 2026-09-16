<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

/**
 * SalaryMode — نظام رواتب / إتفاق
 *
 * Which of the company's two pay tracks a person is on, and therefore whether
 * their salary is a CALCULATION or a NUMBER.
 *
 *   Scale  (نظام رواتب) — the majority. Pay is the sum of the criteria this
 *                         module stores (activity type, experience, education,
 *                         organizational grade, seniority, the four flags …)
 *                         multiplied by the company scale factor. There is no
 *                         stored amount, and `agreed_salary` must be NULL.
 *
 *   Agreed (إتفاق)      — a flat negotiated figure that bypasses the
 *                         calculation entirely. `agreed_salary` is required.
 *
 * WHY `Agreed` IS A FIRST-CLASS TRACK AND NOT A SPECIAL CASE: this is called
 * out in both START_HERE.md §8 and the Phase 1.5 spec as something the original
 * design document got wrong. A calculator that treats إتفاق as "scale, but
 * override the total afterwards" will eventually recompute over it — during a
 * scale-factor revision, say — and quietly repay someone the wrong amount.
 * The mode is read BEFORE any criterion is, not after.
 *
 * The pairing rule (agreed => amount required; scale => amount must be NULL) is
 * enforced by EmployeeClassificationGuard, so it binds the seeder and the
 * importer and not only the HTTP front door (P2).
 */
enum SalaryMode: int
{
    case Scale  = 1;   // نظام رواتب
    case Agreed = 2;   // إتفاق

    /**
     * Arabic display name.
     */
    public function label(): string
    {
        return match ($this) {
            self::Scale  => 'نظام رواتب',
            self::Agreed => 'إتفاق',
        };
    }

    /**
     * Whether this track stores an explicit amount on the employee row.
     *
     * The one definition of the pairing rule, read by the guard so the rule and
     * the enum cannot drift.
     */
    public function requiresAgreedSalary(): bool
    {
        return $this === self::Agreed;
    }

    public static function isDefined(int $value): bool
    {
        return self::tryFrom($value) instanceof self;
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
