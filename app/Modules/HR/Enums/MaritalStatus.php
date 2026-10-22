<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

/**
 * MaritalStatus — الحالة الاجتماعية
 *
 * The four values the intake form offers (XLSForm `a4`): عازب / متزوج / مطلق /
 * أرمل.
 *
 * WHY the order is NOT meaningful here, unlike EducationLevel: these are
 * nominal categories, not a scale. Nothing may compare them with `>`. The one
 * rule attached to them is structural rather than ordinal — `wives_count` is
 * only meaningful for Married — and it is enforced by
 * EmployeeClassificationGuard, not inferred from the int.
 */
enum MaritalStatus: int
{
    case Single   = 1;
    case Married  = 2;
    case Divorced = 3;
    case Widowed  = 4;

    /**
     * Arabic display name.
     */
    public function label(): string
    {
        return match ($this) {
            self::Single   => 'عازب',
            self::Married  => 'متزوج',
            self::Divorced => 'مطلق',
            self::Widowed  => 'أرمل',
        };
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
