<?php

declare(strict_types=1);

namespace App\Modules\Leave\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A leave rule refused the action — legacy's refusals, in Arabic.
 *
 * 422, as legacy answers. A DomainException carries no payload (C17): where
 * legacy returned the balance or the attended dates beside its message, the
 * figures are in the message.
 */
class LeaveException extends \RuntimeException implements DomainException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = Response::HTTP_UNPROCESSABLE_ENTITY,
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
