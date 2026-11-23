<?php

declare(strict_types=1);

namespace App\Modules\Documents\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A decision on a document that cannot be signed now (documents design
 * §7.2): the signer manages no unit, the unit has no stamp, or the password
 * does not match. The message says which, in Arabic, for the signer.
 */
class DocumentDecisionException extends \RuntimeException implements DomainException
{
    public function statusCode(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }
}
