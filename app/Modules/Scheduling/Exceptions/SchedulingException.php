<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A scheduling rule refused a write — raised by the model hooks, so it binds
 * seeders and tinker as well as the API, and rendered as a 422 with its
 * Arabic message (C17: no payload).
 */
class SchedulingException extends \RuntimeException implements DomainException
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
