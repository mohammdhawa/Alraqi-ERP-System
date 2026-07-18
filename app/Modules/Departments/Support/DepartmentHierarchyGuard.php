<?php

declare(strict_types=1);

namespace App\Modules\Departments\Support;

use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Models\Department;

/**
 * DepartmentHierarchyGuard
 *
 * The SINGLE owner of the departments tree's structural invariants. Every rule
 * the schema cannot express lives here exactly once:
 *
 *   - defined-level      the level must map to a DepartmentLevel case.
 *   - root tier          a parentless unit must be الإدارة العامة (level 1).
 *   - singleton root     at most one row may be parentless. MySQL has no
 *                        partial index, so this is application-layer only.
 *   - child tier         a parented unit sits exactly one tier below its
 *                        parent (parent.level + 1), and nothing may nest under
 *                        the deepest defined tier.
 *   - no self-reference  a unit cannot be its own parent (update only).
 *   - no cycle           a unit cannot move under one of its own descendants
 *                        (update only).
 *
 * WHY a standalone guard rather than logic in the form requests:
 * - Before this, the tier rules lived only in DepartmentRequest — the HTTP
 *   layer. Any non-HTTP writer (seeder, tinker, job, factory) bypassed them
 *   entirely, which is exactly how the seeder created four roots. The rules had
 *   to move to a layer every write path crosses.
 * - The logic must exist ONCE. Both entry points call this guard:
 *     * Form requests translate each violation into a 422 validator error,
 *       keeping the Arabic field-level UX.
 *     * The Department model's `saving` hook throws DepartmentHierarchyException
 *       on the first violation — the same standard the soft-delete children
 *       guard already set (a model hook that covers every path).
 *
 * Operates on primitives (level, parent_id, subject id) so it is coupled to
 * neither HTTP nor Eloquent events, and is trivially unit-testable.
 */
final class DepartmentHierarchyGuard
{
    /**
     * All invariants broken by a unit that would end up at $level under
     * $parentId. $subjectId is the row's own id on update, or null on create
     * (there is no row yet to self-reference, close a loop, or exclude from the
     * singleton-root count).
     *
     * @return list<HierarchyViolation>
     */
    public function violations(?int $level, ?int $parentId, ?int $subjectId): array
    {
        // The level must resolve to a defined tier before any tier maths can
        // run. (The form request's Rule::enum catches this first over HTTP;
        // this branch is what protects the model-layer path.)
        if ($level === null || ! DepartmentLevel::isDefined($level)) {
            return [new HierarchyViolation('level', 'المستوى المحدد غير معرّف في هيكل الشركة.')];
        }

        // Root position: no parent.
        if ($parentId === null) {
            $violations = [];
            $root = DepartmentLevel::root();

            if ($level !== $root->value) {
                $violations[] = new HierarchyViolation('level', sprintf(
                    'الوحدة التنظيمية بدون وحدة أصل يجب أن تكون %s (المستوى %d).',
                    $root->label(),
                    $root->value,
                ));
            }

            // Singleton root: another parentless row already claims the trunk.
            if ($this->anotherRootExists($subjectId)) {
                $violations[] = new HierarchyViolation(
                    'parent_id',
                    'يوجد بالفعل وحدة جذر (الإدارة العامة) في هيكل الشركة، ولا يمكن إنشاء أكثر من جذر واحد.',
                );
            }

            return $violations;
        }

        // Rule 5: a unit cannot be its own parent (only possible on update).
        if ($subjectId !== null && $parentId === $subjectId) {
            return [new HierarchyViolation('parent_id', 'لا يمكن أن تكون الوحدة التنظيمية تابعة لنفسها.')];
        }

        // Rule 6 (no cycle): checked BEFORE loading the parent because it walks
        // raw parent_id pointers — no level cast — so it stays valid even if a
        // parent sits at a not-yet-defined tier, and it is the dominant error
        // when a move is both cyclic and mis-tiered.
        if ($subjectId !== null && $this->wouldCreateCycle($parentId, $subjectId)) {
            return [new HierarchyViolation(
                'parent_id',
                'لا يمكن نقل الوحدة التنظيمية لتصبح تابعة لإحدى الوحدات الفرعية التابعة لها.',
            )];
        }

        // find() respects the SoftDeletes scope, so a trashed parent reads as
        // missing — re-parenting a live unit under a deleted one is rejected.
        $parent = Department::find($parentId);

        if (! $parent instanceof Department) {
            return [new HierarchyViolation('parent_id', 'الوحدة التنظيمية الأصل غير موجودة أو تم حذفها.')];
        }

        // Rule 2 (child tier): exactly one tier below the parent — no skipping,
        // and nothing may nest under the deepest defined tier.
        $expected = $parent->level->next();

        if (! $expected instanceof DepartmentLevel) {
            return [new HierarchyViolation('parent_id', sprintf(
                'لا يمكن إضافة وحدات فرعية تحت %s لأنها أدنى مستوى في هيكل الشركة.',
                $parent->level->label(),
            ))];
        }

        if ($level !== $expected->value) {
            return [new HierarchyViolation('level', sprintf(
                'الوحدة التابعة لـ%s يجب أن تكون %s (المستوى %d).',
                $parent->level->label(),
                $expected->label(),
                $expected->value,
            ))];
        }

        return [];
    }

    /**
     * Whether a parentless department other than $subjectId already exists.
     * Uses the default (non-trashed) scope: a soft-deleted row does not hold
     * the root slot — though the root is undeletable, so this is belt-and-braces.
     */
    private function anotherRootExists(?int $subjectId): bool
    {
        return Department::query()
            ->whereNull('parent_id')
            ->when($subjectId !== null, fn ($query) => $query->whereKeyNot($subjectId))
            ->exists();
    }

    /**
     * Whether making $parentId the parent of $subjectId would close a loop.
     *
     * Walks UP from the candidate parent: if $subjectId appears among its
     * ancestors, the candidate sits below the subject — the exact condition we
     * reject. O(depth), which DepartmentLevel bounds. The visited set stops the
     * walk from hanging if a cycle ever reached the data by some other path.
     */
    private function wouldCreateCycle(int $parentId, int $subjectId): bool
    {
        $visited = [];
        $ancestorId = $parentId;

        while ($ancestorId !== null && ! isset($visited[$ancestorId])) {
            if ($ancestorId === $subjectId) {
                return true;
            }

            $visited[$ancestorId] = true;
            $parentValue = Department::whereKey($ancestorId)->value('parent_id');
            $ancestorId = $parentValue === null ? null : (int) $parentValue;
        }

        return false;
    }
}
