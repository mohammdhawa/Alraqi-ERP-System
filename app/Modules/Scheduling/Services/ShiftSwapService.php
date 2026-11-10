<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Services;

use App\Modules\Approvals\Services\ApprovalService;
use App\Modules\Attendance\Contracts\ExpectedShifts;
use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Attendance\Support\Duty;
use App\Modules\Attendance\Support\ExpectedShift;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Resources\EmployeeStub;
use App\Modules\Scheduling\Enums\ShiftSwapStatus;
use App\Modules\Scheduling\Enums\WorkScheduleKind;
use App\Modules\Scheduling\Exceptions\SchedulingException;
use App\Modules\Scheduling\Models\EmployeeWorkSchedule;
use App\Modules\Scheduling\Models\ShiftSwap;
use App\Modules\Scheduling\Support\SwapNotices;
use App\Shared\Services\ManagementChain;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A guard's side of a duty exchange (work shifts piece 2, spec §6.1): what
 * he may exchange, filing, and withdrawing. The engine owns the decision.
 */
class ShiftSwapService
{
    /** How far ahead `options` looks. */
    public const OPTIONS_DAYS = 31;

    public function __construct(
        private readonly ShiftSwapCheck $check,
        private readonly ApprovalService $engine,
        private readonly ExpectedShifts $shifts,
        private readonly ManagementChain $chain,
        private readonly AttendanceCalendar $calendar,
        private readonly SwapNotices $notices,
    ) {}

    /**
     * My duties that start in the next 31 days and have not opened, and each
     * colleague I could exchange with — on a cycle today, the same manager.
     *
     * @return array{my_duties: list<array<string, mixed>>, partners: list<array{employee: mixed, duties: list<array<string, mixed>>}>}
     */
    public function options(Employee $me): array
    {
        $today = $this->calendar->today();
        $manager = $this->chain->resolve($me)?->getKey();

        $candidates = Employee::query()
            ->whereKeyNot($me->getKey())
            ->where(fn ($q) => $q->whereNull('termination_date')->orWhereDate('termination_date', '>=', $today))
            ->whereIn('id', EmployeeWorkSchedule::query()->inForceOn($today)
                ->whereHas('schedule', fn ($q) => $q->where('kind', WorkScheduleKind::Cycle))
                ->select('employee_id'))
            ->orderBy('first_name')->orderBy('id')
            ->get();
        $managers = $manager === null ? [] : $this->chain->attachTo(new Collection($candidates->all()));
        $partners = $candidates->filter(fn (Employee $e): bool => $manager !== null && ($managers[$e->getKey()] ?? null)?->getKey() === $manager)->values();

        $duties = $this->upcoming(array_merge([(int) $me->getKey()], $partners->modelKeys()));

        return [
            'my_duties' => $duties[(int) $me->getKey()] ?? [],
            'partners'  => $partners->map(fn (Employee $e): array => [
                'employee' => EmployeeStub::from($e),
                'duties'   => $duties[(int) $e->getKey()] ?? [],
            ])->all(),
        ];
    }

    /**
     * @param  array{my_duty_date: string, partner_id: int, partner_duty_date: string, reason?: ?string}  $data
     *
     * @throws SchedulingException
     */
    public function file(Employee $requester, User $by, array $data): ShiftSwap
    {
        $swap = DB::transaction(function () use ($requester, $by, $data): ShiftSwap {
            // Both rows, in ascending id order, so two exchanges of the same
            // duty filed at once are judged one after the other.
            $ids = [(int) $requester->getKey(), (int) $data['partner_id']];
            sort($ids);
            Employee::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();

            $swap = new ShiftSwap([
                'requester_id'        => $requester->getKey(),
                'partner_id'          => (int) $data['partner_id'],
                'requester_duty_date' => $data['my_duty_date'],
                'partner_duty_date'   => $data['partner_duty_date'],
                'reason'              => isset($data['reason']) && trim((string) $data['reason']) !== '' ? trim((string) $data['reason']) : null,
                'status'              => ShiftSwapStatus::Pending,
                'created_by'          => $by->getKey(),
            ]);

            $violation = $this->check->violation($swap, true);
            if ($violation !== null) {
                throw new SchedulingException($violation);
            }

            $swap->save();
            $this->engine->submit($swap, ShiftSwapApprovalRoute::make());

            return $swap;
        });

        $this->notices->filed($swap);

        return $swap->fresh();
    }

    /** The requester's withdrawal while pending, through the engine (§6.1). */
    public function withdraw(ShiftSwap $swap, User $by): ShiftSwap
    {
        if ($swap->status !== ShiftSwapStatus::Pending || (int) $by->employee_id !== (int) $swap->requester_id || $swap->approval === null) {
            throw new SchedulingException('لا يمكن سحب هذا الطلب.');
        }

        $this->engine->withdraw($swap->approval, $by);

        return $swap->fresh();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, list<array<string, mixed>>>
     */
    private function upcoming(array $ids): array
    {
        $timezone = $this->calendar->timezone();
        $today = CarbonImmutable::parse($this->calendar->today(), $timezone);
        $now = CarbonImmutable::now($timezone);
        $opens = max(0, (int) config('attendance.check_in_opens_minutes', 60));
        $close = max(0, (int) config('attendance.close_after_minutes', 240));
        $last = $today->addDays(self::OPTIONS_DAYS)->toDateString();

        // From six days back, so a run is cut where every reader cuts it.
        $all = $this->shifts->shiftsFor($ids, $today->subDays(6)->toDateString(), $today->addDays(self::OPTIONS_DAYS + Duty::MAX_SHIFTS)->toDateString());

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = [];
            foreach (Duty::runs(array_values($all[$id] ?? []), $opens, $close) as $duty) {
                if ($duty->shiftDate() >= $today->toDateString() && $duty->shiftDate() <= $last && $duty->opensAt->gt($now)) {
                    $out[$id][] = self::present($duty);
                }
            }
        }

        return $out;
    }

    /** @return array{date: string, starts_at: string, ends_at: string, shifts: list<array<string, string>>} */
    public static function present(Duty $duty): array
    {
        return [
            'date'      => $duty->shiftDate(),
            'starts_at' => $duty->startsAt()->toIso8601String(),
            'ends_at'   => $duty->endsAt()->toIso8601String(),
            'shifts'    => array_map(static fn (ExpectedShift $s): array => $s->toArray(), $duty->shifts),
        ];
    }
}
