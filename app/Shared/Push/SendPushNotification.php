<?php

declare(strict_types=1);

namespace App\Shared\Push;

use App\Modules\Auth\Models\DeviceToken;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Push one notification to every device of its recipients.
 *
 * Dispatched by NotificationService::deliver() AFTER COMMIT: the notification
 * rows are written in the caller's transaction, and a rolled-back approval
 * must not buzz a phone about something that never happened — the rule the
 * legacy job already followed.
 *
 * TOKENS ARE RESOLVED HERE, at send time, not at dispatch: a device whose
 * session ended while the job waited in the queue must not get the push.
 *
 * A transport failure is NOT caught. The queue retries it on the backoff below
 * and, if it keeps failing, it lands in failed_jobs — rather than in a log
 * line nobody reads (the legacy job's reasoning, kept).
 */
final class SendPushNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 180];

    /**
     * @param  array<int, int>  $userIds
     */
    public function __construct(
        public readonly array $userIds,
        public readonly PushMessage $message,
    ) {}

    public function handle(PushTransport $transport): void
    {
        $tokens = DeviceToken::query()
            ->whereIn('user_id', $this->userIds)
            ->pluck('token')
            ->all();

        if ($tokens === []) {
            return;
        }

        $dead = $transport->send($tokens, $this->message);

        if ($dead !== []) {
            DeviceToken::query()->whereIn('token', $dead)->delete();
        }
    }
}
