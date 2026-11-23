<?php

declare(strict_types=1);

namespace App\Modules\Documents\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The list of every document is `documents.view`'s alone (documents design
 * §7.7); a party reads the documents they are on from the other two boxes.
 */
class ViewAllDocumentsForbiddenException extends \RuntimeException implements DomainException
{
    public function __construct(string $message = 'لا تملك صلاحية عرض جميع المستندات.')
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}
