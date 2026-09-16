<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

/**
 * EmployeeStatus — حالة الموظف
 *
 * Whether this person is employed here right now. Three states, deliberately
 * coarse.
 *
 * ⚙️ THIS REPLACES A SQL ENUM. `employees.status` was
 * `enum('active','inactive','terminated')` plus a `Rule::in` — recorded in
 * API_BUILD_STANDARD.md §12 under "deliberately not done" with the note that
 * the employees rebuild is when to fix it (C26). This is that rebuild. The
 * column is now an indexed unsignedTinyInteger and this enum is the lens.
 *
 * ⚠️ WIRE CONTRACT CHANGE: `status` was the string "active" and is now the int
 * 1, with `status_label` alongside it — matching how `level` / `level_label`
 * already ship on departments. Batch 2 is a contract-changing release anyway
 * (the name split alone forces every client to update), so this rides along
 * rather than being deferred into a second break later.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * WHY `Suspended` AND NOT `Inactive` — this closes an open question
 * ───────────────────────────────────────────────────────────────────────────
 * START_HERE.md §11 question 2 asks whether the company distinguishes
 * suspended / on-loan / seconded staff, "which the existing `inactive` case
 * could carry". It was open because the workbook showed only متعاقد and
 * إنهاء عمل.
 *
 * The later HR-folder scan answers it: its employee status list is
 * نشط / موقوف / منتهي الخدمة / مستقيل — موقوف (suspended) is a state the
 * company really does track. The vague `inactive` is renamed to the thing it
 * was always standing in for.
 *
 * WHY مستقيل (resigned) is NOT a fourth case: resigned and terminated are the
 * same employment state reached for different reasons, and the reason belongs
 * to the separation record (whose own `separation_type` already enumerates
 * إنهاء عمل / استقالة / انتهاء عقد / فصل / وفاة). Storing it twice is P1
 * violated, and the copy on `employees` is the one that would go stale.
 */
enum EmployeeStatus: int
{
    case Active     = 1;   // نشط — على رأس عمله
    case Suspended  = 2;   // موقوف — موظف لكنه متوقف مؤقتاً
    case Terminated = 3;   // منتهي الخدمة

    /**
     * Arabic display name.
     */
    public function label(): string
    {
        return match ($this) {
            self::Active     => 'نشط',
            self::Suspended  => 'موقوف',
            self::Terminated => 'منتهي الخدمة',
        };
    }

    /**
     * Whether this state means the person still works here.
     *
     * The one place "still employed" is defined. Reports, payroll eligibility
     * and headcount all read it rather than each re-deciding which cases count.
     */
    public function isEmployed(): bool
    {
        return $this !== self::Terminated;
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
