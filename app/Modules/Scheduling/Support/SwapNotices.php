<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Support;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\NotificationService;
use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Models\ShiftSwap;
use App\Shared\Support\NotificationEventCatalogue;

/**
 * What the two guards are told, and when (work shifts piece 2, spec §6.5).
 * The partner is told, never asked (S13). Someone without an active account
 * is skipped. Every notice references the exchange, so a tap opens it.
 */
final class SwapNotices
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function filed(ShiftSwap $swap): void
    {
        $requester = $swap->requester;
        $partner = $swap->partner;
        $mine = $swap->requester_duty_date->toDateString();
        $theirs = $swap->partner_duty_date->toDateString();

        $this->tell($requester, 'تم تقديم طلب تبديل المناوبة', "طلبك لتبديل مناوبة {$mine} مع {$partner->full_name} بانتظار اعتماد المدير المباشر.", NotificationEventCatalogue::SHIFT_SWAP_SUBMITTED, $swap);
        $this->tell($partner, 'طلب تبديل مناوبة معك', "طلب {$requester->full_name} تبديل مناوبة {$theirs} معك مقابل مناوبته {$mine}.", NotificationEventCatalogue::SHIFT_SWAP_REQUESTED, $swap);
    }

    public function withdrawn(ShiftSwap $swap): void
    {
        $this->tell($swap->partner, 'سحب طلب تبديل المناوبة', "سحب {$swap->requester->full_name} طلب تبديل المناوبة معك.", NotificationEventCatalogue::SHIFT_SWAP_WITHDRAWN, $swap);
    }

    /** The manager's decision, to both. */
    public function decided(ShiftSwap $swap, bool $approved): void
    {
        $requester = $swap->requester;
        $partner = $swap->partner;

        if ($approved) {
            $this->tell($requester, 'تمت الموافقة على تبديل المناوبة', "تمت الموافقة على تبديل مناوبتك {$swap->requester_duty_date->toDateString()} مع {$partner->full_name}.", NotificationEventCatalogue::SHIFT_SWAP_APPROVED, $swap);
            $this->tell($partner, 'تمت الموافقة على تبديل المناوبة', "تمت الموافقة على تبديل مناوبتك {$swap->partner_duty_date->toDateString()} مع {$requester->full_name}.", NotificationEventCatalogue::SHIFT_SWAP_APPROVED, $swap);

            return;
        }

        $body = "تم رفض طلب تبديل المناوبة بين {$requester->full_name} و{$partner->full_name}.";
        $this->tell($requester, 'تم رفض تبديل المناوبة', $body, NotificationEventCatalogue::SHIFT_SWAP_REJECTED, $swap);
        $this->tell($partner, 'تم رفض تبديل المناوبة', $body, NotificationEventCatalogue::SHIFT_SWAP_REJECTED, $swap);
    }

    /** HR's cancellation, to both. */
    public function cancelled(ShiftSwap $swap): void
    {
        $body = "ألغت الموارد البشرية تبديل المناوبة بين {$swap->requester->full_name} و{$swap->partner->full_name}.";
        $this->tell($swap->requester, 'تم إلغاء تبديل المناوبة', $body, NotificationEventCatalogue::SHIFT_SWAP_CANCELLED, $swap);
        $this->tell($swap->partner, 'تم إلغاء تبديل المناوبة', $body, NotificationEventCatalogue::SHIFT_SWAP_CANCELLED, $swap);
    }

    private function tell(Employee $employee, string $title, string $body, string $type, ShiftSwap $swap): void
    {
        $account = User::query()->where('employee_id', $employee->getKey())->value('id');
        if ($account !== null) {
            $this->notifications->sendToUser((int) $account, $title, $body, type: $type, reference: $swap);
        }
    }
}
