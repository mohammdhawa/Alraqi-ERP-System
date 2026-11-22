<?php

declare(strict_types=1);

namespace App\Modules\Departments\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * One person manages one unit at most (documents round D27.1, the owner's
 * strict rule of 2026-11-22), so a manager's stamp is always their one
 * unit's. The message names the unit they already manage, so HR knows which
 * post to clear first.
 */
class ManagerOfAnotherUnitException extends \RuntimeException implements DomainException
{
    public function __construct(string $unitName)
    {
        parent::__construct("هذا الموظف مدير لوحدة «{$unitName}» بالفعل، ولا يدير الموظف أكثر من وحدة واحدة.");
    }

    public function statusCode(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }
}
