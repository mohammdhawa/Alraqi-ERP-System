<?php

declare(strict_types=1);

namespace App\Shared\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * ApiRespond Trait
 *
 * Provides a consistent JSON response envelope across the entire ERP.
 * Every API response follows the same structure:
 *
 * Success: { "success": true, "message": "...", "data": {...} }
 * Error:   { "success": false, "message": "...", "errors": {...} }
 *
 * WHY a trait and not a base controller:
 * - Traits compose; base controllers inherit. Composition is more flexible.
 * - Can be used in controllers, exception handlers, middleware — anywhere.
 * - Avoids deep inheritance chains as the ERP grows.
 * - Laravel 12 favors composition over inheritance.
 *
 * WHY a fixed envelope:
 * - Frontend teams can write a single response parser.
 * - Mobile apps get predictable error handling.
 * - Monitoring/logging can key on "success" field uniformly.
 */
trait ApiRespond
{
    protected function success(
        mixed $data = null,
        string $message = 'تمت العملية بنجاح.',
        int $statusCode = Response::HTTP_OK,
        array $headers = [],
    ): JsonResponse {
        $response = [
            'success' => true,
            'message' => $message,
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        return response()->json($response, $statusCode, $headers);
    }

    protected function created(
        mixed $data = null,
        string $message = 'تم الإنشاء بنجاح.',
    ): JsonResponse {
        return $this->success($data, $message, Response::HTTP_CREATED);
    }

    protected function noContent(string $message = 'تمت العملية بنجاح.'): JsonResponse
    {
        return $this->success(message: $message, statusCode: Response::HTTP_NO_CONTENT);
    }

    /**
     * Success envelope for a PAGINATED resource collection.
     *
     * The plain success() helper nests a resource collection under `data`,
     * which silently drops Laravel's pagination metadata (a ResourceCollection
     * only emits its meta when it is the top-level response, not when embedded).
     * This helper keeps the same envelope but lifts the transformed items into
     * `data` and exposes the paginator's metadata under a sibling `meta` key, so
     * frontends can build pagers (total pages, item counts, etc.).
     *
     * Pass the result of `SomeResource::collection($paginator)`; the underlying
     * LengthAwarePaginator is read off the collection's `resource`.
     */
    protected function paginated(
        ResourceCollection $data,
        string $message = 'تمت العملية بنجاح.',
        int $statusCode = Response::HTTP_OK,
    ): JsonResponse {
        $paginator = $data->resource;

        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data->collection,
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'from'         => $paginator->firstItem(),
                'to'           => $paginator->lastItem(),
            ],
        ], $statusCode);
    }

    protected function error(
        string $message = 'حدث خطأ ما.',
        int $statusCode = Response::HTTP_BAD_REQUEST,
        mixed $errors = null,
    ): JsonResponse {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $statusCode);
    }

    protected function unauthorized(string $message = 'غير مصرّح بالوصول.'): JsonResponse
    {
        return $this->error($message, Response::HTTP_UNAUTHORIZED);
    }

    protected function forbidden(string $message = 'ليس لديك صلاحية لتنفيذ هذا الإجراء.'): JsonResponse
    {
        return $this->error($message, Response::HTTP_FORBIDDEN);
    }

    protected function notFound(string $message = 'العنصر المطلوب غير موجود.'): JsonResponse
    {
        return $this->error($message, Response::HTTP_NOT_FOUND);
    }

    protected function validationError(
        mixed $errors,
        string $message = 'فشل التحقق من البيانات المُدخلة.',
    ): JsonResponse {
        return $this->error($message, Response::HTTP_UNPROCESSABLE_ENTITY, $errors);
    }

    protected function serverError(string $message = 'حدث خطأ داخلي في الخادم.'): JsonResponse
    {
        return $this->error($message, Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}