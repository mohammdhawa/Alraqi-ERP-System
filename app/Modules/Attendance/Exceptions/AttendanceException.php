<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Exceptions;

use App\Modules\HR\Models\Employee;
use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * An attendance business rule refused the event — legacy's
 * AttendanceException, with legacy's Arabic messages.
 *
 * Its message is written for the employee, which is why the sync endpoint may
 * echo it for one entry while it reports every other failure generically.
 *
 * 422, as legacy answers.
 */
class AttendanceException extends \RuntimeException implements DomainException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = Response::HTTP_UNPROCESSABLE_ENTITY,
    ) {
        parent::__construct($message);
    }

    /** The refusal for an act on someone not on duty (owner, 2026-10-20, parity audit C-3). */
    public const NOT_ON_DUTY = 'لا يمكن تنفيذ هذا الإجراء: الموظف ليس على رأس عمله.';

    /**
     * Refuse unless the employee is on duty — not suspended, not terminated,
     * not departed. Their records stay readable; nothing new is written.
     */
    public static function unlessOnDuty(?Employee $employee): void
    {
        if ($employee === null || ! $employee->isOnDuty()) {
            throw new self(self::NOT_ON_DUTY);
        }
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
