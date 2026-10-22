<?php

declare(strict_types=1);

namespace App\Modules\HR\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a posting-history write would leave an employee with two CURRENT
 * postings, or with a posting that ends before it starts.
 *
 * WHY THIS CANNOT BE AN INDEX: "at most one row per employee where `to_date` IS
 * NULL" is a partial unique index. MySQL has none, and SQLite's is not
 * something Laravel's schema builder expresses portably. So the rule lives in
 * the model's `saving` hook — the layer every writer crosses (P2) — exactly as
 * the singleton-root rule does for departments, and for the same reason.
 *
 * WHY IT MATTERS MORE THAN IT LOOKS: `employees.department_id` is kept in step
 * with whichever assignment is open. Two open assignments means the pointer has
 * two sources and the last writer wins at random, which is a silent corruption
 * of the one column every org-scoped query reads.
 *
 * 422 — an invariant violation, the same class the form request reports.
 */
class AssignmentConflictException extends \RuntimeException implements DomainException
{
    public function __construct(
        string $message = 'لا يمكن أن يكون للموظف أكثر من تعيين حالي واحد.',
        public readonly int $statusCode = Response::HTTP_UNPROCESSABLE_ENTITY,
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
