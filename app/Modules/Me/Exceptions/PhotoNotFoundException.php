<?php

declare(strict_types=1);

namespace App\Modules\Me\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * No personal photo, or its file is gone. The app shows initials.
 */
class PhotoNotFoundException extends \RuntimeException implements DomainException
{
    public function __construct(string $message = 'لا توجد صورة شخصية.')
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return Response::HTTP_NOT_FOUND;
    }
}
