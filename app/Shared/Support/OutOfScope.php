<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Illuminate\Auth\Access\Response;

/**
 * The refusal a policy gives when the caller may perform the verb but the row
 * lies outside their scope (GAP-3).
 *
 * ⚙️ 403, NOT 404 — ratified as START_HERE §9 decision 19, with its cost
 * accepted knowingly: a 403 confirms the row exists, so someone walking ids can
 * learn which ids are real. A clear refusal was judged worth more to the user
 * and to support, for an internal ERP behind authentication.
 *
 * A DENIAL, NOT AN EXCEPTION. A policy returns this, so `Gate::allows()`,
 * `$user->can()` and `@can` still answer false for an out-of-scope row; only
 * `authorize()` and `can:` middleware turn it into an AuthorizationException.
 *
 * THE CODE IS WHAT LETS THE MESSAGE THROUGH. `ApiExceptionRenderer` replaces an
 * ordinary AuthorizationException's message with its fixed Arabic 403 (C12 —
 * the framework default is English, written for developers). A denial carrying
 * `CODE` was authored for the client, so the renderer shows its message —
 * decision 19 asks for a refusal that says the row is out of reach, not the
 * same words a caller missing the VERB gets.
 *
 * In Shared rather than a module because every module that scopes rows gives
 * the same refusal; the message is the caller's to specialise.
 */
final class OutOfScope
{
    public const CODE = 'out_of_scope';

    public static function deny(string $message = 'هذا السجل خارج نطاق صلاحياتك.'): Response
    {
        return Response::deny($message, self::CODE);
    }
}
