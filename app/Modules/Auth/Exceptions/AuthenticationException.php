<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * Base exception for all authentication failures.
 *
 * WHY custom exceptions instead of generic ones:
 * - The exception handler can map each to a specific HTTP status code.
 * - Audit logging can differentiate between failure types.
 * - Code reads as: throw new AuthenticationException vs throw new \Exception.
 * - Each exception knows its own HTTP status code (encapsulation).
 */
class AuthenticationException extends \RuntimeException
{
    public function __construct(
        string $message = 'Authentication failed.',
        public readonly int $statusCode = Response::HTTP_UNAUTHORIZED,
    ) {
        parent::__construct($message);
    }
}
