<?php

declare(strict_types=1);

namespace App\Modules\Documents\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only a إدارة (or the root) keeps a صادر register, never a section.
 */
class NotARegisterException extends \RuntimeException implements DomainException
{
    public function __construct(string $message = 'هذه الوحدة لا تملك سجل صادر.')
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }
}
