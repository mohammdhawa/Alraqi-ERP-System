<?php

declare(strict_types=1);

namespace App\Modules\HR\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when an import run would point a source key at a different employee
 * from the one it was mapped to before.
 *
 * Between runs HR corrects the source, and the importer matches each row to
 * the person it became last time. A key that now names somebody else is not a
 * correction the importer can make: either the source reused an identifier or
 * the importer derived it wrongly. Either way it is reported for a human, never
 * absorbed by re-pointing the mapping — which would silently merge two people's
 * records.
 *
 * 422 — an invariant violation.
 */
class ImportSourceConflictException extends \RuntimeException implements DomainException
{
    public function __construct(
        string $message = 'هذا السطر من ملف الاستيراد مرتبط بموظف آخر في تشغيل سابق.',
        public readonly int $statusCode = Response::HTTP_UNPROCESSABLE_ENTITY,
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
