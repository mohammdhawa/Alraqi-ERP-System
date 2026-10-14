<?php

declare(strict_types=1);

namespace App\Shared\Push;

use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Messaging\SendReport;
use RuntimeException;

/**
 * Push through Firebase Cloud Messaging (work order §4.3), with
 * kreait/firebase-php — the library the legacy backend already uses, against
 * the same Firebase project (D8), so the Flutter app's existing FCM setup
 * receives these pushes unchanged.
 *
 * Bound in place of LogPushTransport when Firebase credentials are configured
 * (`FIREBASE_CREDENTIALS`, see AppServiceProvider).
 *
 * ───────────────────────────────────────────────────────────────────────────
 * WHAT IS RETRIED
 * ───────────────────────────────────────────────────────────────────────────
 * FCM answers per device. The job is retried only when the send reached NO
 * device and found no dead token either — an outage, bad credentials, no
 * network — because then a retry duplicates nothing. A partial failure is
 * logged and not retried: the devices that got it would get it twice.
 *
 * Dead tokens — unknown to FCM (the app was uninstalled) or not a valid token
 * at all — are returned, and the job deletes them.
 */
final class FcmPushTransport implements PushTransport
{
    public function __construct(
        private readonly Messaging $messaging,
    ) {}

    public function send(array $tokens, PushMessage $message): array
    {
        $report = $this->messaging->sendMulticast(
            CloudMessage::new()
                ->withNotification(Notification::create($message->title, $message->body))
                // `click_action` is what the legacy Flutter handler routes a
                // tap on; kept so the re-pointed app opens notifications as
                // it does today.
                ->withData([...$message->data, 'click_action' => 'FLUTTER_NOTIFICATION_CLICK']),
            $tokens,
        );

        $dead = array_values(array_unique([...$report->unknownTokens(), ...$report->invalidTokens()]));
        $delivered = $report->successes()->count();
        $failed = $report->failures()->count();

        if ($delivered === 0 && $dead === [] && $failed > 0) {
            $first = $report->failures()->getItems()[0];

            throw new RuntimeException(
                'FCM delivered to no device: '.($first instanceof SendReport ? $first->error()?->getMessage() : 'unknown error'),
            );
        }

        if ($failed > count($dead)) {
            Log::warning('FCM push partly failed; not retried, to avoid duplicates.', [
                'delivered' => $delivered,
                'failed'    => $failed - count($dead),
                'dead'      => count($dead),
                'type'      => $message->data['type'] ?? null,
            ]);
        }

        return $dead;
    }
}
