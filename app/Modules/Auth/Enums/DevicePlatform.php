<?php

declare(strict_types=1);

namespace App\Modules\Auth\Enums;

/**
 * DevicePlatform — what a session or a push token runs on.
 *
 * STRING-BACKED, unlike this codebase's int enums, because the value is sent
 * by clients rather than chosen from a list in the dashboard: the Flutter app
 * reports `android` / `ios` and the dashboard `web`, as the legacy API already
 * accepted them. The column stores what the client said; the enum is the
 * allow-list.
 *
 * Shared by `refresh_tokens.platform` (a session's device) and
 * `device_tokens.platform` (where a push goes) — one vocabulary for both.
 */
enum DevicePlatform: string
{
    case Android = 'android';
    case Ios     = 'ios';
    case Web     = 'web';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
