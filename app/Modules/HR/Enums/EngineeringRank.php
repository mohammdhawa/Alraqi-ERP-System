<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

/**
 * EngineeringRank — الرتبة الهندسية
 *
 * The twelve rungs of the engineering ladder as approved on 2026-10-14 (work
 * order §8): لا يوجد، متدرب، مراقب فني، مساعد مهندس، مشارك، مشارك1–4، ممارس،
 * رأي، استشاري — the list «نظام الرواتب» prices. مشارك1–4 are ranks of their
 * own, between مشارك and ممارس (150, then 165, 180, 195, 210, then 225 before
 * the company scale factor); none may be folded into another. ركن is not a
 * rank; migration 2026_10_14_100000 moves any row that held one.
 *
 * ⚙️ THE INT IS AN IDENTITY, NOT AN ORDER (changed 2026-10-14). It used to be
 * both — the rungs were numbered in pay order, so `>=` read as "at least this
 * rank". Adding four rungs between مشارك (5) and ممارس (6) would have meant
 * renumbering ممارس، رأي and استشاري, which every stored row, every audit-log
 * value and the dashboard's form all carry. So the existing ranks keep their
 * ints, مشارك1–4 are 10–13, and THE PAY ORDER IS THE DECLARATION ORDER below:
 * cases() and options() list the ladder lowest first. Nothing compares ranks by
 * int — do not start: compare positions in cases() if "higher than" is needed.
 *
 * Never compute pay from the case value either. The amounts belong to the
 * payroll rate table.
 *
 * APPLIES ONLY to `activity_type = Engineering`; EmployeeClassificationGuard
 * refuses to store a rank on any other kind of post. `None` (لا يوجد) is a real
 * rung rather than a null: it is what an engineering post whose holder has not
 * been ranked yet carries, and the matrix prices it at zero explicitly.
 */
enum EngineeringRank: int
{
    // Declared lowest-paid first — see the docblock. 9 (ركن) is retired and
    // must not be reused: an old audit row holding it has to read as unknown,
    // not as some other rank.
    case None              = 1;    // لا يوجد
    case Trainee           = 2;    // متدرب
    case TechnicalMonitor  = 3;    // مراقب فني
    case AssistantEngineer = 4;    // مساعد مهندس
    case Associate         = 5;    // مشارك
    case Associate1        = 10;   // مشارك1
    case Associate2        = 11;   // مشارك2
    case Associate3        = 12;   // مشارك3
    case Associate4        = 13;   // مشارك4
    case Practitioner      = 6;    // ممارس
    case Authority         = 7;    // رأي
    case Consultant        = 8;    // استشاري

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
            self::Associate1        => 'مشارك1',
            self::Associate2        => 'مشارك2',
            self::Associate3        => 'مشارك3',
            self::Associate4        => 'مشارك4',
            self::Practitioner      => 'ممارس',
            self::Authority         => 'رأي',
            self::Consultant        => 'استشاري',
        };
    }

    public static function isDefined(int $value): bool
    {
        return self::tryFrom($value) instanceof self;
    }

    /**
     * All rungs, lowest first (declaration order, not value order).
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
