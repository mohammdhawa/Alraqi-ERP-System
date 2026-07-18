<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use Symfony\Component\HttpFoundation\Response;

class InvalidRefreshTokenException extends AuthenticationException
{
    public function __construct(string $message = 'رمز التحديث غير صالح أو منتهي الصلاحية.')
    {
        parent::__construct($message, Response::HTTP_UNAUTHORIZED);
    }
}