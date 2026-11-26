<?php

declare(strict_types=1);

namespace App\Modules\Documents\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * سجل الصادر is the إدارة's own manager's, or `documents.view`'s.
 */
class RegisterForbiddenException extends \RuntimeException implements DomainException
{
    public function __construct(string $message = 'لا يطّلع على سجل الصادر إلا مدير الإدارة أو من يملك صلاحية عرض المستندات.')
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}
