<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * EmployeeAssignmentService
 *
 * Writes سجل التعيين — the posting history. Reading it belongs to
 * EmployeeService, because a person's history is a read of their own record;
 * this class owns the one operation that changes it.
 *
 * WHY A DEDICATED WRITE PATH when the Employee model already records a posting
 * change automatically: the automatic one can only ever infer "something
 * changed", so it writes نقل داخلي dated today. A real HR decision has a REASON
 * (ترقية، تعديل مسمى، إعادة هيكلة), an EFFECTIVE DATE that is frequently not
 * today, and usually a note recording the decision number. Forcing those
 * through the employee endpoint would mean either losing them or smuggling
 * history fields onto a person's profile.
 *
 * THE HISTORY IS APPEND-ONLY. There is no update and no delete here, and no
 * `hr.assignments.update` permission exists to call one. A posting recorded
 * wrongly is corrected by recording the correction — the way a ledger is —
 * because editing last year's posting changes what a payslip was computed
 * against, after the fact and without a trace of the original.
 */
class EmployeeAssignmentService
{
    /**
     * Record a posting change: close the current posting and open a new one.
     *
     * ───────────────────────────────────────────────────────────────────────
     * THE DATE ARITHMETIC, WHICH IS THE WHOLE JOB
     * ───────────────────────────────────────────────────────────────────────
     * The outgoing posting is closed the day BEFORE the new one starts, not on
     * the same day. `EmployeeAssignment::scopeOnDate()` matches a posting when
     * `from_date <= D AND (to_date IS NULL OR to_date >= D)`, so closing the old
     * row on the new row's start date would make BOTH match on that date — and
     * "which department was this person in on 1 October?" would have two
     * answers. Payroll asks that question for every employee every month.
     *
     * RETROACTIVE AND SAME-DAY CHANGES are an amendment, not a transfer. If the
     * new posting starts on or before the open one began, closing the old row
     * would date it to end before it started (which its own guard refuses, and
     * rightly). There is no history to preserve in that case — the open posting
     * never described a period that actually elapsed — so it is amended in
     * place.
     *
     * CONCURRENCY: the "at most one open posting" invariant is checked in the
     * model and then written, so two simultaneous transfers could both pass the
     * check. The employee row is locked for the duration, which serialises them;
     * the second then sees the first's committed row and is refused. Like the
     * department locks, this is a real guarantee on MySQL and a no-op under the
     * SQLite test driver, where correctness is by construction instead.
     *
     * ⚠️ AN OMITTED FIELD CARRIES FORWARD; ONLY AN EXPLICIT NULL CLEARS. This is
     * the difference between a partial update and a replacement, and getting it
     * wrong here is silent data loss rather than an error: a promotion states the
     * new grade, a تعديل مسمى states the new title, and neither restates the
     * department. Read as a replacement, that posting would record the person as
     * belonging to no unit at all — and because `syncEmployeePointer()` pushes
     * the row onto the employee, the pointer columns would be cleared too. There
     * is no update route to repair it with, the history being append-only.
     *
     * `array_key_exists` rather than `??` is what draws that line: `?? ` cannot
     * tell "the caller said null" from "the caller said nothing", and those are
     * the two cases that must behave differently.
     *
     * @param  array<string, mixed>  $data
     */
    public function record(Employee $employee, array $data): EmployeeAssignment
    {
        return DB::transaction(function () use ($employee, $data): EmployeeAssignment {
            // The locked row is also the one the carry-forward reads, so an
            // omitted field inherits committed state rather than whatever the
            // caller's in-memory instance happened to hold.
            $locked = Employee::whereKey($employee->getKey())->lockForUpdate()->first() ?? $employee;

            $from = Carbon::parse($data['from_date'] ?? now()->toDateString())->startOfDay();
            $open = $employee->currentAssignment()->first();

            $carried = fn (string $field): mixed => array_key_exists($field, $data)
                ? $data[$field]
                : $locked->getAttribute($field);

            $posting = [
                'department_id'        => $carried('department_id'),
                'job_title_id'         => $carried('job_title_id'),
                'organizational_grade' => $carried('organizational_grade'),
                'from_date'            => $from->toDateString(),
                'reason'               => $data['reason'],
                'notes'                => $data['notes'] ?? null,
            ];

            // Amendment: the open posting never covered an elapsed period.
            if ($open instanceof EmployeeAssignment && $open->from_date !== null && $open->from_date->gte($from)) {
                $open->update($posting);

                return $open->refresh();
            }

            if ($open instanceof EmployeeAssignment) {
                $open->update(['to_date' => $from->copy()->subDay()->toDateString()]);
            }

            return $employee->assignments()->create($posting);
        });
    }
}
