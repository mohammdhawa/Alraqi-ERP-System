<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a role administrator grants or revokes a grant that reaches
 * further than their own `auth.roles.update` grant does (GAP-3).
 *
 * 403, as for any other out-of-scope row (decision 19): the caller may use the
 * verb, just not over this reach.
 */
class GrantOutOfReachException extends \RuntimeException implements DomainException
{
    public function __construct(
        string $message = 'لا يمكنك منح أو سحب صلاحية تتجاوز نطاق صلاحياتك.',
        public readonly int $statusCode = Response::HTTP_FORBIDDEN,
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
