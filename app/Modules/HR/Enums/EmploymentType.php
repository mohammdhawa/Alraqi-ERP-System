<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

/**
 * EmploymentType — الحالة (نوع التوظيف)
 *
 * On what footing this person is employed: متعاقد / يحتاج تثبيت / جماعة / مثبت.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * WHY THIS COLUMN EXISTS AT ALL — it corrects a conflation
 * ───────────────────────────────────────────────────────────────────────────
 * START_HERE.md §14 maps the workbook's الحالة column onto EmployeeStatus:
 * "متعاقد / إنهاء عمل -> status = active / terminated". That reading was taken
 * from a sheet where الحالة happened to hold only those two values.
 *
 * The later HR-folder scan shows the same field carrying FOUR values —
 * متعاقد، يحتاج تثبيت، جماعة، مثبت — and none of the other three is an
 * employment state. They are tenure categories: how the person is engaged, not
 * whether they are engaged. A مثبت employee and a متعاقد employee are both
 * simply active.
 *
 * So the one column was two facts, and the old mapping silently picked one of
 * them. They are separated here: EmployeeStatus answers "does this person work
 * here", EmploymentType answers "on what basis". Conflating them would have
 * made "list everyone awaiting confirmation" unanswerable, and would have put a
 * tenure change and a termination through the same field.
 *
 * WHY it is nullable: the workbook rows that carry only متعاقد / إنهاء عمل give
 * no footing for the terminated staff, and inventing one during the Batch 4
 * import would be fabricating data. NULL means "not recorded", which is the
 * truth about those rows.
 */
enum EmploymentType: int
{
    case Contracted        = 1;   // متعاقد
    case AwaitingTenure    = 2;   // يحتاج تثبيت
    case Collective        = 3;   // جماعة — عمالة جماعية
    case Tenured           = 4;   // مثبت

    /**
     * Arabic display name.
     */
    public function label(): string
    {
        return match ($this) {
            self::Contracted     => 'متعاقد',
            self::AwaitingTenure => 'يحتاج تثبيت',
            self::Collective     => 'جماعة',
            self::Tenured        => 'مثبت',
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
