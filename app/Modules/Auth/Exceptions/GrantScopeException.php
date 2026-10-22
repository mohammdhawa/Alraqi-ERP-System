<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown by `UserRole`'s `saving` hook when a grant's scope is malformed — a
 * unit grant without a unit, or a company grant carrying one.
 *
 * Over HTTP, `AssignRoleRequest` catches the same shapes first as field-level
 * 422s; this is what protects every other writer (C4). Rendering belongs to
 * ApiExceptionRenderer (C17).
 */
class GrantScopeException extends \RuntimeException implements DomainException
{
    public function __construct(
        string $message = 'نطاق الصلاحية غير صالح.',
        public readonly int $statusCode = Response::HTTP_UNPROCESSABLE_ENTITY,
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
