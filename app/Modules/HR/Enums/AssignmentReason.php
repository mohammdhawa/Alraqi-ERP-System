<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

/**
 * AssignmentReason — سبب التكليف
 *
 * Why a posting row exists: what event moved this person into this
 * department / title / grade on this date.
 *
 * `Hire` is structurally special — it is the reason carried by the FIRST row of
 * every employee's history, the one that replaces the workbook's three frozen
 * `بداية التعيين` columns. `Employee::recordPostingChange()` writes it
 * automatically when an employee first lands in a unit; every later row states
 * an actual decision.
 *
 * WHY the reason lives on the assignment and not on the employee: it describes
 * one transition, not the person. An employee promoted in March and transferred
 * in July has two facts, and a column on `employees` can hold one of them.
 */
enum AssignmentReason: int
{
    case Hire        = 1;   // تعيين
    case Transfer    = 2;   // نقل داخلي
    case Promotion   = 3;   // ترقية
    case TitleChange = 4;   // تعديل مسمى وظيفي
    case Restructure = 5;   // إعادة هيكلة
    case Termination = 6;   // إنهاء عمل

    /**
     * Arabic display name.
     */
    public function label(): string
    {
        return match ($this) {
            self::Hire        => 'تعيين',
            self::Transfer    => 'نقل داخلي',
            self::Promotion   => 'ترقية',
            self::TitleChange => 'تعديل مسمى وظيفي',
            self::Restructure => 'إعادة هيكلة',
            self::Termination => 'إنهاء عمل',
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
