<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

/**
 * ActivityType — نوع النشاط
 *
 * Which kind of work a post is, and therefore which pay criteria apply to the
 * person holding it: هندسي / صناعي / إداري / مالي / أمني.
 *
 * This is the axis SELECTOR. Two of the classification columns on `employees`
 * are meaningless unless the activity type says otherwise, and
 * EmployeeClassificationGuard refuses to store them when it does not:
 *
 *   Engineering  -> `engineering_rank` (الرتبة الهندسية) applies
 *   Industrial   -> `industrial_experience_years` (سنوات الخبرة الصناعية) applies
 *
 * ───────────────────────────────────────────────────────────────────────────
 * ⚠️ WHAT THE TWO SOURCES DISAGREE ABOUT — read before tightening this
 * ───────────────────────────────────────────────────────────────────────────
 * phase-1.5-foundation-truth-up.md §5.3 states that the three experience axes
 * are "mutually exclusive by activity_type", with `experience_level` applying
 * ONLY to administrative / financial / security staff.
 *
 * The later scan of the HR folder contradicts the second half of that. Its
 * pay matrix (ورقة "نظام الرواتب") carries TWO experience rate tables, and the
 * split between them is not "has experience" versus "has none" — it is which
 * group you are in:
 *
 *   إداري / مالي / أمني   خبرة 1..5  ->  150, 100, 75, 50, 0
 *   هندسي / صناعي          1..5       ->  300, 200, 100, 70, 0
 *
 * An engineering row therefore carries an experience level too, valued from a
 * different table — and it carries an engineering rank ON TOP, as a separate
 * criterion. So `experience_level` is NOT exclusive to the administrative
 * group.
 *
 * The guard enforces only the half both sources agree on. Making
 * `experience_level` administrative-only would have rejected genuine
 * engineering rows at import time, which is an expensive way to discover that a
 * spec sentence was an inference from an older workbook.
 *
 * WHAT STILL NEEDS AN ANSWER FROM HR, before the Batch 4 import: whether an
 * engineering employee's pay reads the numeric experience table, the
 * engineering-rank table, or both added together. That decides the payroll
 * calculator, not this schema — every input it could need is stored either way.
 */
enum ActivityType: int
{
    case Engineering    = 1;   // هندسي
    case Industrial     = 2;   // صناعي
    case Administrative = 3;   // إداري
    case Financial      = 4;   // مالي
    case Security       = 5;   // أمني

    /**
     * Arabic display name.
     */
    public function label(): string
    {
        return match ($this) {
            self::Engineering    => 'هندسي',
            self::Industrial     => 'صناعي',
            self::Administrative => 'إداري',
            self::Financial      => 'مالي',
            self::Security       => 'أمني',
        };
    }

    /**
     * Whether a post of this kind may carry الرتبة الهندسية.
     *
     * Stated here rather than as a literal comparison in the guard, so the rule
     * and the enum that expresses it cannot drift apart — the same reasoning
     * that put Sector::declaredAt() on the Sector enum.
     */
    public function usesEngineeringRank(): bool
    {
        return $this === self::Engineering;
    }

    /**
     * Whether a post of this kind may carry سنوات الخبرة الصناعية.
     */
    public function usesIndustrialExperience(): bool
    {
        return $this === self::Industrial;
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
