<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

/**
 * EducationLevel — المرحلة الدراسية / المؤهل العلمي
 *
 * The thirteen rungs the company recognises, from "no schooling" to a
 * doctorate. Unlike MaritalStatus these ARE a scale: the order is load-bearing
 * and a pay criterion (قيمة المؤهل العلمي) is attached to each rung.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * THE ORDER, AND WHY IT IS NOT THE ORDER THE SOURCES LIST
 * ───────────────────────────────────────────────────────────────────────────
 * Two company artefacts order these rungs differently, and one of them makes
 * the scale non-monotonic in its own pay value:
 *
 *   The reference schema's `education_stages.rank_order` puts معهد at rank 4,
 *   directly after بكالوريا and BEFORE سنة 1. Read against the pay matrix in
 *   the same file (ورقة "نظام الرواتب") the values then run
 *   0, 0, 0, 35, 60, 35, 35, 60, 100, 100, 100, 135, 175 — معهد (60) outranks
 *   سنة 1 (35) while sorting below it.
 *
 *   The pay matrix itself lists the rungs in a different order, and in THAT
 *   order the values run 0, 0, 0, 35, 35, 35, 60, 60, 100, 100, 100, 135, 175
 *   — monotonically non-decreasing.
 *
 * This enum takes the pay matrix's order, because the matrix is the artefact
 * that actually attaches money to the rungs and it is internally consistent.
 * The practical consequence: معهد sits ABOVE سنة 2 and level with سنة 3, which
 * is also how the company pays them.
 *
 * ⚠️ THE INT IS AN ORDER, NOT AN AMOUNT. `EducationLevel::Institute->value` is
 * 7; the money attached to معهد is 60 × the company scale factor. Never compute
 * pay from the case value, and never assume a fixed gap between rungs — سنة 4,
 * سنة 5 and كلية are three distinct rungs carrying one identical amount. The
 * amounts live in the versioned, effective-dated rate table that arrives with
 * payroll (phase-1.5-foundation-truth-up.md §6.4); keeping them out of here is
 * what makes a pay-scale revision a data change rather than a redeploy (P4).
 *
 * Comparisons that ARE legitimate: "at least a degree" as
 * `$level->value >= EducationLevel::College->value`, and sorting a staff list
 * by attainment.
 */
enum EducationLevel: int
{
    case None           = 1;   // لا يوجد
    case Primary        = 2;   // ابتدائي
    case NinthGrade     = 3;   // تاسع
    case Baccalaureate  = 4;   // بكالوريا
    case UniversityYear1 = 5;  // سنة 1
    case UniversityYear2 = 6;  // سنة 2
    case Institute      = 7;   // معهد
    case UniversityYear3 = 8;  // سنة 3
    case UniversityYear4 = 9;  // سنة 4
    case UniversityYear5 = 10; // سنة 5
    case College        = 11;  // كلية
    case Masters        = 12;  // ماجستير
    case Doctorate      = 13;  // دكتوراه

    /**
     * Arabic display name.
     */
    public function label(): string
    {
        return match ($this) {
            self::None            => 'لا يوجد',
            self::Primary         => 'ابتدائي',
            self::NinthGrade      => 'تاسع',
            self::Baccalaureate   => 'بكالوريا',
            self::UniversityYear1 => 'سنة 1',
            self::UniversityYear2 => 'سنة 2',
            self::Institute       => 'معهد',
            self::UniversityYear3 => 'سنة 3',
            self::UniversityYear4 => 'سنة 4',
            self::UniversityYear5 => 'سنة 5',
            self::College         => 'كلية',
            self::Masters         => 'ماجستير',
            self::Doctorate       => 'دكتوراه',
        };
    }

    public static function isDefined(int $value): bool
    {
        return self::tryFrom($value) instanceof self;
    }

    /**
     * All rungs, lowest attainment first.
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
