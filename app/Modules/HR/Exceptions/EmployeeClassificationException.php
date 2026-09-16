<?php

declare(strict_types=1);

namespace App\Modules\HR\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown by the Employee model's `saving` hook when a write would break one of
 * the classification invariants — see EmployeeClassificationGuard.
 *
 * WHY this exists alongside EmployeeRequest's validation:
 * - The form request is the front door: it runs the same guard and returns a
 *   friendly 422 with field-level Arabic messages. It covers HTTP only.
 * - This is the backstop for every OTHER writer — the Batch 4 importer above
 *   all, plus seeders, tinker, jobs and factories. On a table that feeds
 *   payroll, silent acceptance of an engineering rank on an administrative post
 *   is far worse than an error.
 *
 * 422 because an invariant violation is the same class of failure the form
 * request reports as Unprocessable Entity — mirroring
 * DepartmentHierarchyException exactly.
 */
class EmployeeClassificationException extends \RuntimeException implements DomainException
{
    public function __construct(
        string $message = 'بيانات الموظف تخالف قواعد التصنيف الوظيفي.',
        public readonly int $statusCode = Response::HTTP_UNPROCESSABLE_ENTITY,
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
