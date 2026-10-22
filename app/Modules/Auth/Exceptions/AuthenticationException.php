<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base exception for all authentication failures.
 *
 * WHY custom exceptions instead of generic ones:
 * - The exception handler can map each to a specific HTTP status code.
 * - Audit logging can differentiate between failure types.
 * - Code reads as: throw new AuthenticationException vs throw new \Exception.
 * - Each exception carries its own HTTP status code as data (C17).
 *
 * This class and its subclasses (AccountDisabled 403, InvalidRefreshToken 401)
 * were already rendered centrally, by a bespoke block in bootstrap/app.php that
 * hand-built the envelope. Implementing DomainException folds them into the one
 * general mapper, so that block is gone and there is a single envelope again.
 */
class AuthenticationException extends \RuntimeException implements DomainException
{
    public function __construct(
        string $message = 'فشلت عملية المصادقة.',
        public readonly int $statusCode = Response::HTTP_UNAUTHORIZED,
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
