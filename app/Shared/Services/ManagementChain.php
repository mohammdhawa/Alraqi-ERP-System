<?php

declare(strict_types=1);

namespace App\Shared\Services;

use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Collection;

/**
 * ManagementChain — who is this person's manager?
 *
 * ✅ THE WALK THE PROJECT DID NOT HAVE. Until this existed the documentation
 * called the escalation chain "structure without a walk": the tree, the manager
 * pointers and a guaranteed terminus (المدير العام at the root) all existed, and
 * no code climbed them.
 *
 * ⚙️ **THE ORG TREE IS THE ONLY SOURCE, since 2026-09-24.** There is no stored
 * manager column at all. `employees.direct_manager_id` existed to record an
 * EXCEPTION — someone reporting outside their unit head — and the project owner
 * ratified that the company has no such exception: a manager is always the one
 * the hierarchy gives. So the column was dropped rather than left nullable and
 * unused, and rule 1 below went with it. A column for a case that does not occur
 * is a second copy of what `departments.manager_id` already says, waiting to
 * disagree with it (P1).
 *
 * THE RULES ARE RATIFIED, NOT INVENTED (project owner, 2026-09-23 — decision 15
 * in START_HERE.md §9 — amended 2026-09-24):
 *
 *   1. The manager of the employee's own unit.
 *   2. If that post is VACANT, or the manager IS the person we are asking
 *      about, climb to the nearest ancestor unit that has a manager.
 *   3. Terminate at المدير العام. The root always has a manager in a healthy
 *      tree, so the walk normally ends there rather than falling off the top.
 *   4. Manual reassignment stays available on top of this, as the legacy system
 *      already provides. Nothing here forbids a human override; there is simply
 *      no approvals engine yet to hold one.
 *
 * ⚙️ ONE CASE RULE 2 COVERS WITHOUT SAYING SO: **a post nobody holds is
 * vacant.** A `manager_id` pointing at a SOFT-DELETED employee is exactly that
 * — the pointer survives the person, the post does not — so the walk climbs.
 * Returning null instead would say "this person has no manager", which is a
 * claim about the org chart and not about a departed colleague.
 *
 * ⚙️ AND A UNIT NOBODY CAN SEE IS NOT A DEAD END (2026-09-24). An office can be
 * soft-deleted while staff still point at it. Its post is vacant — a deleted
 * unit has no manager — so the walk climbs PAST it to the live ancestor that
 * does, the division head GAP-3 already treats as responsible for those staff
 * (`Department::subtreeIds()` walks trashed units for the same reason). Until
 * then the walk started from `$employee->department`, which the soft-delete
 * scope resolves to null, so it never began and the API reported "no manager"
 * for exactly the people whose unit had just been dissolved.
 *
 * ONE METHOD, NOT TWO. This was specified as a service with two entry points —
 * "who is my manager" for the app's home screen and "who approves this" for the
 * Stage 2 approvals engine. Building it showed they are the same question: the
 * self-skip in rule 2 is exactly what a display needs too, because a unit head
 * asking who their manager is wants their own superior, not themselves. The
 * distinction was dropped rather than encoded, and this note is here so nobody
 * re-adds it looking for a difference that was never there.
 *
 * WHERE IT LIVES. `app/Shared` because two modules need it — HR renders the
 * resolved manager today, and Approvals will walk the same chain in Stage 2.
 * Two copies of an org-chart walk would drift, and a drifting answer to "who
 * approves this" is a request routed to the wrong person.
 */
class ManagementChain
{
    /**
     * The manager of one employee, or null at the top of the tree.
     *
     * ⚠️ COSTS QUERIES PER CALL. For anything rendering more than one person,
     * use `attachTo()` — this method is the single-row path, and calling it in
     * a loop is the N+1 that method exists to prevent.
     */
    public function resolve(Employee $employee): ?Employee
    {
        // An ELOQUENT collection, not a support one: `attachTo()` is typed on
        // it, and `collect()` would hand back a plain collection.
        $resolved = $this->attachTo(new Collection([$employee]));

        return $resolved[$employee->getKey()] ?? null;
    }

    /**
     * Resolve the manager of every employee in the collection, in a fixed
     * number of queries, and set it on each (`Employee::resolvedManager()`).
     *
     * THE COST IS BOUNDED BY THE TIER COUNT, NOT BY THE PAGE SIZE. The walk is
     * done in memory over an eager-loaded ancestor chain — the same technique
     * `Department::scopeWithAncestors()` uses for `sector` — and every
     * candidate manager on the page is fetched in ONE query. A page of fifteen
     * employees costs the same handful of queries as a page of one. Whoever
     * hands these models to a Resource owes them this call.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, Employee|null> keyed by employee id
     */
    public function attachTo(Collection $employees): array
    {
        if ($employees->isEmpty()) {
            return [];
        }

        $units = $this->unitsWithAncestors($employees);

        $candidatesPerEmployee = [];

        foreach ($employees as $employee) {
            $unit = $employee->department_id === null ? null : ($units[(int) $employee->department_id] ?? null);
            $candidatesPerEmployee[$employee->getKey()] = $this->candidateIds($employee, $unit);
        }

        $managers = $this->fetchManagers($candidatesPerEmployee);

        $resolved = [];

        foreach ($employees as $employee) {
            $manager = null;

            foreach ($candidatesPerEmployee[$employee->getKey()] as $candidateId) {
                // A candidate absent from the map is a pointer at somebody who
                // has left: the post is vacant, so keep climbing (rule 3).
                $found = $managers[$candidateId] ?? null;

                if ($found !== null && $found->getKey() !== $employee->getKey()) {
                    $manager = $found;
                    break;
                }
            }

            $employee->setResolvedManager($manager);
            $resolved[$employee->getKey()] = $manager;
        }

        return $resolved;
    }

    /**
     * Every unit the page's employees sit in, with its ancestor chain, keyed
     * by id — soft-deleted units INCLUDED at every level.
     *
     * Loaded independently of `$employee->department` on purpose. That
     * relation hides trashed units (correctly, for what the Resource renders),
     * and the walk must not: it needs to climb through a dissolved office to
     * the division above it. The ancestor path is derived from the tier enum,
     * so adding a tier does not leave this walk one hop short; the cost is one
     * query for the units plus one per ancestor level, whatever the page size.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, Department>
     */
    private function unitsWithAncestors(Collection $employees): array
    {
        $ids = $employees->pluck('department_id')->filter()->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        $withTrashed = static fn ($query) => $query->withTrashed();
        $path = '';
        $loads = [];

        foreach (explode('.', Department::ancestorRelationPath()) as $hop) {
            $path = $path === '' ? $hop : "{$path}.{$hop}";
            $loads[$path] = $withTrashed;
        }

        return Department::withTrashed()
            ->whereIn('id', $ids)
            ->with($loads)
            ->get()
            ->keyBy(static fn (Department $unit): int => (int) $unit->getKey())
            ->all();
    }

    /**
     * The ordered ids to try for one employee: each unit's manager, from their
     * own unit upwards.
     *
     * Self-references are dropped here rather than filtered later, so the id
     * list means only "people who could be this person's manager". A trashed
     * unit contributes nothing — its post is vacant — but the walk goes on
     * through it.
     *
     * @return array<int, int>
     */
    private function candidateIds(Employee $employee, ?Department $unit): array
    {
        $candidates = [];

        // Bounded by the tier count plus one, so a cycle introduced by a bad
        // write cannot hang a request. The tree forbids cycles; a walk that
        // trusts that and is wrong once is an outage, and a walk that checks is
        // four comparisons.
        $hops = count(DepartmentLevel::cases()) + 1;

        while ($unit instanceof Department && $hops-- > 0) {
            if (! $unit->trashed() && $unit->manager_id !== null && (int) $unit->manager_id !== $employee->getKey()) {
                $candidates[] = (int) $unit->manager_id;
            }

            $unit = $unit->parent;
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Every candidate manager on the page, in one query, keyed by id.
     *
     * Soft-deleted employees are excluded by the model's global scope, which is
     * the point: a pointer at a departed person must read as a vacant post, and
     * their simply not being here is how that happens.
     *
     * @param  array<int, array<int, int>>  $candidatesPerEmployee
     * @return array<int, Employee>
     */
    private function fetchManagers(array $candidatesPerEmployee): array
    {
        $ids = array_values(array_unique(array_merge(...array_values($candidatesPerEmployee))));

        if ($ids === []) {
            return [];
        }

        return Employee::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy(static fn (Employee $employee): int => (int) $employee->getKey())
            ->all();
    }
}
