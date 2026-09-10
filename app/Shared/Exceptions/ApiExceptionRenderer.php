<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use App\Shared\Traits\ApiErrors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * ApiExceptionRenderer
 *
 * THE single place an error response is shaped (API_BUILD_STANDARD stage 8,
 * C15, C16, C17). Wired once in bootstrap/app.php; nothing else renders.
 *
 * WHAT THIS CLOSED (GAP-2): before this existed, every framework-generated
 * error escaped the envelope entirely. Probed on 2026-09-08, `401`, `404`,
 * `405`, `422`, `429` and `5xx` all returned a bare `{"message": …}` with no
 * `success` key, and the `404` from route-model binding leaked the
 * fully-qualified model class — verified to persist with APP_DEBUG=false. A
 * client could not write one parser, and `errors` arrived on a body whose
 * `success` field was absent rather than false.
 *
 * DESIGN NOTES:
 *
 * - It uses ApiErrors — the error half that ApiRespond itself composes — so the
 *   envelope is built by the same code every controller uses. There is exactly
 *   one definition of what an error envelope is, and this class does not carry
 *   success/pagination helpers it could never legitimately call.
 *
 * - It returns NULL for non-API, non-JSON requests, letting Laravel's default
 *   HTML handling take over. An API-only rule must not swallow web errors.
 *
 * - Ordering in the match is significant: the most specific type wins.
 *   ThrottleRequestsException is checked before the generic
 *   HttpExceptionInterface arm because it is one; ModelNotFoundException is
 *   checked at all because a render callback runs BEFORE Laravel's
 *   prepareException() converts it to NotFoundHttpException.
 *
 * - C12: no arm exposes a class name, file path, trace, SQL, or model class —
 *   in ANY environment. The messages below are fixed Arabic strings; only a
 *   DomainException (whose message is authored for clients) passes its own
 *   message through. A developer needing detail uses `report()`'s log output or
 *   `withoutExceptionHandling()` in a test.
 *
 * - §6 "403 vs 404 for row-scoping": an unknown route and a missing row return
 *   the SAME generic 404, so a 404 never confirms that a row exists.
 */
class ApiExceptionRenderer
{
    // Only the error half (ApiErrors), not the whole of ApiRespond: this
    // class can never produce a success envelope or a paginated one, and a
    // renderer holding paginate() invites someone to use it.
    use ApiErrors;

    /**
     * Map a throwable to the standard error envelope, or null to let Laravel
     * handle it (non-API requests).
     */
    public function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return match (true) {
            // Field-level failures: the only arm that carries an `errors` bag.
            $e instanceof ValidationException => $this->validationError($e->errors()),

            // Module exceptions carry meaning + status as data (C17). Matching
            // the interface — not a list of classes — is what keeps modules
            // zero-config: a new one needs no edit here.
            $e instanceof DomainException => $this->error($e->getMessage(), $e->statusCode()),

            // C9: 401 is "we do not know who you are", 403 is "we know, and no".
            $e instanceof AuthenticationException => $this->unauthorized(),

            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => $this->forbidden(),

            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => $this->notFound(),

            $e instanceof MethodNotAllowedHttpException => $this->error(
                'طريقة الطلب غير مدعومة لهذا المسار.',
                $e->getStatusCode(),
                headers: $e->getHeaders(),
            ),

            // Retry-After and X-RateLimit-* must survive (§6 Headers).
            $e instanceof ThrottleRequestsException => $this->error(
                'عدد المحاولات كبير جدًا. يُرجى المحاولة بعد قليل.',
                $e->getStatusCode(),
                headers: $e->getHeaders(),
            ),

            // Any other framework HTTP exception keeps its status but loses its
            // internal message, which is written for developers, not clients.
            $e instanceof HttpExceptionInterface => $this->error(
                'تعذّر إتمام الطلب.',
                $e->getStatusCode(),
                headers: $e->getHeaders(),
            ),

            default => $this->serverError(),
        };
    }
}
