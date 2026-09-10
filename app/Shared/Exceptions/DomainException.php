<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

/**
 * Marks a module exception that carries a client-facing meaning and an HTTP
 * status code AS DATA (API_BUILD_STANDARD C17).
 *
 * WHY an interface rather than a render() method on each exception:
 * - Before this existed, five domain exceptions each carried their own
 *   `render(Request): ?JsonResponse` and hand-built the envelope (GAP-4). That
 *   duplicated the envelope in five places, made changing it a five-file edit,
 *   and forced domain code — thrown from inside MODEL BOOT HOOKS — to import
 *   Illuminate\Http\Request and know about status codes and JSON.
 * - The stated reason those exceptions self-rendered was to keep modules
 *   zero-config: a new module should not require an edit to bootstrap/app.php.
 *   That reason is honoured here. ApiExceptionRenderer maps anything
 *   implementing this interface, so a new module exception is rendered
 *   correctly the moment it declares it — still no bootstrap edit, but now
 *   with exactly one place that knows what an envelope looks like.
 *
 * Implementations keep their message on the Throwable (getMessage()) and expose
 * the status through statusCode(). They import nothing from HTTP.
 */
interface DomainException
{
    /**
     * The HTTP status this failure maps to, as data. The exception does not
     * render; ApiExceptionRenderer does.
     */
    public function statusCode(): int;
}
