<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The account is not linked to an employee — the technical department's
 * accounts, `super_admin` — so it has no attendance to record or read.
 *
 * 403, not 422: nothing about the request could be corrected; this account
 * is simply not one the self-service attendance surface is for.
 */
class NotAnAttendeeException extends \RuntimeException implements DomainException
{
    public function __construct(
        string $message = 'هذا الحساب غير مرتبط بموظف، فلا يُسجَّل له حضور.',
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}
