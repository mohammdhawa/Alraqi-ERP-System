<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Enums\Sector;
use App\Modules\Departments\Models\Department;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The real Al-Raqi organizational tree.
 *
 * SOURCE OF RECORD
 *   File   Program_HR_2_2026.xlsx
 *   Sheet  دليل الهيكل والمسميات الوظيفية  (columns: أسماء الإدارات | القسم | المكتب)
 *   Read   2026-08-05
 *   Cross-checked visually against هيكلية شركة الراقي للانشاءات.pptx (15 slides).
 *
 * WHEN THE SOURCES DISAGREE
 *   - Division names: the sheet تقرير الأعداد للموظفين writes إدارة الشؤون المالية
 *     and إدارة الشؤون الإعلامية where دليل الهيكل writes الإدارة المالية and
 *     إدارة التسويق. دليل الهيكل wins — it is the structural reference; the
 *     other sheet is a headcount report.
 *   - Production units (مجبل الإسفلت, معمل البلوك, …): the chart draws them as
 *     "وحدات" boxes, the HR sheet gives them قسم rank. The HR sheet wins.
 *   - تقنية المعلومات: the chart names the division إدارة التسويق وتقنية المعلومات
 *     with a قسم تقنية المعلومات under it; the HR sheet has neither. The chart
 *     wins (project owner's ruling, 2026-08-05) — it is four months newer. The
 *     HR sheet's قسم الدعم التقني under إدارة الشؤون الإدارية is a separate unit
 *     and stays.
 *
 * IDEMPOTENT, and keyed on `code` rather than `name`: names repeat across the
 * company — الحسابات and أمانة الصندوق each appear under three different
 * divisions — so a name key would collapse them into one row and re-parent it on
 * every run. `code` is derived from the path through the tree (FIN, FIN-CASH,
 * FIN-CASH-VAULT), which is stable, unique, and is what the employee import will
 * match on later, when matching by name would be a coin flip.
 *
 * WRITES THROUGH THE MODEL, never DB::table(). The hierarchy invariants live in
 * Department's saving hook, and a seeder writing around them is precisely how an
 * earlier seeder produced four roots without erroring. Every one of these rows
 * passes the same DepartmentHierarchyGuard the API enforces.
 *
 * `manager_id` IS DELIBERATELY UNSET, including on the root, whose manager is
 * المدير العام (docs/phase-1.5-foundation-truth-up.md §4.3). Setting it would
 * mean inventing an employee, and the real staff arrive by import. The column
 * stays NULL until they do.
 */
class DepartmentSeeder extends Seeder
{
    /**
     * The single root. Its code prefixes nothing — the divisions start their own
     * namespaces — because a shared prefix on all 118 rows would carry no
     * information.
     */
    private const ROOT_CODE = 'RAQI';

    private const ROOT_NAME = 'شركة الراقي للإنشاءات';

    /**
     * Seed the whole tree in one transaction: a run that fails halfway leaves no
     * half-built company behind, which matters because the guard rejects rows
     * whose parent is missing and a partial tree would fail differently on the
     * retry than on the first attempt.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $root = Department::query()->updateOrCreate(
                ['code' => self::ROOT_CODE],
                [
                    'name'      => self::ROOT_NAME,
                    'parent_id' => null,
                    'level'     => DepartmentLevel::root()->value,
                    'sector'    => null,
                    'is_active' => true,
                ],
            );

            $this->seedBranch($this->governance(), $root);
            $this->seedBranch($this->divisions(), $root);
        });
    }

    /**
     * Seed one tier of children under $parent, then recurse.
     *
     * The level is DERIVED from the parent's rather than declared per node: the
     * data says what the tree looks like, DepartmentLevel says how deep that
     * puts each row. A literal level on every node would be the same fact
     * written twice, and the two would disagree the first time a branch moved.
     *
     * @param  array<int, array{code: string, name: string, sector?: Sector, active?: bool, children?: array<int, array<string, mixed>>}>  $nodes
     */
    private function seedBranch(array $nodes, Department $parent): void
    {
        if ($nodes === []) {
            return;
        }

        $level = $parent->level->next();

        if (! $level instanceof DepartmentLevel) {
            throw new RuntimeException(sprintf(
                'Cannot seed children under %s (%s): it is the deepest defined tier.',
                $parent->name,
                $parent->level->label(),
            ));
        }

        foreach ($nodes as $node) {
            $unit = Department::query()->updateOrCreate(
                ['code' => $node['code']],
                [
                    'name'      => $node['name'],
                    'parent_id' => $parent->id,
                    'level'     => $level->value,
                    'sector'    => ($node['sector'] ?? null)?->value,
                    'is_active' => $node['active'] ?? true,
                ],
            );

            $this->seedBranch($node['children'] ?? [], $unit);
        }
    }

    /**
     * Governance units — level 2, beside the divisions rather than above them
     * (docs/phase-1.5-foundation-truth-up.md §4.3).
     *
     * The chart draws them on top of the divisions, which would make them a tier
     * of their own and give the tree two roots. Placing them at level 2 with a
     * NULL sector keeps the single-root invariant Phase 1 fought for while
     * giving the board and the GM's office somewhere real to live. مكتب المدير
     * العام is also what the new-hire intake form calls `a13` and treats as a
     * division, so the form needs no special case at import time.
     *
     * NULL sector, not a third case: neither unit is in either box on slide 1.
     *
     * @return array<int, array<string, mixed>>
     */
    private function governance(): array
    {
        return [
            ['code' => 'BOD', 'name' => 'مجلس الإدارة'],
            ['code' => 'GM', 'name' => 'مكتب المدير العام'],
        ];
    }

    /**
     * The thirteen divisions, in the two groups slide 1 draws them in.
     *
     * @return array<int, array<string, mixed>>
     */
    private function divisions(): array
    {
        return [
            ['code' => 'FIN', 'name' => 'الإدارة المالية', 'sector' => Sector::AdministrativeOffices],
            ['code' => 'HR', 'name' => 'إدارة الموارد البشرية', 'sector' => Sector::AdministrativeOffices],
            ['code' => 'ADM', 'name' => 'إدارة الشؤون الإدارية', 'sector' => Sector::AdministrativeOffices],
            ['code' => 'PR', 'name' => 'إدارة العلاقات العامة', 'sector' => Sector::AdministrativeOffices],
            ['code' => 'MKT', 'name' => 'إدارة التسويق وتقنية المعلومات', 'sector' => Sector::AdministrativeOffices],
            ['code' => 'SEC', 'name' => 'إدارة الأمن والحماية', 'sector' => Sector::AdministrativeOffices],
            ['code' => 'ARC', 'name' => 'إدارة الديوان والأرشيف', 'sector' => Sector::AdministrativeOffices],
            ['code' => 'LEG', 'name' => 'إدارة الشؤون القانونية', 'sector' => Sector::AdministrativeOffices],

            ['code' => 'ENG', 'name' => 'الإدارة الهندسية', 'sector' => Sector::AdministrativeBodies],
            ['code' => 'PRD', 'name' => 'إدارة المشاريع الإنتاجية', 'sector' => Sector::AdministrativeBodies],
            ['code' => 'HEQ', 'name' => 'إدارة الآليات الثقيلة', 'sector' => Sector::AdministrativeBodies],
            ['code' => 'SUP', 'name' => 'إدارة الإمداد والتوريد', 'sector' => Sector::AdministrativeBodies],
            ['code' => 'REM', 'name' => 'إدارة التسويق العقاري', 'sector' => Sector::AdministrativeBodies],
        ];
    }
}
