<?php

declare(strict_types=1);

namespace App\Modules\Documents\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * D15: an employee cannot delete, replace or redraw their own signature. Only
 * the system administrator deletes one; its owner then draws a new one.
 */
class SignatureExistsException extends \RuntimeException implements DomainException
{
    public function __construct(string $message = 'لديك توقيع محفوظ، ولا يمكن تغييره. لحذفه راجع مدير النظام.')
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return Response::HTTP_CONFLICT;
    }
}
