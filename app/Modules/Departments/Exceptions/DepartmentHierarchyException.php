<?php

declare(strict_types=1);

namespace App\Modules\Departments\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown by the Department model's `saving` hook when a write would break a
 * hierarchy invariant (root tier, singleton root, child tier, self-reference,
 * or cycle) — see DepartmentHierarchyGuard.
 *
 * WHY this exists alongside the form-request validation:
 * - The form requests are the front door: they run the same guard and return a
 *   friendly 422 with field-level Arabic messages. But they only cover HTTP.
 * - This exception is the backstop for every OTHER writer — seeder, tinker,
 *   job, factory. Silent failure on a foundation table is worse than an error,
 *   so the model throws rather than returning false.
 *
 * It mirrors DepartmentHasChildrenException: a self-rendering domain exception
 * whose JSON envelope matches ApiRespond::error() exactly, so clients that do
 * reach it (a hypothetical non-form-request HTTP write) see no difference. The
 * status is 422 because an invariant violation is the same class of failure the
 * form request reports as Unprocessable Entity.
 */
class DepartmentHierarchyException extends \RuntimeException
{
    public function __construct(
        string $message = 'الوحدة التنظيمية تخالف قواعد الهيكل التنظيمي.',
        public readonly int $statusCode = Response::HTTP_UNPROCESSABLE_ENTITY,
    ) {
        parent::__construct($message);
    }

    /**
     * Render as the project's standard error envelope for API clients.
     */
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
        ], $this->statusCode);
    }
}
