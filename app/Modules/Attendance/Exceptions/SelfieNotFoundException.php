<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The record has no selfie to show: an HR-corrected check-out never had one,
 * and a file is deleted after three months while its record stays (Q11).
 *
 * 404 — the record exists; the file does not.
 */
class SelfieNotFoundException extends \RuntimeException implements DomainException
{
    public function __construct(string $message = 'لا توجد صورة لهذا السجل.')
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return Response::HTTP_NOT_FOUND;
    }
}
