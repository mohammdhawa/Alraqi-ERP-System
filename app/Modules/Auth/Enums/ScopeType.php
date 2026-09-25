<?php

declare(strict_types=1);

namespace App\Modules\Auth\Enums;

/**
 * ScopeType — how far a role grant reaches (GAP-3, START_HERE §9 decision 18).
 *
 * A grant is a role PLUS the rows it applies to. The role says which verbs; the
 * scope says which employees (and, later, which units) those verbs touch.
 *
 *   Company — every row. Today's behaviour, and what every grant written before
 *             scopes existed was migrated to, so no one's reach changed on the
 *             day this shipped.
 *   Unit    — a named unit and everything beneath it (decision 14: the whole
 *             subtree). For the cases the tree cannot express — a departmental
 *             assistant who is not the manager.
 *   Managed — «الوحدات التي أديرها»: the subtree of every unit whose
 *             `departments.manager_id` is this user's employee. Nothing is
 *             stored about WHICH units; the tree stays the single record of who
 *             manages what (P1), so a manager moved to another division carries
 *             their reach with them on the next request.
 *
 * ⚠️ There is deliberately NO "no scope" case. A grant always says how far it
 * reaches. "Derive scope from the tree alone" was rejected because a user who
 * manages nothing would then see everything — see decision 18.
 */
enum ScopeType: int
{
    case Company = 1;
    case Unit    = 2;
    case Managed = 3;

    /**
     * Arabic display name.
     */
    public function label(): string
    {
        return match ($this) {
            self::Company => 'الشركة كاملة',
            self::Unit    => 'وحدة محددة',
            self::Managed => 'الوحدات التي أديرها',
        };
    }

    /**
     * Whether a grant of this type names a unit. The one definition of the
     * pairing rule, read by the guard and the form request alike.
     */
    public function requiresDepartment(): bool
    {
        return $this === self::Unit;
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
