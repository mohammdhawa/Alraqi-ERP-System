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
 * EXCLUDED FROM THE TREE (ratified decision #6 — they are not organizational
 * units, and admitting them would take the tree past four tiers):
 *   - Every ورشة* row under إدارة المشاريع الإنتاجية. Job titles, not units.
 *   - The 15 rows under قسم السائقين (باكر, تركس, بلدوزر, لودر, رافعة, صهريج,
 *     مدحلة, كليدر, …). Equipment classes a driver is qualified on — asset
 *     categories, not offices. قسم السائقين therefore has no offices at all.
 *   - The row قسم الصيانة appearing as an OFFICE inside
 *     قسم الصيانة (رحبة الصيانة). A data-entry duplicate of its own parent.
 *
 * NOT EXCLUDED, though it looks like a duplicate: قسم الشؤون القانونية under
 * إدارة التسويق العقاري, which repeats the name of the standalone
 * إدارة الشؤون القانونية division. Both sources show it, and the tree follows
 * the sources (project owner's ruling, 2026-08-05). It is seeded as REM-LEG.
 *
 * NAMES NORMALIZED ON THE WAY IN (the source spelling is in the comment):
 *   الإستعلامات → الاستعلامات · قسم العقود والإتفاقيات → قسم العقود والاتفاقيات
 *   الكميرات → الكاميرات · اللوجستك → اللوجستيك · trailing spaces stripped
 *   (`قسم الصيانة  `, `البوفيه `). No unit name is translated: they are Arabic.
 *
 * EXPECTED SHAPE: 13 divisions · 53 sections · 50 offices, 119 rows with the
 * root and the two governance units. DepartmentSeederTest asserts it.
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
 * `manager_id` IS DELIBERATELY UNSET here, including on the root, whose manager
 * is المدير العام (docs/phase-1.5-foundation-truth-up.md §4.3). Setting it would
 * mean inventing an employee, and the real staff arrive by import. The column
 * stays NULL until they do. (DevDepartmentSeeder does point units at invented
 * staff, so a development database shows managers this seeder never set.)
 *
 * ── THE ROOT, AND WHY IT IS NOT NAMED AFTER THE COMPANY ──────────────────────
 *
 * §4.3 of the ratified spec puts a row called `شركة الراقي للإنشاءات` at level 1.
 * That row is in neither source artefact: the org chart's top box is
 * مجلس الإدارة, and the HR sheet lists مجلس الإدارة and المدير العام as two
 * values in its أسماء الإدارات column. The company-as-an-entity row was the
 * price of flattening the chart's top into four tiers.
 *
 * Read literally, the chart is five deep — مجلس الإدارة → المدير العام →
 * إدارة → قسم → مكتب — and five tiers means renumbering `level` on every row,
 * moving the sector rule off level 2, and revising the published API contract.
 * Too much bought for one relationship.
 *
 * So the root is `الإدارة العامة`, headed by المدير العام (project owner's
 * ruling, 2026-08-05). It removes the invented entity, and it makes the tier
 * label and the row name finally agree — the tier was always called
 * الإدارة العامة while the only row at it was named something else.
 *
 * ONE RELATIONSHIP IS KNOWINGLY WRONG, and this is the only place it is
 * recorded: `مجلس الإدارة` sits UNDER the administration the GM heads, whereas
 * in reality the board oversees him. `parent_id` here is administrative
 * containment, not a line of authority.
 *
 * It is affordable because the board is a container and nothing else: it holds
 * members who carry a job title (`عضو مجلس إدارة`, grade 1) and draw pay, no
 * unit reports to it, and no approval escalates to it (project owner, same
 * ruling — this also settles §9 open question 2: board members ARE `employees`
 * rows, which is why the unit has to exist at all).
 *
 * **Whoever writes the escalation walk must skip BOD.** Nothing terminates
 * there. If that ever changes — if a decision genuinely needs board sign-off —
 * this compromise expires and the five-tier shape becomes the correct one.
 */
class DepartmentSeeder extends Seeder
{
    /**
     * The single root. Its code prefixes nothing — the divisions start their own
     * namespaces — because a shared prefix on all 119 rows would carry no
     * information.
     *
     * The name matches DepartmentLevel::GeneralAdministration's label exactly,
     * and that is the point: the tier is called الإدارة العامة, so the one row
     * at that tier should be it. See the class docblock for what this replaced.
     */
    private const ROOT_CODE = 'GA';

    private const ROOT_NAME = 'الإدارة العامة';

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
     * Governance units — level 2, beside the divisions rather than above them.
     *
     * مجلس الإدارة is here for one reason: its members are `employees`, and an
     * employee needs a `department_id`. It is a container, not an authority
     * node — see the class docblock for the relationship that is knowingly
     * inverted by placing it at this level, and for why that is affordable.
     *
     * مكتب المدير العام is the GM's office STAFF, which is a different thing
     * from الإدارة العامة (the root) being the administration he heads. The
     * new-hire intake form calls it `a13` and treats it as a division, so
     * keeping it at level 2 means the import needs no special case for it.
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
     * The thirteen divisions with their sections and offices, in the two groups
     * slide 1 draws them in.
     *
     * Written as one nested literal rather than thirteen methods because the
     * shape of the company IS the point of this file, and a reader checking it
     * against the HR sheet needs to see the tree, not follow calls.
     *
     * @return array<int, array<string, mixed>>
     */
    private function divisions(): array
    {
        return [
            // ------------------------------------------- المكاتب الإدارية (8)
            [
                'code' => 'FIN', 'name' => 'الإدارة المالية', 'sector' => Sector::AdministrativeOffices,
                'children' => [
                    ['code' => 'FIN-CASH', 'name' => 'قسم النقدية', 'children' => [
                        ['code' => 'FIN-CASH-VAULT', 'name' => 'أمانة الصندوق'],
                        ['code' => 'FIN-CASH-BANK', 'name' => 'الحسابات المصرفية'],
                    ]],
                    ['code' => 'FIN-GL', 'name' => 'قسم الحسابات العامة', 'children' => [
                        ['code' => 'FIN-GL-ACC', 'name' => 'الحسابات العام'],
                    ]],
                    ['code' => 'FIN-BUD', 'name' => 'قسم الموازنات'],
                    ['code' => 'FIN-FA', 'name' => 'قسم الأصول الثابتة', 'children' => [
                        ['code' => 'FIN-FA-MON', 'name' => 'المراقبة والتقييم والجرد'],
                        ['code' => 'FIN-FA-ACC', 'name' => 'حسابات الأصول'],
                    ]],
                    ['code' => 'FIN-AUD', 'name' => 'قسم الرقابة المالية والتدقيق', 'children' => [
                        ['code' => 'FIN-AUD-CTRL', 'name' => 'الرقابة المالية'],
                        ['code' => 'FIN-AUD-AUD', 'name' => 'التدقيق المالي'],
                    ]],
                ],
            ],
            [
                'code' => 'HR', 'name' => 'إدارة الموارد البشرية', 'sector' => Sector::AdministrativeOffices,
                'children' => [
                    ['code' => 'HR-JA', 'name' => 'قسم التحليل والتوصيف'],
                    ['code' => 'HR-PLAN', 'name' => 'قسم التخطيط'],
                    ['code' => 'HR-REC', 'name' => 'قسم الاستقطاب والتوظيف'],
                    ['code' => 'HR-TRN', 'name' => 'قسم التدريب والتطوير'],
                    ['code' => 'HR-PERF', 'name' => 'قسم تقييم الأداء'],
                    ['code' => 'HR-DATA', 'name' => 'قسم إدارة البيانات'],
                ],
            ],
            [
                'code' => 'ADM', 'name' => 'إدارة الشؤون الإدارية', 'sector' => Sector::AdministrativeOffices,
                'children' => [
                    ['code' => 'ADM-VEH', 'name' => 'قسم المركبات', 'children' => [
                        ['code' => 'ADM-VEH-FUEL', 'name' => 'المحروقات'],
                        ['code' => 'ADM-VEH-MNT', 'name' => 'صيانة الآليات'],
                    ]],
                    ['code' => 'ADM-SRV', 'name' => 'قسم الخدمات', 'children' => [
                        // source: الإستعلامات
                        ['code' => 'ADM-SRV-INFO', 'name' => 'الاستعلامات'],
                        // source: "البوفيه " with a trailing space
                        ['code' => 'ADM-SRV-CAF', 'name' => 'البوفيه'],
                    ]],
                    ['code' => 'ADM-IT', 'name' => 'قسم الدعم التقني'],
                ],
            ],
            [
                'code' => 'PR', 'name' => 'إدارة العلاقات العامة', 'sector' => Sector::AdministrativeOffices,
                'children' => [
                    ['code' => 'PR-LOC', 'name' => 'قسم العلاقات المحلية', 'children' => [
                        ['code' => 'PR-LOC-TEND', 'name' => 'مكتب المناقصات'],
                        ['code' => 'PR-LOC-CS', 'name' => 'مكتب خدمة الزبائن'],
                    ]],
                    ['code' => 'PR-EXT', 'name' => 'قسم العلاقات الخارجية', 'children' => [
                        ['code' => 'PR-EXT-TRN', 'name' => 'الترجمة'],
                    ]],
                ],
            ],
            [
                'code' => 'MKT', 'name' => 'إدارة التسويق وتقنية المعلومات', 'sector' => Sector::AdministrativeOffices,
                'children' => [
                    ['code' => 'MKT-DIG', 'name' => 'قسم التسويق الرقمي'],
                    ['code' => 'MKT-MED', 'name' => 'القسم الإعلامي'],
                    // From the chart only; absent from the HR sheet. See the
                    // class docblock — the chart is four months newer.
                    ['code' => 'MKT-IT', 'name' => 'قسم تقنية المعلومات'],
                ],
            ],
            [
                'code' => 'SEC', 'name' => 'إدارة الأمن والحماية', 'sector' => Sector::AdministrativeOffices,
                'children' => [
                    ['code' => 'SEC-PROT', 'name' => 'قسم الحماية', 'children' => [
                        // source: الكميرات
                        ['code' => 'SEC-PROT-CAM', 'name' => 'الكاميرات'],
                        ['code' => 'SEC-PROT-GRD', 'name' => 'الحراسة'],
                    ]],
                ],
            ],
            [
                'code' => 'ARC', 'name' => 'إدارة الديوان والأرشيف', 'sector' => Sector::AdministrativeOffices,
                'children' => [
                    ['code' => 'ARC-MAIL', 'name' => 'قسم البريد'],
                    ['code' => 'ARC-GEN', 'name' => 'قسم الأرشيف العام'],
                ],
            ],
            [
                'code' => 'LEG', 'name' => 'إدارة الشؤون القانونية', 'sector' => Sector::AdministrativeOffices,
                'children' => [
                    // source: قسم العقود والإتفاقيات
                    ['code' => 'LEG-CON', 'name' => 'قسم العقود والاتفاقيات'],
                    ['code' => 'LEG-CASE', 'name' => 'قسم المعاملات القانونية'],
                ],
            ],

            // ------------------------------------------ الأجسام الإدارية (5)
            [
                'code' => 'ENG', 'name' => 'الإدارة الهندسية', 'sector' => Sector::AdministrativeBodies,
                'children' => [
                    ['code' => 'ENG-SUP', 'name' => 'قسم الرقابة الهندسية'],
                    ['code' => 'ENG-PLAN', 'name' => 'قسم التخطيط والدراسات', 'children' => [
                        ['code' => 'ENG-PLAN-TOPO', 'name' => 'الطبوغرافيا'],
                        ['code' => 'ENG-PLAN-ARCH', 'name' => 'المعماري'],
                        ['code' => 'ENG-PLAN-STRU', 'name' => 'الإنشائي'],
                        ['code' => 'ENG-PLAN-MECH', 'name' => 'الميكانيك'],
                        ['code' => 'ENG-PLAN-ELEC', 'name' => 'الكهرباء'],
                    ]],
                    ['code' => 'ENG-ADM', 'name' => 'القسم الإداري الهندسي', 'children' => [
                        ['code' => 'ENG-ADM-ARC', 'name' => 'مكتب الديوان والأرشيف'],
                    ]],
                    ['code' => 'ENG-FIN', 'name' => 'القسم المالي الهندسي', 'children' => [
                        ['code' => 'ENG-FIN-ACC', 'name' => 'الحسابات'],
                        ['code' => 'ENG-FIN-VAULT', 'name' => 'أمانة الصندوق'],
                        ['code' => 'ENG-FIN-DATA', 'name' => 'إدخال البيانات'],
                    ]],
                    ['code' => 'ENG-EXEC', 'name' => 'القسم التنفيذي الهندسي', 'children' => [
                        ['code' => 'ENG-EXEC-TECH', 'name' => 'المكتب الفني'],
                        ['code' => 'ENG-EXEC-WS', 'name' => 'مكتب الورشات الفنية'],
                    ]],
                ],
            ],
            [
                'code' => 'PRD', 'name' => 'إدارة المشاريع الإنتاجية', 'sector' => Sector::AdministrativeBodies,
                'children' => [
                    ['code' => 'PRD-RES', 'name' => 'قسم الأبحاث والدراسات'],
                    // The chart draws these as "وحدات" boxes; the HR sheet gives
                    // them قسم rank, and the HR sheet is the structural source.
                    ['code' => 'PRD-ASPH', 'name' => 'قسم مجبل الإسفلت'],
                    ['code' => 'PRD-CONC', 'name' => 'قسم مجبل البيتون'],
                    ['code' => 'PRD-CWS', 'name' => 'قسم ورش البيتون'],
                    ['code' => 'PRD-INTL', 'name' => 'قسم معمل الأنترلوك'],
                    ['code' => 'PRD-BLK', 'name' => 'قسم معمل البلوك'],
                    ['code' => 'PRD-QSAL', 'name' => 'قسم مقلع السلامة'],
                    ['code' => 'PRD-QISK', 'name' => 'قسم مقلع الإسكان'],
                    ['code' => 'PRD-CRSH', 'name' => 'قسم الكسارة الراوترية'],
                    ['code' => 'PRD-LATH', 'name' => 'قسم مخرطة الحجر'],
                    ['code' => 'PRD-SAW', 'name' => 'قسم منشرة الحجر'],
                    // source: "قسم الصيانة  " with trailing spaces
                    ['code' => 'PRD-MNT', 'name' => 'قسم الصيانة', 'children' => [
                        ['code' => 'PRD-MNT-MECH', 'name' => 'ميكانيك'],
                        ['code' => 'PRD-MNT-ELEC', 'name' => 'كهرباء'],
                        ['code' => 'PRD-MNT-SMTH', 'name' => 'حدادة'],
                    ]],
                    ['code' => 'PRD-FIN', 'name' => 'القسم المالي الإنتاجي', 'children' => [
                        ['code' => 'PRD-FIN-ACC', 'name' => 'الحسابات'],
                        ['code' => 'PRD-FIN-VAULT', 'name' => 'أمانة الصندوق'],
                    ]],
                    ['code' => 'PRD-ADM', 'name' => 'القسم الإداري الإنتاجي'],
                    ['code' => 'PRD-MKT', 'name' => 'قسم تسويق الإنتاج'],
                ],
            ],
            [
                'code' => 'HEQ', 'name' => 'إدارة الآليات الثقيلة', 'sector' => Sector::AdministrativeBodies,
                'children' => [
                    ['code' => 'HEQ-SUP', 'name' => 'قسم الإشراف الفني'],
                    ['code' => 'HEQ-MNT', 'name' => 'قسم الصيانة (رحبة الصيانة)', 'children' => [
                        ['code' => 'HEQ-MNT-MECH', 'name' => 'ميكانيك الثقيل'],
                        ['code' => 'HEQ-MNT-ELEC', 'name' => 'كهرباء الثقيل'],
                        ['code' => 'HEQ-MNT-BODY', 'name' => 'كومجي الثقيل'],
                        ['code' => 'HEQ-MNT-SMTH', 'name' => 'حدادة الثقيل'],
                        ['code' => 'HEQ-MNT-WASH', 'name' => 'مغسلة الثقيل'],
                        ['code' => 'HEQ-MNT-HYD', 'name' => 'صيانة هدروليك الثقيل'],
                        ['code' => 'HEQ-MNT-PRESS', 'name' => 'مكبس هدروليك الثقيل'],
                        ['code' => 'HEQ-MNT-LATH', 'name' => 'طورنو الثقيل'],
                    ]],
                    ['code' => 'HEQ-FIN', 'name' => 'القسم المالي والإداري للآليات الثقيلة', 'children' => [
                        ['code' => 'HEQ-FIN-ACC', 'name' => 'الحسابات'],
                        ['code' => 'HEQ-FIN-VAULT', 'name' => 'أمانة الصندوق'],
                    ]],
                    // No offices: its 15 source rows are equipment classes.
                    ['code' => 'HEQ-DRV', 'name' => 'قسم السائقين'],
                ],
            ],
            [
                'code' => 'SUP', 'name' => 'إدارة الإمداد والتوريد', 'sector' => Sector::AdministrativeBodies,
                'children' => [
                    ['code' => 'SUP-PUR', 'name' => 'قسم المشتريات', 'children' => [
                        ['code' => 'SUP-PUR-PROJ', 'name' => 'مشتريات المشاريع'],
                        ['code' => 'SUP-PUR-SUB', 'name' => 'المشتريات الفرعية'],
                        // Both sources mark it "معلق لحين الاجتماع". Seeded
                        // inactive rather than omitted (project owner's ruling,
                        // 2026-08-05): the row is out of the selection lists,
                        // and activating it after the meeting is a data change
                        // rather than an edit to this file.
                        ['code' => 'SUP-PUR-SALE', 'name' => 'المبيعات', 'active' => false],
                    ]],
                    // source: اللوجستك والمستودعات
                    ['code' => 'SUP-LOG', 'name' => 'قسم اللوجستيك والمستودعات', 'children' => [
                        ['code' => 'SUP-LOG-CENT', 'name' => 'المستودع المركزي'],
                        ['code' => 'SUP-LOG-SUB', 'name' => 'المستودعات الفرعية'],
                        ['code' => 'SUP-LOG-LOG', 'name' => 'اللوجستي'],
                    ]],
                ],
            ],
            [
                'code' => 'REM', 'name' => 'إدارة التسويق العقاري', 'sector' => Sector::AdministrativeBodies,
                'children' => [
                    ['code' => 'REM-PROM', 'name' => 'قسم الترويج والإعلان', 'children' => [
                        ['code' => 'REM-PROM-CS', 'name' => 'مكتب خدمة الزبائن'],
                        ['code' => 'REM-PROM-ADV', 'name' => 'مكتب الدعاية والإعلان'],
                    ]],
                    ['code' => 'REM-FIN', 'name' => 'القسم المالي للتسويق العقاري'],
                    // Duplicates the name of the standalone إدارة الشؤون
                    // القانونية division, and is kept anyway: both sources show
                    // it, and the tree follows the sources (project owner's
                    // ruling, 2026-08-05). This is the case that justifies
                    // keying everything on `code` — REM-LEG and LEG are two
                    // different units whose names alone cannot tell them apart.
                    ['code' => 'REM-LEG', 'name' => 'قسم الشؤون القانونية'],
                ],
            ],
        ];
    }
}
