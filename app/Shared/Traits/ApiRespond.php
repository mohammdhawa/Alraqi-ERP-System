<?php

declare(strict_types=1);

namespace App\Shared\Traits;

use Illuminate\Http\JsonResponse;
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
        string $message = 'Success',
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
        string $message = 'Resource created successfully',
    ): JsonResponse {
        return $this->success($data, $message, Response::HTTP_CREATED);
    }

    protected function noContent(string $message = 'Success'): JsonResponse
    {
        return $this->success(message: $message, statusCode: Response::HTTP_NO_CONTENT);
    }

    protected function error(
        string $message = 'An error occurred',
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

    protected function unauthorized(string $message = 'Unauthorized'): JsonResponse
    {
        return $this->error($message, Response::HTTP_UNAUTHORIZED);
    }

    protected function forbidden(string $message = 'Forbidden'): JsonResponse
    {
        return $this->error($message, Response::HTTP_FORBIDDEN);
    }

    protected function notFound(string $message = 'Resource not found'): JsonResponse
    {
        return $this->error($message, Response::HTTP_NOT_FOUND);
    }

    protected function validationError(
        mixed $errors,
        string $message = 'Validation failed',
    ): JsonResponse {
        return $this->error($message, Response::HTTP_UNPROCESSABLE_ENTITY, $errors);
    }

    protected function serverError(string $message = 'Internal server error'): JsonResponse
    {
        return $this->error($message, Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}