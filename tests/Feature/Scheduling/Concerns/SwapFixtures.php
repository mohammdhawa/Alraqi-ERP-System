<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling\Concerns;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Enums\ShiftSwapStatus;
use App\Modules\Scheduling\Models\ShiftSwap;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Modules\Scheduling\Services\ScheduleAssigner;
use Database\Seeders\SchedulingSeeder;
use Tests\Feature\Leave\Concerns\LeaveFixtures;

/**
 * The guards the exchange tests trade duties between (work shifts piece 2),
 * on leave's tree: guardA and guardB work in A1, whose post is vacant, so
 * both report to headA; guardC works in B, whose manager is the GM.
 *
 * With the clock at 2026-11-13 10:00 — guardA's cycle starts 11-14 (duties
 * 11-14, 11-20, 11-26 …), guardB's and guardC's 11-17 (11-17, 11-23 …):
 * opposite teams.
 */
trait SwapFixtures
{
    use LeaveFixtures;

    protected Employee $guardA;

    protected Employee $guardB;

    protected Employee $guardC;

    protected function buildGuards(): void
    {
        $this->seed(SchedulingSeeder::class);
        $this->buildLeaveTree();

        $this->guardA = $this->person($this->units['a1']);
        $this->guardB = $this->person($this->units['a1']);
        $this->guardC = $this->person($this->units['b']);

        $this->assignGuard($this->guardA, '2026-11-14');
        $this->assignGuard($this->guardB, '2026-11-17');
        $this->assignGuard($this->guardC, '2026-11-17');
    }

    protected function assignGuard(Employee $employee, string $cycleStart): void
    {
        app(ScheduleAssigner::class)->assign($employee, WorkSchedule::where('code', 'GUARD')->sole(), '2026-11-13', $cycleStart, User::factory()->create());
    }

    /** An unsaved exchange, for the check. */
    protected function exchange(Employee $requester, string $requesterDate, Employee $partner, string $partnerDate): ShiftSwap
    {
        return new ShiftSwap([
            'requester_id'        => $requester->id,
            'partner_id'          => $partner->id,
            'requester_duty_date' => $requesterDate,
            'partner_duty_date'   => $partnerDate,
            'status'              => ShiftSwapStatus::Pending,
        ]);
    }
}
