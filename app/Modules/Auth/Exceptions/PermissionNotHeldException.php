<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when an administrator would make someone gain or lose a permission
 * they do not hold themselves (GAP-16, START_HERE §9 decision 24).
 *
 * Distinct from GrantOutOfReachException, which is about WHERE a grant applies:
 * this is about WHAT it carries. The message names the permissions — by their
 * Arabic labels — because "you lack something" with no list sends the
 * administrator to guess which of a role's permissions is the obstacle.
 *
 * 403, as for any other refusal of a verb the caller holds but may not use
 * this way (decision 19).
 */
class PermissionNotHeldException extends \RuntimeException implements DomainException
{
    /**
     * @param  array<int, string>  $labels  the permissions the actor lacks, as they should be named
     * @param  string  $lead  one sentence saying what was refused and why
     */
    public function __construct(
        public readonly array $labels,
        string $lead,
    ) {
        parent::__construct(sprintf(
            '%s لا تملك: %s.',
            $lead,
            implode('، ', array_map(static fn (string $label): string => "«{$label}»", $labels)),
        ));
    }

    public function statusCode(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}
