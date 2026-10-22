<?php

declare(strict_types=1);

namespace App\Modules\Leave\Exceptions;

use App\Modules\HR\Models\Employee;
use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A leave rule refused the action — legacy's refusals, in Arabic.
 *
 * 422, as legacy answers. A DomainException carries no payload (C17): where
 * legacy returned the balance or the attended dates beside its message, the
 * figures are in the message.
 */
class LeaveException extends \RuntimeException implements DomainException
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
