<?php

declare(strict_types=1);

namespace App\Modules\Departments\Enums;

/**
 * Sector — الجهة
 *
 * Which of the company's two groupings a division belongs to. Slide 1 of the
 * org chart does not lay the thirteen divisions out in one row: it draws them
 * in two boxes.
 *
 *   المكاتب الإدارية (8)  the functions that serve the company itself — finance,
 *                          HR, administration, PR, marketing/IT, security,
 *                          registry, legal.
 *   الأجسام الإدارية (5)   the bodies that execute the actual construction
 *                          business — engineering, production, heavy equipment,
 *                          supply, real-estate marketing.
 *
 * WHY an attribute and NOT a tier (ratified decision #1,
 * docs/phase-1.5-foundation-truth-up.md §2):
 * - As a tier it would need two invented rows — "المكاتب الإدارية" and
 *   "الأجسام الإدارية" — that exist in no org chart, have no manager and no
 *   staff, and it would push every division down a level, taking the tree to
 *   five deep. Decision #6 fixes the depth at four.
 * - As an attribute it is one nullable int on the rows that actually answer
 *   the question, and the tree is untouched.
 *
 * WHY a PHP enum over an int column, and not a SQL ENUM or a lookup table:
 * the same reasoning as DepartmentLevel, which this deliberately mirrors line
 * for line — `departments.sector` stores the int and nothing else, and this is
 * a lens over it rather than a second copy of the fact.
 *
 * WHERE THE VALUE LIVES — this is the P1 part, and it is not symmetrical with
 * `level`. Only level-2 (إدارة) rows carry a value; the root, the governance
 * units, the sections and the offices all carry NULL and resolve their sector
 * by walking UP to the nearest ancestor that has one
 * (Department::sector()). Copying it downward is the exact drift P1 exists to
 * prevent: re-parent a section and the copy would still name the old sector,
 * and — because the walk stops at the first non-null ancestor — it would hide
 * the truth from everything beneath it too. DepartmentHierarchyGuard refuses
 * the write rather than trusting anyone to remember.
 */
enum Sector: int
{
    case AdministrativeOffices = 1;
    case AdministrativeBodies  = 2;

    /**
     * Arabic display name for the sector.
     */
    public function label(): string
    {
        return match ($this) {
            self::AdministrativeOffices => 'المكاتب الإدارية',
            self::AdministrativeBodies  => 'الأجسام الإدارية',
        };
    }

    /**
     * The tier that is allowed to declare a sector. Every other row inherits.
     *
     * Stated here rather than as a literal `2` in the guard so the rule and the
     * enum that expresses it cannot drift apart.
     */
    public static function declaredAt(): DepartmentLevel
    {
        return DepartmentLevel::Division;
    }

    /**
     * Whether an arbitrary int maps to a defined sector — used by the guard to
     * reject a value the enum does not know, the same way DepartmentLevel does.
     */
    public static function isDefined(int $sector): bool
    {
        return self::tryFrom($sector) instanceof self;
    }

    /**
     * Both sectors — for select inputs and API metadata.
     *
     * @return array<int, array{value: int, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $sector): array => [
                'value' => $sector->value,
                'label' => $sector->label(),
            ],
            self::cases(),
        );
    }
}
