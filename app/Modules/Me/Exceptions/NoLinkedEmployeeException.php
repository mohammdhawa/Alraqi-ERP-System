<?php

declare(strict_types=1);

namespace App\Modules\Me\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The account is not linked to an employee — the technical department's
 * accounts, `super_admin` — so it has no profile, photo or home screen.
 *
 * 403, not 404: nothing about the request could be corrected; this account is
 * simply not one the self-service surface is for. The words are the ones
 * `/api/me/leave-balance` already answers with.
 */
class NoLinkedEmployeeException extends \RuntimeException implements DomainException
{
    public function __construct(string $message = 'لا يوجد موظف مرتبط بهذا الحساب.')
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}
