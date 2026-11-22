<?php

declare(strict_types=1);

namespace App\Modules\Documents\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only PDFs and photos can be signed (I.12): nothing can be printed on a Word
 * file, so it is refused, with a message asking for a PDF.
 */
class UnsupportedUploadException extends \RuntimeException implements DomainException
{
    public function __construct(string $message = 'يُرجى رفع الملف بصيغة PDF أو صورة.')
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }
}
