<?php

declare(strict_types=1);

namespace App\Modules\Departments\Enums;

/**
 * DepartmentLevel
 *
 * The tier of an organizational unit in the departments tree:
 *
 *   الإدارة العامة (GeneralAdministration, 1) -> إدارة (Division, 2) -> قسم (Section, 3) -> [future tiers…]
 *
 * WHY a PHP enum and NOT a database column:
 * - `departments.level` stores the int and nothing else. This enum is a lens
 *   over that int, not a second copy of the fact. Two columns describing one
 *   fact (level + a `type`/`tier` string) drift apart the moment someone
 *   writes one without the other.
 * - Adding a tier is a ONE-LINE change here — no migration, no ALTER, no
 *   schema change, no downtime:
 *
 *       case Branch = 4;
 *
 *   That property is the entire reason `level` is an int rather than a SQL
 *   ENUM('division','section'). Preserve it.
 *
 * WHY it doubles as the registry of valid depth:
 * - A department's level MUST resolve to a case here. `tryFrom()` returning
 *   null is what validation uses to reject a row at an undefined depth, so
 *   the set of cases below IS the maximum depth of the tree. There is no
 *   separate max-depth constant to keep in sync.
 *
 * Keep per-tier rules in this enum. Scattering "what sits under a division"
 * across requests, services, and views is how hierarchy bugs start.
 */
enum DepartmentLevel: int
{
    case GeneralAdministration = 1;
    case Division              = 2;
    case Section               = 3;

    /**
     * Arabic display name for the tier.
     */
    public function label(): string
    {
        return match ($this) {
            self::GeneralAdministration => 'الإدارة العامة',
            self::Division              => 'إدارة',
            self::Section               => 'قسم',
        };
    }

    /**
     * The tier directly BELOW this one, or null if this is the deepest defined
     * tier (i.e. nothing may be nested under it).
     *
     * This is the single definition of "what comes under a division" — the
     * child rule in the form requests is expressed in terms of it.
     */
    public function next(): ?self
    {
        return self::tryFrom($this->value + 1);
    }

    /**
     * The tier directly ABOVE this one, or null if this tier is a root tier.
     */
    public function previous(): ?self
    {
        return self::tryFrom($this->value - 1);
    }

    /**
     * The tier a root row must be. The single root of the company tree is
     * always the general administration (الإدارة العامة); a division is never
     * a root.
     */
    public static function root(): self
    {
        return self::GeneralAdministration;
    }

    /**
     * Whether an arbitrary int maps to a defined tier. Used by validation to
     * reject undefined depths (e.g. level 3 while only 1 and 2 are defined).
     */
    public static function isDefined(int $level): bool
    {
        return self::tryFrom($level) instanceof self;
    }

    /**
     * All tiers, shallowest first — for select inputs and API metadata.
     *
     * @return array<int, array{value: int, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $level): array => [
                'value' => $level->value,
                'label' => $level->label(),
            ],
            self::cases(),
        );
    }
}
