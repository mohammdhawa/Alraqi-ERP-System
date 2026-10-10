<?php

declare(strict_types=1);

namespace App\Shared\Push;

use Illuminate\Support\Facades\Log;

/**
 * The transport bound until Firebase is configured: it writes what it would
 * have pushed to the log and pushes nothing.
 *
 * Deliberately not silent. A notification row still reaches the app's list,
 * so a missing transport would otherwise be noticed only as "nobody's phone
 * buzzes" — the log line is where that starts.
 */
final class LogPushTransport implements PushTransport
{
    public function send(array $tokens, PushMessage $message): array
    {
        Log::info('Push not sent: no push transport is configured.', [
            'devices' => count($tokens),
            'title'   => $message->title,
            'type'    => $message->data['type'] ?? null,
        ]);

        return [];
    }
}
