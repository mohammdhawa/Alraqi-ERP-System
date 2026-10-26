<?php

declare(strict_types=1);

namespace App\Shared\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * ApiRespond Trait
 *
 * Provides a consistent JSON response envelope across the entire ERP.
 * Every API response follows the same structure:
 *
 * Success: { "success": true, "message": "...", "data": {...} }
 * Error:   { "success": false, "message": "...", "errors": {...} }  (see ApiErrors)
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
    /**
     * The error half of the envelope, composed rather than duplicated —
     * so a controller keeps the full vocabulary while ApiExceptionRenderer
     * and CheckPermission can take only the errors. One `error()` exists.
     */
    use ApiErrors;

    /**
     * `$extra` (2026-10-24) carries what the request made beside `data` — the
     * account and temporary password `POST /api/hr/employees` creates with the
     * employee — inside this one envelope (C16), as `paginated()` does. It may
     * not replace the envelope's own keys.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function success(
        mixed $data = null,
        string $message = 'تمت العملية بنجاح.',
        int $statusCode = Response::HTTP_OK,
        array $headers = [],
        array $extra = [],
    ): JsonResponse {
        $reserved = array_intersect(array_keys($extra), ['success', 'message', 'data', 'meta']);

        if ($reserved !== []) {
            throw new \InvalidArgumentException('success() extra may not replace the envelope key(s): '.implode(', ', $reserved));
        }

        $response = [
            'success' => true,
            'message' => $message,
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        return response()->json($response + $extra, $statusCode, $headers);
    }

    /** @param  array<string, mixed>  $extra */
    protected function created(
        mixed $data = null,
        string $message = 'تم الإنشاء بنجاح.',
        array $extra = [],
    ): JsonResponse {
        return $this->success($data, $message, Response::HTTP_CREATED, extra: $extra);
    }

    /**
     * The page size to apply to a list query, read from the query string and
     * CLAMPED to 1–100 (C28).
     *
     * WHY THIS LIVES HERE and not in each controller: it is one rule, so it is
     * written once (P1). Before it existed, every service accepted
     * `int $perPage = 15` and every controller called `paginate()` with no
     * argument — so `per_page` was silently DEAD on every list endpoint
     * (verified 2026-09-08: per_page=5, 0, -1, abc and 1000000 all returned
     * per_page 15), and no cap existed for the moment it was wired up.
     *
     * WHY THE CLAMP IS NOT DECORATION: without it `?per_page=1000000` is a
     * cheap denial of service against a table that will hold hundreds of
     * thousands of audit rows.
     *
     * WHY IT IS CLAMPED, NOT REJECTED: a page size is a client hint, not a
     * business fact — silently honouring the nearest legal value is kinder than
     * a 422 and matches the documented contract. An unknown `sort` or filter
     * value IS a 422 (C30); those are different, because a wrong sort returns
     * wrong data while a wrong page size returns correct data in a different
     * batch.
     *
     * The result is passed to the service as an int, so the service never reads
     * the query string and stays callable from a console command or a job (C1).
     */
    protected function perPage(Request $request, int $default = 15): int
    {
        $requested = $request->query('per_page');

        if (! is_numeric($requested)) {
            return $default;
        }

        return max(1, min(100, (int) $requested));
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
     *
     * `$extra` (2026-10-18) carries facts about the WHOLE result beside `meta`
     * — the year a balances list is for, a register's window and summary — so
     * they stay inside this one envelope (C16) instead of a hand-built one.
     * It may not replace the envelope's own keys.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function paginated(
        ResourceCollection $data,
        string $message = 'تمت العملية بنجاح.',
        int $statusCode = Response::HTTP_OK,
        array $extra = [],
    ): JsonResponse {
        $reserved = array_intersect(array_keys($extra), ['success', 'message', 'data', 'meta']);

        if ($reserved !== []) {
            throw new \InvalidArgumentException('paginated() extra may not replace the envelope key(s): '.implode(', ', $reserved));
        }

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
        ] + $extra, $statusCode);
    }
}
