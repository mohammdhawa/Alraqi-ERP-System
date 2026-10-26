<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

/**
 * The first password of an account someone else created — the import's and
 * the one `POST /api/hr/employees` makes (EMPLOYEE_ACCOUNT_ON_CREATE_DESIGN.md).
 * Read off a screen or a sheet and typed on a phone, so it has no look-alikes:
 * no 0/O, no 1/l/I. The account must change it at first sign-in.
 */
final class TemporaryPassword
{
    public const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public const LENGTH = 10;

    public static function generate(): string
    {
        $password = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $password .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $password;
    }
}
