<?php

declare(strict_types=1);

namespace App\Modules\HR\Support;

use App\Modules\HR\Enums\AssignmentReason;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeAssignment;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * HireDateGuard — `hire_date` and the posting history say one thing (GAP-19).
 *
 * The opening تعيين posting is dated FROM the hire: Employee::recordPostingChange()
 * writes it that way. Before this guard, only its creation read `hire_date`, so
 * a later correction left the two disagreeing — the history said a person was
 * posted before they were hired, or, corrected the other way, that they were
 * employed on days they sat in no unit. "Which unit were they in on D" is the
 * question payroll asks for every employee every month, and the Batch 4
 * importer re-runs against HR corrections, so every hire date fixed between
 * runs would have gone stale in the history (P1: two records of one fact).
 *
 * THE RULE, two halves of one invariant — no posting starts before the hire:
 *
 *   1. A `hire_date` correction MOVES the opening تعيين posting with it, earlier
 *      or later — that posting was dated from the hire, so it is exactly what
 *      the correction corrects. It is refused when the move would cross the
 *      posting's own end (a correction that deep is a history edit, made on
 *      the assignments screen), or when the first posting is not a تعيين and
 *      would then start before the hire.
 *   2. No posting may START before `hire_date` — the other door, where the
 *      assignments endpoint back-dates a posting past the hire.
 *
 * Shared by EmployeeRequest / EmployeeAssignmentRequest (field-level 422s) and
 * by the Employee / EmployeeAssignment saving hooks (the backstop for the
 * importer, seeders and every other writer) — the DepartmentHierarchyGuard
 * shape (C4/C5).
 *
 * WHAT "OPENING" MEANS: the employee's earliest posting, if its reason is
 * تعيين. A first posting recorded under another reason — someone hired with no
 * unit and placed later by a transfer — started on its own date, not the hire,
 * and does not move.
 */
final class HireDateGuard
{
    /**
     * Would changing this employee's hire date to `$newHireDate` break the
     * history? Null when it would not — including for a new employee, whose
     * opening posting is written from the hire date anyway, and for a cleared
     * date, which constrains nothing.
     */
    public function hireDateViolation(Employee $employee, ?CarbonInterface $newHireDate): ?ClassificationViolation
    {
        if (! $employee->exists || $newHireDate === null) {
            return null;
        }

        $first = self::earliestPosting($employee);

        if ($first === null || $first->from_date === null) {
            return null;
        }

        $new = $newHireDate->copy()->startOfDay();

        if (self::followsHireDate($first)) {
            if ($first->to_date !== null && $new->gt($first->to_date)) {
                return new ClassificationViolation(
                    'hire_date',
                    sprintf(
                        'لا يمكن نقل تاريخ التعيين إلى ما بعد نهاية التعيين الأول (%s): التعيين الأول يبدأ من تاريخ التعيين، وتصحيح بهذا العمق يُجرى من سجل التعيين.',
                        $first->to_date->toDateString(),
                    ),
                );
            }

            return null;
        }

        if ($first->from_date->lt($new)) {
            return new ClassificationViolation(
                'hire_date',
                sprintf(
                    'لا يمكن أن يكون تاريخ التعيين بعد بداية أول تعيين مسجّل للموظف (%s).',
                    $first->from_date->toDateString(),
                ),
            );
        }

        return null;
    }

    /**
     * Would a posting starting on `$from` begin before the employee was hired?
     */
    public function postingViolation(?CarbonInterface $hireDate, CarbonInterface $from): ?ClassificationViolation
    {
        if ($hireDate === null || ! $from->copy()->startOfDay()->lt($hireDate->copy()->startOfDay())) {
            return null;
        }

        return new ClassificationViolation(
            'from_date',
            sprintf('تاريخ بدء التعيين لا يمكن أن يسبق تاريخ تعيين الموظف (%s).', $hireDate->toDateString()),
        );
    }

    /**
     * The employee's hire date as stored — read fresh, because the assignment
     * hook asks it of an employee it holds only by id.
     */
    public static function storedHireDate(int $employeeId): ?CarbonInterface
    {
        $raw = Employee::withTrashed()->whereKey($employeeId)->value('hire_date');

        return $raw === null ? null : Carbon::parse($raw)->startOfDay();
    }

    /**
     * The first posting by date; the id breaks a tie deterministically.
     *
     * reorder(), because the relation itself sorts newest first — an appended
     * orderBy() would only break ties and return the LATEST posting.
     */
    public static function earliestPosting(Employee $employee): ?EmployeeAssignment
    {
        return $employee->assignments()->reorder()->orderBy('from_date')->orderBy('id')->first();
    }

    /**
     * Whether this posting is the opening تعيين, dated from the hire.
     */
    public static function followsHireDate(EmployeeAssignment $posting): bool
    {
        return $posting->reason === AssignmentReason::Hire;
    }
}
