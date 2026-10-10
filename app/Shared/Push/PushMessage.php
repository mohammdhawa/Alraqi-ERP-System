<?php

declare(strict_types=1);

namespace App\Shared\Push;

/**
 * What a push says — the visible title and body, and the data the app routes
 * a tap by.
 *
 * `data` values are strings because FCM's data payload accepts nothing else.
 * It carries the notification's `type` (a NotificationEventCatalogue name) and
 * its reference, so a tap on "تمت الموافقة على الإجازة" opens that leave
 * request (mapping §8: the app deep-links from the reference).
 */
final readonly class PushMessage
{
    /**
     * @param  array<string, string>  $data
     */
    public function __construct(
        public string $title,
        public ?string $body = null,
        public array $data = [],
    ) {}
}
