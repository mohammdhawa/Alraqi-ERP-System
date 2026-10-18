<?php

declare(strict_types=1);

namespace App\Shared\Support;

use App\Modules\Auth\Models\User;

/**
 * The account that did something to a row, as every response embeds it
 * (C31): who corrected or refused an attendance row — legacy's
 * `rejected_by_name`, with the id beside it — and who decided or reassigned
 * an approval step. In Shared since the approvals endpoints became its second
 * module (2026-10-18).
 *
 * An account is not always a person (the technical department), so the name
 * falls back to its email. Reads the user's `employee`, which its caller
 * eager-loads.
 */
final class Actor
{
    /**
     * @return array{id: int, name: ?string}|null
     */
    public static function of(?User $user): ?array
    {
        return $user === null ? null : [
            'id'   => (int) $user->getKey(),
            'name' => $user->employee?->full_name ?? $user->email,
        ];
    }
}
