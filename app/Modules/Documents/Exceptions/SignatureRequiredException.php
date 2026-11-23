<?php

declare(strict_types=1);

namespace App\Modules\Documents\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signer has no signature yet (documents design §7.2, D14, D24). A
 * distinct status, 428, so the client opens the pad, stores the drawing and
 * sends the decision again.
 */
class SignatureRequiredException extends \RuntimeException implements DomainException
{
    public function __construct(string $message = 'لم ترسم توقيعك بعد. ارسمه ثم أعد المحاولة.')
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return Response::HTTP_PRECONDITION_REQUIRED;
    }
}
