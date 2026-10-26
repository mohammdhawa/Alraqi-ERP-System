<?php

declare(strict_types=1);

namespace App\Modules\HR\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The employee number is the person's Kobo id (KOBO_EMPLOYEE_NUMBER_DESIGN.md
 * §3): required at creation, digits only, never changed. Thrown by the
 * Employee model's hooks — the backstop for every writer the request does not
 * cover (the importer, seeders, scripts). 422, as the request's own refusal.
 */
class EmployeeNumberException extends \RuntimeException implements DomainException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = Response::HTTP_UNPROCESSABLE_ENTITY,
    ) {
        parent::__construct($message);
    }

    public static function required(): self
    {
        return new self('رقم كوبو مطلوب.');
    }

    public static function notDigits(): self
    {
        return new self('رقم كوبو يتكوّن من أرقام فقط.');
    }

    /** K2: a Kobo id never changes, and it is the login. */
    public static function immutable(): self
    {
        return new self('لا يمكن تغيير الرقم الوظيفي بعد إنشائه.');
    }

    /** A deleted employee's number went to someone else while they were gone. */
    public static function takenOnRestore(string $number): self
    {
        return new self("الرقم الوظيفي {$number} صار لموظف آخر — لا يُستعاد هذا الموظف.");
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
