<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

/**
 * Gender — الجنس
 *
 * Int-backed (C26): `employees.gender` stores the int, this is the lens over
 * it. The Arabic label lives here and nowhere else, so no client and no lookup
 * table carries a second copy of it.
 */
enum Gender: int
{
    case Male   = 1;
    case Female = 2;

    /**
     * Arabic display name.
     */
    public function label(): string
    {
        return match ($this) {
            self::Male   => 'ذكر',
            self::Female => 'أنثى',
        };
    }

    /**
     * Whether an arbitrary int maps to a defined case — used by the guards,
     * mirroring DepartmentLevel::isDefined().
     */
    public static function isDefined(int $value): bool
    {
        return self::tryFrom($value) instanceof self;
    }

    /**
     * For select inputs and API metadata.
     *
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
