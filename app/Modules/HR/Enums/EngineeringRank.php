<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

/**
 * EngineeringRank — الرتبة الهندسية
 *
 * The nine rungs of the engineering ladder, from unranked to ركن.
 *
 * ORDER: ascending by the amount the pay matrix attaches to each rung —
 * 0, 25, 100, 125, 150, 225, 300, 400, 1000 (before the company scale factor).
 * That makes `->value` a legitimate ordering key and `>=` comparisons
 * meaningful, the same property EducationLevel has and for the same reason. The
 * reference schema lists these descending; the direction is cosmetic there and
 * load-bearing here.
 *
 * ⚠️ As with EducationLevel, THE INT IS AN ORDER, NOT AN AMOUNT — and the gaps
 * here are enormous (ركن is worth two and a half times استشاري). Never compute
 * pay from the case value. The amounts belong to the payroll rate table.
 *
 * APPLIES ONLY to `activity_type = Engineering`; EmployeeClassificationGuard
 * refuses to store a rank on any other kind of post. `None` (لا يوجد) is a real
 * rung rather than a null: it is what an engineering post whose holder has not
 * been ranked yet carries, and the matrix prices it at zero explicitly.
 */
enum EngineeringRank: int
{
    case None              = 1;   // لا يوجد
    case Trainee           = 2;   // متدرب
    case TechnicalMonitor  = 3;   // مراقب فني
    case AssistantEngineer = 4;   // مساعد مهندس
    case Associate         = 5;   // مشارك
    case Practitioner      = 6;   // ممارس
    case Authority         = 7;   // رأي
    case Consultant        = 8;   // استشاري
    case Principal         = 9;   // ركن

    /**
     * Arabic display name.
     */
    public function label(): string
    {
        return match ($this) {
            self::None              => 'لا يوجد',
            self::Trainee           => 'متدرب',
            self::TechnicalMonitor  => 'مراقب فني',
            self::AssistantEngineer => 'مساعد مهندس',
            self::Associate         => 'مشارك',
            self::Practitioner      => 'ممارس',
            self::Authority         => 'رأي',
            self::Consultant        => 'استشاري',
            self::Principal         => 'ركن',
        };
    }

    public static function isDefined(int $value): bool
    {
        return self::tryFrom($value) instanceof self;
    }

    /**
     * All rungs, lowest first.
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
