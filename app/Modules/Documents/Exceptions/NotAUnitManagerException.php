<?php

declare(strict_types=1);

namespace App\Modules\Documents\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only a unit's manager creates documents (documents design §5). It also
 * carries the refusal of a sender who is not one of HR's managers, with
 * `DocumentSending`'s copy (documents to employees).
 */
class NotAUnitManagerException extends \RuntimeException implements DomainException
{
    public function __construct(string $message = 'لا يُنشئ المستندات إلا مدير وحدة تنظيمية.')
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}
