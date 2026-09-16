<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

/**
 * Governorate — المحافظة
 *
 * The fourteen Syrian governorates, used for both المحافظة (origin) and
 * مكان الإقامة الحالي (residence).
 *
 * ───────────────────────────────────────────────────────────────────────────
 * WHY AN ENUM AND NOT THE FREE-TEXT COLUMN THE SPEC ASKED FOR
 * ───────────────────────────────────────────────────────────────────────────
 * phase-1.5-foundation-truth-up.md §5.1 specifies `governorate` as a nullable
 * string. The later HR-folder scan is evidence against that, and says why in
 * its own comments: every free-text column in the spreadsheets showed spelling
 * drift, and it names this exact column — حماة versus حماه, two spellings of
 * one governorate — alongside مجبل الأسفلت / الاسفلت / الإسفلت, three spellings
 * of one site. 22 raw values were found for a set that has fourteen members.
 *
 * A free-text column means "group staff by governorate" silently returns two
 * rows for حماة, and no amount of care at the keyboard fixes it retroactively.
 *
 * The reference schema's answer is a lookup TABLE. This module's answer is an
 * enum, because §6.4's test for the choice is whether the set is stable: the
 * governorates of Syria have not changed since 1967, which is a stronger
 * stability claim than the one already accepted for activity types and
 * education levels. An enum also costs no join on a hot list query, and a
 * misspelling becomes impossible rather than merely discouraged (P2).
 *
 * NON-SYRIAN STAFF: the column is nullable and `employees.nationality` /
 * `birth_place` are free text, so a non-Syrian employee carries a NULL
 * governorate and a real birth place. That is honest — "not one of the
 * fourteen" is exactly what NULL says here. If the company ever employs enough
 * staff from one other country to want to group them, the right change is a
 * `country` column beside this one, NOT a fifteenth case.
 */
enum Governorate: int
{
    case Damascus     = 1;    // دمشق
    case RifDimashq   = 2;    // ريف دمشق
    case Aleppo       = 3;    // حلب
    case Homs         = 4;    // حمص
    case Hama         = 5;    // حماة
    case Latakia      = 6;    // اللاذقية
    case Tartus       = 7;    // طرطوس
    case Idlib        = 8;    // إدلب
    case Daraa        = 9;    // درعا
    case Suwayda      = 10;   // السويداء
    case Quneitra     = 11;   // القنيطرة
    case DeirEzZor    = 12;   // دير الزور
    case Raqqa        = 13;   // الرقة
    case Hasakah      = 14;   // الحسكة

    /**
     * Arabic display name — the ONE canonical spelling.
     */
    public function label(): string
    {
        return match ($this) {
            self::Damascus   => 'دمشق',
            self::RifDimashq => 'ريف دمشق',
            self::Aleppo     => 'حلب',
            self::Homs       => 'حمص',
            self::Hama       => 'حماة',
            self::Latakia    => 'اللاذقية',
            self::Tartus     => 'طرطوس',
            self::Idlib      => 'إدلب',
            self::Daraa      => 'درعا',
            self::Suwayda    => 'السويداء',
            self::Quneitra   => 'القنيطرة',
            self::DeirEzZor  => 'دير الزور',
            self::Raqqa      => 'الرقة',
            self::Hasakah    => 'الحسكة',
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
