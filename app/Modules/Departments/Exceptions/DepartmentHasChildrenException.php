<?php

declare(strict_types=1);

namespace App\Modules\Departments\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a department that still has non-trashed children is deleted.
 *
 * WHY this exists as a real exception and not a boolean return:
 * - The guard lives in a model `deleting` hook, which can only stop a delete
 *   by returning false (silent, no reason) or by throwing. Silent failure on
 *   a foundation table is worse than an error.
 * - Every caller — controller, service, seeder, tinker, a future module — gets
 *   the same refusal and the same Arabic reason.
 *
 * WHY it renders itself instead of a block in bootstrap/app.php (where the Auth
 * exceptions are handled): modules here are zero-config — ModuleServiceProvider
 * discovers a folder and it works. Requiring an edit to bootstrap/app.php for
 * every module exception would break that, and the file would collect a render
 * block per module over time. The envelope below matches ApiRespond::error()
 * exactly, so clients see no difference.
 */
class DepartmentHasChildrenException extends \RuntimeException
{
    public function __construct(
        string $message = 'لا يمكن حذف وحدة تنظيمية تحتوي على وحدات فرعية. الرجاء نقل أو حذف الوحدات الفرعية أولًا.',
        public readonly int $statusCode = Response::HTTP_CONFLICT,
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
