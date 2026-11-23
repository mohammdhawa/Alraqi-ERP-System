<?php

declare(strict_types=1);

namespace App\Modules\Documents\Enums;

/**
 * Between managers: Pending, then Approved or Rejected, or NotCompleted when
 * the system administrator closes a stuck one (§7.8). To an employee:
 * AwaitingSignature, then Signed or Refused (documents design §6.1).
 */
enum DocumentStatus: int
{
    case Pending           = 1;
    case Approved          = 2;
    case Rejected          = 3;
    case AwaitingSignature = 4;
    case Signed            = 5;
    case Refused           = 6;
    case NotCompleted      = 7;

    public function label(): string
    {
        return match ($this) {
            self::Pending           => 'قيد الاعتماد',
            self::Approved          => 'معتمد',
            self::Rejected          => 'مرفوض',
            self::AwaitingSignature => 'بانتظار التوقيع',
            self::Signed            => 'موقّع',
            self::Refused           => 'مرفوض من الموظف',
            self::NotCompleted      => 'لم يُستكمل',
        };
    }
}
