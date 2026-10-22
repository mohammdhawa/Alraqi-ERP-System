<?php

declare(strict_types=1);

namespace App\Shared\Push;

/**
 * PushTransport — the one thing that talks to a push provider.
 *
 * An interface so the provider is a binding, not a dependency of everything
 * that notifies: tests bind a recorder, a server without Firebase credentials
 * binds LogPushTransport, and the FCM implementation is bound once the
 * Firebase project is decided (work order §3, D8).
 */
interface PushTransport
{
    /**
     * Push $message to every token in $tokens.
     *
     * Throws on a transient failure, so the queue retries — do not swallow it.
     *
     * @param  array<int, string>  $tokens
     * @return array<int, string> the tokens the provider says are permanently
     *                            invalid (app uninstalled, token rotated) —
     *                            the caller deletes them
     */
    public function send(array $tokens, PushMessage $message): array;
}
