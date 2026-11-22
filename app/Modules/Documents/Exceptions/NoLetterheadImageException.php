<?php

declare(strict_types=1);

namespace App\Modules\Documents\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

class NoLetterheadImageException extends \RuntimeException implements DomainException
{
    public function __construct(string $message = 'لم تُرفع هذه الصورة بعد.')
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return Response::HTTP_NOT_FOUND;
    }
}
