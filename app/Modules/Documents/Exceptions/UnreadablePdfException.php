<?php

declare(strict_types=1);

namespace App\Modules\Documents\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A PDF the printing cannot open, even after the server tried to rewrite it
 * (documents design §11.5): an encrypted one, a broken one, or one the repair
 * tool could not fix or was not there to fix.
 */
class UnreadablePdfException extends \RuntimeException implements DomainException
{
    public function __construct(
        string $message = 'تعذّر تجهيز هذا الملف للتوقيع. يُرجى حفظه من جديد بصيغة PDF (مثل Microsoft Print to PDF) ثم رفعه.',
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }
}
