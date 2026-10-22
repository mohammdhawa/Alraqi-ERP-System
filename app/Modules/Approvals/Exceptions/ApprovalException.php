<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * An approval rule refused the action — not your turn, nobody to approve, a
 * step that cannot be reassigned. Arabic, written for the person acting.
 *
 * 422, as legacy answers, unless a rule says otherwise (a withdrawal by
 * someone who is not the requester is 403).
 */
class ApprovalException extends \RuntimeException implements DomainException
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
