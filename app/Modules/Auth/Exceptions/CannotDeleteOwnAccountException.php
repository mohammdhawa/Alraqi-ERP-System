<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a user deletes the account they are currently signed in as.
 *
 * WHY THIS CLASS EXISTS AT ALL: the rule used to live inline in
 * UserController::destroy as an `if` returning a magic 422 —
 *
 *     if ($user->id === $request->user()->id) {
 *         return $this->error('لا يمكنك حذف حسابك الخاص.', 422);
 *     }
 *
 * which broke C2 (business branching in a handler), C10 (an authorization
 * decision inside a controller action) and C16 (a hand-built status literal
 * instead of the envelope helpers). Worst of all it broke C4: the rule guarded
 * exactly one path. UserService::delete() called from a console command, a job,
 * or any future admin screen had no such guard, so the protection was a
 * property of one URL rather than of the operation.
 *
 * The rule now lives in UserService::delete(), which every caller crosses, and
 * the refusal is this exception carrying its status as data (C17).
 *
 * WHY 422 AND NOT 403: the caller is permitted to delete users — that is what
 * auth.users.delete grants, and it is why a permission check cannot express
 * this. What is wrong is the specific target. That is an unprocessable request,
 * not a denied one.
 */
class CannotDeleteOwnAccountException extends \RuntimeException implements DomainException
{
    public function __construct(
        string $message = 'لا يمكنك حذف حسابك الخاص.',
        public readonly int $statusCode = Response::HTTP_UNPROCESSABLE_ENTITY,
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
