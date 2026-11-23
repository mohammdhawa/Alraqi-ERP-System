<?php

declare(strict_types=1);

namespace App\Modules\Documents\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The document was deleted by its creator (documents design §7.6). Its
 * parties may still be sent its link, so it answers 410, not 404.
 */
class DocumentDeletedException extends \RuntimeException implements DomainException
{
    public function __construct(string $message = 'حُذف هذا المستند.')
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return Response::HTTP_GONE;
    }
}
