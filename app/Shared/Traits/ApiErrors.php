<?php

declare(strict_types=1);

namespace App\Shared\Traits;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * ApiErrors Trait
 *
 * The ERROR half of the standard envelope:
 *
 *   { "success": false, "message": "...", "errors": {...} }
 *
 * WHY THIS IS SPLIT OUT OF ApiRespond: two collaborators only ever produce
 * errors — ApiExceptionRenderer and the CheckPermission middleware — and taking
 * the whole of ApiRespond handed them `success()`, `created()`, `paginated()`
 * and `perPage()`, none of which an error path can meaningfully call. A
 * middleware that can build a paginated success envelope is a middleware whose
 * contract has stopped being obvious.
 *
 * WHAT THE SPLIT MUST NOT DO is create a second definition of the envelope.
 * There is still exactly one `error()`: ApiRespond composes this trait rather
 * than restating it, so a controller keeps the full vocabulary and the shape of
 * an error response is written once, here. C16 says only ApiRespond builds an
 * envelope; that is still true — this IS ApiRespond's error half, not a rival.
 */
trait ApiErrors
{
    protected function error(
        string $message = 'حدث خطأ ما.',
        int $statusCode = Response::HTTP_BAD_REQUEST,
        mixed $errors = null,
        array $headers = [],
    ): JsonResponse {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        // $headers carries Retry-After / X-RateLimit-* through from a throttled
        // request, and Allow from a 405 — they are part of those contracts.
        return response()->json($response, $statusCode, $headers);
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
