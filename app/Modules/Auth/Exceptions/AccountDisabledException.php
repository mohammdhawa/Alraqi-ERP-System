<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use Symfony\Component\HttpFoundation\Response;

class AccountDisabledException extends AuthenticationException
{
    public function __construct(string $message = 'تم تعطيل هذا الحساب.')
    {
        parent::__construct($message, Response::HTTP_FORBIDDEN);
    }
}