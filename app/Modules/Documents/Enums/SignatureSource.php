<?php

declare(strict_types=1);

namespace App\Modules\Documents\Enums;

/**
 * Where a signature was drawn (D24: in the app or on the dashboard).
 */
enum SignatureSource: int
{
    case App       = 1;
    case Dashboard = 2;

    public function label(): string
    {
        return match ($this) {
            self::App       => 'التطبيق',
            self::Dashboard => 'لوحة التحكم',
        };
    }
}
