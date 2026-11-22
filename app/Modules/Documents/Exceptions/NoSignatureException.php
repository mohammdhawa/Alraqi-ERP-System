<?php

declare(strict_types=1);

namespace App\Modules\Documents\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

class NoSignatureException extends \RuntimeException implements DomainException
{
    public function __construct(string $message = 'لا يوجد توقيع محفوظ.')
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return Response::HTTP_NOT_FOUND;
    }
}
