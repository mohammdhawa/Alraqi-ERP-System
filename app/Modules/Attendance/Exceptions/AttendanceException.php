<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * An attendance business rule refused the event — legacy's
 * AttendanceException, with legacy's Arabic messages.
 *
 * Its message is written for the employee, which is why the sync endpoint may
 * echo it for one entry while it reports every other failure generically.
 *
 * 422, as legacy answers.
 */
class AttendanceException extends \RuntimeException implements DomainException
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
