<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use App\Modules\HR\Models\Employee;

/**
 * EmployeeStub
 *
 * The ONE shape every embedded reference to a person takes, wherever a resource
 * in any module carries an FK to `employees`:
 *
 *   "manager": { "id": 12, "full_name": "سامر خليل", "employee_number": "E-0012" }
 *
 * WHY IT EXISTS: the same three lines were about to be written four times — the
 * department's manager, the user's employee, the employee's direct manager, and
 * the warning's issuer. Four copies is four chances for one of them to embed a
 * different field set, and a client that learns "a person object has these
 * keys" from one endpoint would then be wrong about another (P1: one fact, one
 * place).
 *
 * WHY `employee_number` IS IN IT and not just id + name: at this headcount two
 * people genuinely share الاسم الثلاثي, so a name alone does not identify anyone
 * on screen. The staff number is what tells them apart, and a client that had to
 * fetch it separately would be issuing a request per row to render a list.
 *
 * NOT GATED. C11 gates `national_id` and the pay block; a person's name and
 * staff number are neither. Anyone holding a read permission on the OWNING
 * resource — the department, the user account, the employee — may see who the
 * referenced person is, or the FK they can already read would be a dead end.
 *
 * ⚠️ NULL IS THE ONLY EMPTY VALUE. A caller passes the resolved relation, so
 * `null` here means "no such person" — the FK is null, or it points at a
 * soft-deleted employee, which is the same statement to a client that cannot
 * fetch that row either. The KEY IS NEVER OMITTED: in this API an absent key
 * means "you may not see this field" (C11), and a relation someone forgot to
 * eager-load must not borrow that meaning. See C31.
 *
 * CROSS-MODULE ON PURPOSE. Auth and Departments both import this from HR, the
 * same deliberate direction as `Department::manager()` pointing at
 * `HR\Models\Employee`: other aggregates hold references to people, HR owns what
 * a person IS. The dependency must not be inverted — nothing in HR may import a
 * shape from Auth or Departments to build this.
 */
final class EmployeeStub
{
    /**
     * Build the embedded object for one person, or null when there is none.
     *
     * Takes the RESOLVED relation rather than an id: this class must never
     * query. The caller (a Resource) reads a relation its own caller eager-
     * loaded — see the eager-load notes on each of the three call sites.
     *
     * @return array{id: int, full_name: string, employee_number: string}|null
     */
    public static function from(?Employee $employee): ?array
    {
        if ($employee === null) {
            return null;
        }

        return [
            'id'              => (int) $employee->getKey(),
            'full_name'       => $employee->full_name,
            'employee_number' => $employee->employee_number,
        ];
    }
}
