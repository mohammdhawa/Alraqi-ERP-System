<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Services;

use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\NotificationService;
use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Enums\ShiftChangeOrigin;
use App\Modules\Scheduling\Exceptions\SchedulingException;
use App\Modules\Scheduling\Models\Shift;
use App\Modules\Scheduling\Models\ShiftChange;
use App\Shared\Support\NotificationEventCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * HR's one-off changes (work shifts piece 2, spec §5): the one writer of
 * origin-HR rows. A request is one transaction with the person's row locked —
 * any refused date refuses it all — and the check-ins it touches are matched
 * again inside it (§5.3). The person is told when the change reaches today or
 * later (§5.4).
 */
class ShiftChanger
{
    public function __construct(
        private readonly ScheduleResolver $resolver,
        private readonly CheckInRematch $rematch,
        private readonly AttendanceCalendar $calendar,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @return Collection<int, ShiftChange>
     *
     * @throws SchedulingException
     */
    public function record(Employee $employee, string $from, string $to, ?Shift $shift, string $reason, User $by): Collection
    {
        $dates = $this->dates($from, $to);

        $changes = DB::transaction(function () use ($employee, $from, $to, $dates, $shift, $reason, $by): Collection {
            Employee::query()->whereKey($employee->getKey())->lockForUpdate()->first();

            $held = ShiftChange::query()->forEmployees([(int) $employee->getKey()])->between($from, $to)
                ->where('origin', ShiftChangeOrigin::Swap)->orderBy('date')->first();
            if ($held !== null) {
                throw new SchedulingException("{$held->date->toDateString()}: هذا اليوم ضمن تبديل مناوبة معتمد؛ يجب إلغاء التبديل أولاً.");
            }

            $violation = $this->resolver->runViolation($employee, array_fill_keys($dates, $shift?->getKey() === null ? null : (int) $shift->getKey()));
            if ($violation !== null) {
                throw new SchedulingException($violation);
            }

            $changes = collect($dates)->map(fn (string $date): ShiftChange => $this->write($employee, $date, $shift, $reason, $by));
            $this->rematch->run($employee, $from, $to);

            return $changes;
        });

        if ($to >= $this->calendar->today()) {
            $what = $shift?->name ?? 'يوم راحة';
            $body = $from === $to ? "تم تعديل ورديتك ليوم {$from}: {$what}" : "تم تعديل ورديتك من {$from} إلى {$to}: {$what}";
            $this->notify($employee, 'تعديل الوردية', $body, NotificationEventCatalogue::SHIFT_CHANGE_RECORDED, $changes->first());
        }

        return $changes;
    }

    /** @throws SchedulingException */
    public function remove(ShiftChange $change, User $by): void
    {
        if ($change->origin === ShiftChangeOrigin::Swap) {
            throw new SchedulingException('هذا التعديل ناتج عن تبديل مناوبة؛ يُلغى بإلغاء التبديل.');
        }

        $employee = Employee::withTrashed()->findOrFail($change->employee_id);
        $date = $change->date->toDateString();

        DB::transaction(function () use ($employee, $change, $date): void {
            Employee::query()->whereKey($employee->getKey())->lockForUpdate()->first();
            $change->delete();
            $this->rematch->run($employee, $date, $date);
        });

        if ($date >= $this->calendar->today()) {
            $this->notify($employee, 'إلغاء تعديل الوردية', "عادت ورديتك ليوم {$date} إلى جدولك.", NotificationEventCatalogue::SHIFT_CHANGE_REMOVED, $employee);
        }
    }

    private function write(Employee $employee, string $date, ?Shift $shift, string $reason, User $by): ShiftChange
    {
        $change = ShiftChange::query()->where('employee_id', $employee->getKey())->whereDate('date', $date)->first()
            ?? new ShiftChange(['employee_id' => $employee->getKey(), 'date' => $date, 'origin' => ShiftChangeOrigin::Hr]);

        $change->fill(['shift_id' => $shift?->getKey(), 'reason' => $reason, 'created_by' => $by->getKey()])->save();

        return $change;
    }

    /** @return list<string> */
    private function dates(string $from, string $to): array
    {
        $dates = [];
        $timezone = $this->calendar->timezone();
        for ($day = CarbonImmutable::parse($from, $timezone), $last = CarbonImmutable::parse($to, $timezone); $day->lte($last); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    private function notify(Employee $employee, string $title, string $body, string $type, ?Model $reference): void
    {
        $account = User::query()->where('employee_id', $employee->getKey())->value('id');
        if ($account !== null) {
            $this->notifications->sendToUser((int) $account, $title, $body, type: $type, reference: $reference);
        }
    }
}
