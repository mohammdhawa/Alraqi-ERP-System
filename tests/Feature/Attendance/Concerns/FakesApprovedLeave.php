<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance\Concerns;

use App\Modules\Attendance\Contracts\ApprovedLeave;
use App\Modules\Attendance\Contracts\LeaveSpan;
use App\Modules\HR\Models\Employee;

/**
 * Approved leave for attendance tests, until the Leave module exists: each
 * call adds a span to an in-memory ApprovedLeave bound in the container.
 * Attendance sees leave only through that contract, so this is exactly what
 * it will see from the real module.
 */
trait FakesApprovedLeave
{
    /** @var array<int, LeaveSpan> */
    private array $leaveSpans = [];

    protected function grantLeave(
        Employee $employee,
        string $start,
        string $end,
        bool $excuse = false,
        bool $deducts = true,
        string $type = 'annual',
        string $typeName = 'إجازة سنوية',
    ): LeaveSpan {
        $span = new LeaveSpan(
            leaveRequestId: count($this->leaveSpans) + 1,
            employeeId: $employee->id,
            startDate: $start,
            endDate: $end,
            isExcuse: $excuse,
            deductsBalance: $deducts,
            typeCode: $type,
            typeName: $typeName,
            reason: $excuse ? 'عذر مقبول' : null,
            recordedBy: $excuse ? 'موظف الموارد' : null,
            approvedBy: $excuse ? null : 'المدير المباشر',
        );
        $this->leaveSpans[] = $span;

        $spans = $this->leaveSpans;
        $this->app->instance(ApprovedLeave::class, new class($spans) implements ApprovedLeave
        {
            /** @param  array<int, LeaveSpan>  $spans */
            public function __construct(private array $spans) {}

            public function covers(int $employeeId, string $date): bool
            {
                return $this->spans($employeeId === 0 ? [] : [$employeeId], $date, $date) !== [];
            }

            public function spans(array $employeeIds, string $from, string $to): array
            {
                return array_values(array_filter(
                    $this->spans,
                    static fn (LeaveSpan $s): bool => in_array($s->employeeId, $employeeIds, true)
                        && $s->startDate <= $to && $s->endDate >= $from,
                ));
            }
        });

        return $span;
    }
}
