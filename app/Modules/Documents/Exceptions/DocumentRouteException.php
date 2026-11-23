<?php

declare(strict_types=1);

namespace App\Modules\Documents\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The route a document was sent on cannot be used: an approver who may not be
 * named, a vacant general manager's post, a register number already taken.
 * The message says which, in Arabic, for the creator.
 */
class DocumentRouteException extends \RuntimeException implements DomainException
{
    public function statusCode(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }
}
