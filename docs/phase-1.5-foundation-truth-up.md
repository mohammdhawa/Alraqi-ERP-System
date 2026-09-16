# Phase 1.5 — Foundation Truth-Up

> **Status:** specification, ratified 2026-07-30. **§4 (Departments) implemented
> 2026-08-05; §5 and §6 (Employees + the supporting tables) implemented
> 2026-09-13** — see the per-section markers below. §7 (the import) not yet
> implemented.
> **§6.1 job-title uniqueness ratified 2026-09-13** (§9 question 4 closed); that
> marker is the one place where a ratified answer *changes* the spec table rather
> than only annotating it.
> **Predecessor:** [phase1-completion-report.md](phase1-completion-report.md)
> **Successor:** [legacy-system-merge-plan.md](legacy-system-merge-plan.md)
>
> The specification text below is **unchanged**. Where implementation resolved
> an open reading or a question the spec left open, that is recorded in the
> section's status marker, not by rewriting the spec.

---

## 1. Why this phase exists

Phase 1 built the foundation from the design document. Then four artefacts from
the company itself were examined:

| Artefact | What it is | Date |
|---|---|---|
| `هيكلية شركة الراقي للانشاءات.pptx` | The official org chart, 15 slides | 2026-07-29 |
| `Program_HR_2_2026.xlsx` | The live HR + payroll workbook, 16 sheets, 242 staff | 2026-02-23 |
| `برنامج الموارد حسب المعايير شهر 9.xlsx` | The same workbook, five months earlier | 2025-10-08 |
| `موظف جديد.xlsx` | The new-hire intake form (XLSForm / Kobo) | 2025-12-11 |

They show that the Phase 1 foundation is **structurally correct but factually
short of the real company**. Three gaps:

1. The org tree stops at `قسم`. The company has a `مكتب` tier below it.
2. `employees` carries 9 business columns. HR tracks roughly 50, and three of
   them are classification axes that drive pay.
3. `employees.salary` is a single decimal. Salary at Al-Raqi is **computed from
   criteria**, not stored as a number.

Phase 1's own completion report makes the argument for fixing this now:

> the remediation batches closed all of them **before any production data
> existed**, which is the cheapest moment such a fix is ever available
> — [phase1-completion-report.md:66](phase1-completion-report.md)

That moment has not passed yet. It passes the day the 242 real employees are
imported. Phase 1.5 is the work that must land *before* that import.

**Phase 1.5 does not build payroll.** It builds the record that payroll will
read. The distinction matters and is enforced throughout §5.

---

## 2. Ratified decisions (2026-07-30)

Six questions were put to the project owner. The answers are binding and the
rest of this document is their consequence.

| # | Question | Answer | Consequence |
|---|---|---|---|
| 1 | Is `الجهة` (الوسطى / التشغيلية) a tier or an attribute? | **Attribute** | No new tier. A `sector` column on `departments` (§4.2) |
| 2 | Is `اللقب` (أبو فلان) an official identifier? | **No — full name only**, first + middle + last | `nickname` never enters the schema. `employees.name` splits into three parts (§5.1) |
| 3 | Is `مكان الراتب` (مكتب رئيسي / جماعة) a payroll grouping? | **No — the company (finance) pays everyone** | Column dropped entirely. One payer, one payroll run (§5.6) |
| 4 | Currency? | **USD only** | No `currency` column anywhere. All money is `decimal(12,2)` USD (§5.6) |
| 5 | Migrate the Access DB (`HR-Raki.accdb`)? | **No — move records manually later** | No ETL from Access. Import source is the Excel workbook only (§7) |
| 6 | Do `ورشة` / `فريق` / machine groupings belong in the org tree? | **No** | They are job titles and asset categories. Max org depth = 4 (§4.1, §7.3) |

---

## 3. Guiding principles — unchanged

Phase 1.5 is bound by the same four principles ([phase1-completion-report.md
§2](phase1-completion-report.md)). Two of them do most of the work here:

- **P1 — one fact, one place.** This is why the name splits into parts rather
  than storing parts *and* a full string; why `سنوات القدم` is never stored
  (it is `hire_date` and today's date); why posting history is a table rather
  than three frozen `initial_*` columns.
- **P4 — data changes, not code.** This is why adding the `مكتب` tier is one
  enum line, exactly as `DepartmentLevel`'s docblock promised it would be.

---

## 4. Departments

### 4.1 The `مكتب` tier

> **✅ Implemented 2026-08-05** — `case Office = 4;` plus its `label()` arm, and
> nothing else: no migration, no `ALTER`. The required grep found one place
> treating `Section` as the leaf — a test asserting `Section->next() === null`,
> now expressed against the enum's case list. `scopeAtLevel()` already existed,
> so no `scopeOffices()` was added.
>
> Found while testing, and worth knowing: the `saving` hook does **not** convert
> an undefined level into `DepartmentHierarchyException` on the ordinary path.
> The enum cast throws `ValueError` inside `setAttribute` first. Nothing invalid
> persists either way; the guard's defined-level branch covers raw-attribute
> writers. The model docblock previously claimed otherwise and was corrected.

Three independent sources agree that a fourth tier exists:

- The PPTX renders offices under sections throughout (`مكتب الطبوغرافيا`,
  `المكتب المعماري`, `مكتب الديوان والأرشيف`, `مكتب خدمة الزبائن`…).
- The intake form cascades `الإدارة → القسم → المكتب → المسمى الوظيفي` — three
  chained `select_one` questions with `choice_filter` on the parent.
- The HR workbook's `قاعدة البيانات` sheet has three org columns, in order:
  `أسماء الإدارات | القسم | المكتب`.

**Change** — [DepartmentLevel.php](../app/Modules/Departments/Enums/DepartmentLevel.php):

```php
case GeneralAdministration = 1;
case Division              = 2;
case Section               = 3;
case Office                = 4;   // مكتب
```

…plus the `label()` arm returning `'مكتب'`. That is the entire schema change.
No migration, no `ALTER`, no downtime — the property the enum was built to
preserve, now cashed in.

Because the enum doubles as the registry of valid depth, max depth becomes 4
automatically. `next()`, `previous()`, validation, and
[DepartmentHierarchyGuard](../app/Modules/Departments/Support/DepartmentHierarchyGuard.php)
all follow without edit.

**Verification required during implementation:** confirm nothing hard-codes
`Section` as the leaf tier. The guard and the form requests are written in
terms of `next()`/`previous()` and should be clean, but a grep for
`DepartmentLevel::Section` is a five-minute check that must not be skipped.
Anything found is a P4 violation and gets rewritten in terms of the enum.

Depth is now final: **4 tiers, no more** (ratified decision #6).

### 4.2 `sector` — الجهة as an attribute

> **✅ Implemented 2026-08-05, with one correction to the reading below.**
>
> **The two cases are not `الوسطى` / `التشغيلية`.** Re-reading the chart during
> implementation: slide 1 is titled `الجهة الوسطى` and shows **all thirteen**
> divisions, and slides 2–15 titled `الجهة التشغيلية` are an internal breakdown
> of those same divisions. Neither is a partition. The real binary split on
> slide 1 is **`المكاتب الإدارية` (8)** vs **`الأجسام الإدارية` (5)**, and that
> is what `Sector` carries (project owner's ruling, 2026-08-05). Everything else
> in this section — attribute not tier, level-2 only, walk up to read, never
> copy down — is implemented exactly as written.
>
> **One rule added beyond the spec.** `DepartmentHierarchyGuard` now refuses a
> stored `sector` on any row that is not level 2. The spec says the value is
> never copied downward but does not say what stops it: `DepartmentResource`
> returns the *resolved* sector, so a client that `PUT`s a section's own payload
> back would store that copy, and — because the walk halts at the first non-null
> ancestor — it would then shadow the true sector from the whole subtree. Owning
> the refusal in the guard rather than the form request keeps it true for the
> seeder, which writes 118 rows.

The chart groups divisions under `الجهة الوسطى` and `الجهة التشغيلية`. Per
ratified decision #1 this is a label, not a level.

**New column** on `departments`:

```php
// الجهة — which side of the company this unit belongs to.
// 1 = الجهة الوسطى, 2 = الجهة التشغيلية. Backed by the Sector PHP enum,
// same pattern as `level`: the int is the fact, the enum is the lens.
$table->unsignedTinyInteger('sector')->nullable()->index();
```

**New enum** `App\Modules\Departments\Enums\Sector` — cases `Middle = 1`
(`الجهة الوسطى`), `Operational = 2` (`الجهة التشغيلية`).

**Where it is declared, and where it is read** — this is the P1 part:

- It is **set on level-2 (`إدارة`) rows only**.
- The root and the governance units (`مجلس الإدارة`) carry `NULL`.
- Sections and offices carry `NULL` and **resolve their sector by walking up
  the tree**. The value is never copied downward.

Copying it to children is the exact drift P1 exists to prevent: move a section
to another division and one column would still name the old sector. A
`Department::sector()` accessor that walks to the nearest ancestor with a
non-null value is the read path. Expose it in `DepartmentResource` as
`sector_label`, alongside the existing `level_label`, so no client re-derives
it — the same reasoning that put `level_label` there.

### 4.3 The top of the tree

> **✅ Implemented 2026-08-05, with the root row changed. Read this before the
> table below.**
>
> **The root is `الإدارة العامة`, headed by المدير العام — not
> `شركة الراقي للإنشاءات`.** That row appears in neither source artefact: the
> chart's top box is `مجلس الإدارة`, and the HR sheet lists `مجلس الإدارة` and
> `المدير العام` as two values in its `أسماء الإدارات` column. It was the price
> of flattening the chart's top into four tiers, and it left the tier label
> (`الإدارة العامة`) disagreeing with the name of the only row at that tier.
>
> Read literally the chart is **five** deep — `مجلس الإدارة` → `المدير العام` →
> `إدارة` → `قسم` → `مكتب`. Five tiers renumbers `level` on every row, moves the
> §4.2 sector rule off level 2, and revises the published API contract: too much
> bought for one relationship. (Note that ratified decision #6's "max depth = 4"
> was a statement about the *bottom* of the tree — the ورشة and machine rows —
> so this is a cost argument, not a rule violation.)
>
> **One relationship is knowingly wrong:** `مجلس الإدارة` sits under the
> administration the GM heads, whereas the board oversees him. `parent_id` there
> is administrative containment, not authority. It is affordable because the
> board is a container and nothing else — **and whoever writes the escalation
> walk must skip it.** If a decision ever genuinely needs board sign-off, this
> compromise expires and the five-tier shape becomes correct.
>
> **This also settles §9 open question 2:** board members ARE `employees` rows
> (project owner, 2026-08-05), which is why the unit must exist at all.
>
> Everything else in the table below is seeded as written by
> [DepartmentSeeder](../database/seeders/DepartmentSeeder.php): the two
> governance units at level 2 with a `NULL` sector, and the thirteen divisions.
> `manager_id` on the root is left `NULL` rather than pointing at المدير العام,
> because setting it would mean inventing an employee row; the real staff arrive
> with the §7.4 import.
>
> Seeded shape: **13 divisions · 53 sections · 50 offices**, 119 rows,
> `parent_id IS NULL` count = 1. Three readings the spec left open were ruled on
> by the project owner (2026-08-05) and are recorded in the seeder's docblock:
> `إدارة التسويق وتقنية المعلومات` follows the chart, with a
> `قسم تقنية المعلومات` under it (§7.1's first discrepancy, resolved in the
> chart's favour); `المبيعات` is seeded `is_active = false` pending its meeting;
> and the `قسم الشؤون القانونية` under `إدارة التسويق العقاري` is **kept**
> despite duplicating the standalone `إدارة الشؤون القانونية` — the governing
> instruction is that the tree matches the source artefacts, including where
> they look wrong to a reader.

The chart shows `مجلس الإدارة` and `المدير العام` above the divisions, but the
intake form lists `المدير العام` as though it were a division (`q1` code `a13`,
containing the job title `مدير مكتب`). Reconciled:

| Row | Level | Sector | Note |
|---|---|---|---|
| `شركة الراقي للإنشاءات` (الإدارة العامة) | 1 | `NULL` | The single root. Its `manager_id` is المدير العام — the approval chain's terminus |
| `مجلس الإدارة` | 2 | `NULL` | Governance, sits beside the divisions rather than above them |
| `مكتب المدير العام` | 2 | `NULL` | Matches the intake form's `a13`; holds `مدير مكتب` |
| the 13 divisions | 2 | Middle / Operational | Per the chart |

This keeps the invariant Phase 1 fought for — exactly one parentless row, at
level 1 — while giving the board and the GM's office somewhere real to live.

---

## 5. Employees

> **✅ Implemented 2026-09-13 (Batch 2)**, by
> [restructure_employees_table.php](../database/migrations/HR/2026_09_13_110000_restructure_employees_table.php).
> The spec below is unchanged; what implementation resolved is recorded here.
>
> **Everything in §5.1–§5.7 landed as written**, plus seven fields the later
> full scan of the HR folder showed the company actually collects and this spec
> did not have: `mother_name`, `phone_secondary`, `nationality` /
> `second_nationality`, `did_military_service` / `military_discharge_date`, and
> `employment_type`.
>
> **Four readings changed, each on evidence:**
>
> 1. **`children_count` became `sons_count` + `daughters_count`.** The intake
>    form counts them separately (عدد الأبناء الذكور / الاناث), so those are the
>    stored facts and the total is an accessor. Storing all three would be the
>    same number twice.
>
> 2. **`governorate` is an enum, not a string** (§5.1 specified `string`), and
>    it split into `origin_governorate` and `residence_governorate`. The HR scan
>    names this exact column as a drift case — حماة versus حماه, 22 distinct
>    spellings of a 14-member set. §6.4's own test for enum-versus-table is
>    whether the set is stable, and the governorates of Syria are a stronger
>    stability claim than the sets already accepted.
>
> 3. ⚠️ **The "mutually exclusive" rule in §5.3 is enforced only halfway, on
>    purpose.** §5.3 says the three experience axes are mutually exclusive by
>    `activity_type`, which reads as forbidding `experience_level` on
>    engineering and industrial staff. The pay matrix in the HR folder
>    contradicts that directly: it carries TWO experience rate tables, one keyed
>    to `إداري/مالي/أمني` and one to `هندسي/صناعي`, so an engineer carries an
>    experience level AND a rank. `EmployeeClassificationGuard` therefore
>    enforces only what both sources agree on — a rank belongs to engineering
>    work, industrial years to industrial work. Enforcing the stricter reading
>    would have rejected genuine rows at import time. **Which table an
>    engineer's pay actually reads is an open question for HR**, recorded in
>    `START_HERE.md` §11; every input it could need is stored either way.
>
> 4. **`status` is now an int-backed enum with `Suspended` (موقوف)** rather than
>    the vague `inactive`, which closes §9 question 3 — and the workbook's
>    `الحالة` turned out to hold a *second* fact (متعاقد / يحتاج تثبيت / جماعة /
>    مثبت), now its own `employment_type` column. The old glossary mapping had
>    been reading one of the two and discarding the other.
>
> **One thing was ruled OUT that the HR scan proposed:** الديانة، المذهب،
> السوابق الجنائية. The intake form collects them; nothing in these requirements
> reads them, so they enter no table (project owner, 2026-09-13).
>
> **Nullability is looser than this spec implies, deliberately.** Almost every
> column is nullable even where the intake form makes it mandatory, because §7.4
> requires the importer to REPORT flawed rows rather than absorb them — which
> means those rows have to be able to land. Completeness is enforced at the API
> front door instead (`EmployeeRequest` requires the name parts and gender).

The current table ([create_employees_table.php](../database/migrations/HR/2026_06_21_110000_create_employees_table.php))
has: `employee_number`, `name`, `national_id`, `phone`, `email`, `address`,
`department_id`, `job_title`, `hire_date`, `salary`, `status`.

Below is the target. **Source** cites where the field was observed: `XLSForm`
= the intake form, `DB-sheet` = the workbook's `قاعدة البيانات` sheet.

### 5.1 Identity

| Column | Type | Source | Notes |
|---|---|---|---|
| `first_name` | string | XLSForm `a1` | الاسم الأول |
| `middle_name` | string | XLSForm `a2` | اسم الأب |
| `last_name` | string | XLSForm `a3` | الكنية |
| `gender` | enum(male, female) | DB-sheet `الجنس` | |
| `birth_date` | date, nullable | XLSForm `a7` | The workbook stores only a year; the intake form collects a full date. Store the date; derive the year |
| `birth_place` | string, nullable | XLSForm `a12` | مكان التولد |
| `governorate` | string, nullable | DB-sheet `المحافظة` | |
| `marital_status` | enum(married, single, widowed, divorced) | XLSForm `a4` | متزوج / عازب / أرمل / مطلق |
| `wives_count` | tinyint, nullable | XLSForm `a5` | Only when married |
| `children_count` | tinyint, nullable | XLSForm `a6` | |
| `national_id` | string, nullable unique | *(exists)* | |
| `phone` | string, nullable | XLSForm `w1` | |
| `email` | string, nullable | *(exists)* | |
| `address` | string, nullable | XLSForm `text_uc47k41` | عنوان الإقامة الحالي |

**`name` is dropped.** The three parts are the fact; `full_name` is an accessor
that joins them. Storing both is two columns for one fact (P1) — and the
workbook proves the failure mode: it carries `الإسم الثلاثي`, `الاسم القديم`,
*and* a `مقارنة الأسماء` column whose only job is to flag where the two
disagree. That column is a bug report about the data model. Do not reproduce it.

`اللقب` does not enter the schema (ratified decision #2).

**Blast radius:** `name` is referenced by `EmployeeResource`, `EmployeeRequest`,
`Employee::$fillable`, the seeders, and the HR feature tests. All must be
updated in the same change. No production data exists, so there is no data
migration — this is the last release in which that is true.

### 5.2 Education

| Column | Type | Source | Notes |
|---|---|---|---|
| `education_level` | enum, 13 cases | XLSForm `q5` | لا يوجد، ابتدائي، تاسع، بكالوريا، سنة 1–5، معهد، كلية، ماجستير، دكتوراه |
| `qualification` | string, nullable | DB-sheet `المؤهل العلمي` | Free text: "كلية اقتصاد سنة 2" |
| `specialization` | string, nullable | XLSForm `a9` | التخصص |
| `is_graduated` | boolean | XLSForm `a10` | |
| `graduation_date` | date, nullable | DB-sheet `تاريخ التخرج الدقيق` | |

`education_level` is an ordered scale that **carries a salary value**
(`قيمة المؤهل العلمي`). The order is load-bearing; back the enum by int, not
string, and keep the cases in ascending order.

### 5.3 Classification — the three axes that drive pay

⚠️ **The single most important correctness note in this document.**

`السوية التنظيمية` is a **position grade**. `departments.level` is a **unit
tier**. Both are integers in the range 1–5. They are not the same fact and must
never be conflated — a section head (`السوية 3`) can sit in a division (`level
2`) row. Conflating them silently corrupts every payslip.

| Column | Type | Source | Notes |
|---|---|---|---|
| `activity_type` | enum(engineering, financial, administrative, industrial, security) | XLSForm `q9` | هندسي / مالي / إداري / صناعي / أمني. Selects which experience axis applies |
| `organizational_grade` | tinyint 1–5 | DB-sheet `السوية التنظيمية` | **Position grade. Not `departments.level`.** |
| `experience_level` | tinyint 1–5, nullable | DB-sheet `الخبرة` | `خبرة 1…5`. Applies when `activity_type` ∈ {administrative, financial, security} |
| `engineering_rank` | enum, 9 cases, nullable | XLSForm `q10` | استشاري، رأي، ممارس، مشارك، مساعد مهندس، مراقب فني، متدرب، ركن، لا يوجد. Applies when `activity_type = engineering` |
| `industrial_experience_years` | smallint, nullable | XLSForm `w6` | Applies when `activity_type = industrial` |

The three experience axes are mutually exclusive by `activity_type`. Enforce
that in the model's `saving` hook, not only in the form request — P2, the
lesson Phase 1 names as load-bearing.

Four further criteria are **flags**, not measurements:

| Column | Type | Source |
|---|---|---|
| `has_hazard_pay` | boolean | DB-sheet `الخطورة` |
| `has_scarcity_pay` | boolean | DB-sheet `الندرة` |
| `has_dual_role_pay` | boolean | DB-sheet `إزدواجية العمل` |
| `distinction_grade` | tinyint, nullable | DB-sheet `التمييز بحسب التقييم` — set by performance review, not by HR directly |

`سنوات القدم` (seniority years) is **not stored**. It is `hire_date` and the
payroll period. Storing it would require a nightly job to keep 242 rows honest,
and the day that job fails every payslip is wrong (P1).

### 5.4 Employment

| Column | Type | Source | Notes |
|---|---|---|---|
| `employee_number` | string, unique | *(exists)* | Keeps the `EMP-00001` generator |
| `legacy_number` | string, nullable unique | DB-sheet `الرقم الوظيفي` | The `M024333` values. Preserved so paper records and the Access DB stay findable after import — see §9 Q1 |
| `hire_date` | date, nullable | *(exists)* | |
| `termination_date` | date, nullable | DB-sheet `تاريخ انهاء العمل` | |
| `status` | enum(active, inactive, terminated) | *(exists)* | `متعاقد` → `active`, `إنهاء عمل` → `terminated` |
| `workplace` | string, nullable | XLSForm `w2` | مكان العمل. Free text now; becomes an FK when Projects lands |
| `direct_manager_id` | FK → employees, nullable | XLSForm `a16` | **NULL means "resolve from the org tree"** |

`direct_manager_id` deserves its rationale. The intake form collects a manager's
*name* as free text; the ERP already knows the org tree and
`departments.manager_id`. Storing a name would be a second copy of a fact the
tree already holds (P1). So: the column exists only to record the **exception**
— someone who reports outside their unit head. NULL, the overwhelmingly common
case, means "walk the tree."

This is a deliberate down payment on the escalation chain that
[phase1-completion-report.md:352](phase1-completion-report.md) flags as
"structure without a walk." It does not implement the walk — the vacant-post
and self-approval rules are still unspecified — but it ensures the walk, when
written, has an override to honour.

### 5.5 Posting history — replacing three frozen columns

The workbook carries `مكان العمل بداية التعيين`, `المدير المباشر بداية التعيين`,
and `المسمى الوظيفي بداية التعيين` — the posting a person held on their first
day, frozen forever. That answers exactly one question and cannot answer "where
were they in March?"

**New table `employee_assignments`:**

| Column | Type | Notes |
|---|---|---|
| `employee_id` | FK → employees | |
| `department_id` | FK → departments, nullable | The unit at the time |
| `job_title_id` | FK → job_titles, nullable | The title at the time |
| `organizational_grade` | tinyint, nullable | Grade at the time |
| `from_date` | date | |
| `to_date` | date, nullable | NULL = current |
| `reason` | enum(hire, transfer, promotion, restructure, termination) | |
| `legacy_workplace` | string, nullable | Import-only: the free-text `مكان العمل بداية التعيين` |
| `legacy_manager_name` | string, nullable | Import-only: the free-text `المدير المباشر بداية التعيين` |
| `notes` | text, nullable | |

The **first row is the initial posting** — the three frozen columns become a
query, and the `ملف النقل` transfer sheet becomes rows rather than a parallel
spreadsheet. `employees.department_id` stays as the denormalised *current*
pointer because it is on every hot query path; the model keeps the two in step
in one `saving` hook, so no writer can desynchronise them (P2).

### 5.6 Pay reference — and what is deliberately absent

| Column | Type | Source | Notes |
|---|---|---|---|
| `sham_cash_account` | string(16), nullable unique | XLSForm `a13` | Exactly 16 digits — constrained in the form, constrain it here too |
| `salary_mode` | enum(scale, agreed) | XLSForm `a17` | نظام رواتب / إتفاق |
| `agreed_salary` | decimal(12,2), nullable | XLSForm `a18` | Required when `salary_mode = agreed`, must be NULL otherwise |

**`employees.salary` is dropped.**

For `salary_mode = scale` employees — the large majority — the number is not a
stored fact. It is the output of the criteria calculation:

```
total = Σ (criterion value × النسبة المئوية للقيمة)
  criteria: أساس الراتب(نوع النشاط) + الخبرة(by activity axis)
          + المؤهل العلمي + السوية التنظيمية + بدل الطعام + القدم/سنة
          + الخطورة + التمييز + الندرة + إزدواجية العمل
  plus manual: بدل سكن، منحة رئاسية، إضافي، مكافئة، بدل ولادة  − حسم
```

Storing that result on `employees` is a cached copy of a derived value with no
invalidation strategy: change the company scale factor (`النسبة المئوية للقيمة`,
currently `0.75`) and all 242 rows are silently wrong. The rate tables and the
calculation belong to Phase 3; §6 says what Phase 1.5 must *not* build.

`مكان الراتب` (مكتب رئيسي / جماعة) does not enter the schema — the company pays
everyone (ratified decision #3). No `currency` column exists; **all money in
this ERP is USD** (ratified decision #4), stated once here rather than repeated
as a column on every row.

### 5.7 Governing salary access

[phase1-completion-report.md:356](phase1-completion-report.md) flags
`employees.salary` read-access as "a decision to make, not made." Making it:

- New permission **`hr.employees.viewSalary`**.
- `EmployeeResource` omits `agreed_salary` and every pay-criteria field unless
  the requester holds it.
- The classification axes (`activity_type`, `organizational_grade`,
  `experience_level`, `engineering_rank`, the four flags) are **pay-determining
  and gated with it**. Knowing someone's grade and activity type is knowing
  their salary to within a rounding error; gating the amount while exposing its
  inputs is theatre.
- `sham_cash_account` is gated by the same permission — it is a payment
  instrument.

---

## 6. New supporting tables

> **✅ Implemented 2026-09-13 (Batch 2).** All three tables landed as specified,
> with these implementation notes:
>
> - **`job_titles`** gained a `description` column (the job description itself)
>   as ONE text field rather than the seven the HR scan proposes (summary,
>   qualifications, skills, authorities, duties…). Nothing in this phase reads
>   the parts separately, and columns nothing queries are columns nobody fills in
>   correctly. Splitting later is a migration; un-splitting is data loss.
>   The in-use guard refuses to delete a title anyone holds **or ever held** —
>   the history half matters more, because a posting that cannot resolve its
>   title cannot explain the payslip computed from it.
>
> - **`employee_assignments`** is as specified, plus the piece §5.5 named but did
>   not design: the two-way synchronisation with `employees.department_id`. It is
>   a model hook in EACH direction, and the recursion is broken by having the
>   assignment→employee direction write through `DB::table()` (no model events) —
>   the same technique `AuditLogService` uses so auditing cannot audit itself. A
>   static "currently syncing" flag would also work and is worse: invisible at
>   the call site, and it leaks across a queue worker's jobs.
>
>   The "at most one open posting" rule is a partial unique index, which MySQL
>   does not have, so it lives in the `saving` hook.
>
> - **`employee_warnings`** stores no `sequence` column. Whether this is
>   someone's third warning is counted from the live rows at read time, so
>   withdrawing #2 of 3 renumbers the third — which a stored counter would not.
>
> - **`employee_documents`** landed as SCHEMA AND MODEL ONLY, with no endpoints.
>   The table is here because the Batch 3/4 importer attaches the intake form's
>   images as it creates each employee, and a table that arrives after the import
>   is a second migration against real rows. Upload, storage, size/type limits
>   and signed downloads belong to the Documents module arriving by port in
>   Stage 2, which already solves all of them in production.
>   ⚠️ Its `ShamCash` type is a photograph of a payment account — i.e. the
>   account number — and must read through `hr.employees.viewSalary` when those
>   endpoints are built, or a document listing walks straight around the
>   column's own gate.
>
> **§6.4 was followed and then extended by one.** `Governorate` became an enum
> too, on the same test: the set is stable. See the §5 marker.

### 6.1 `job_titles` — the catalogue

> **✅ Uniqueness ratified 2026-09-13: one shared catalogue, `unique(name)`.**
> This settles §9 open question 4 and **changes the table below** — the
> `department_id` column is dropped. A title is a company-wide fact: `محاسب` is
> one row, one job description, one suggested `السوية`, whichever divisions
> happen to employ accountants.
>
> **`department_id` is dropped rather than left nullable.** Under `unique(name)`
> a scalar division pointer is NULL for precisely the titles the question was
> about — the shared ones — and non-NULL only by accident of a title currently
> having one employer. "Which divisions use `محاسب`?" is answered by
> `SELECT DISTINCT department_id FROM employees WHERE job_title_id = ?`, which
> is derived from real postings and cannot drift. Storing it on the title too
> would be P1 violated, in the same shape as `سنوات القدم` and inherited
> `sector`.
>
> **The known limit, and its escape hatch.** That derived query only knows
> divisions where someone is *already* posted, so it cannot express "this title
> is offerable in إدارة الإمداد, which has not hired one yet." Nothing needs
> that today — the ERP has no intake form. **If** a division-filtered title
> picker is ever built, add a `job_title_department` pivot; do not re-add the
> scalar column, which would be the same half-fact in a new migration.
>
> **`q4`'s division binding is not evidence against this.** XLSForm cascading
> selects require every option to carry a parent code, so all 197 rows are
> division-tagged whether or not the company thinks that way — the same class of
> form plumbing as the self-referential office rows dropped in §7.3.

`employees.job_title` is a free string today. The company has a governed
catalogue: 197 titles in the intake form's `q4`, each bound to a division; and
the workbook's `دليل الهيكل والمسميات الوظيفية` sheet (691 rows) carries the
same list with a `تم التوصيف` ("has been described") flag — the output of
`قسم التحليل والتوصيف`.

| Column | Type | Notes |
|---|---|---|
| `name` | string, **unique** | المسمى الوظيفي — the catalogue is company-wide |
| `default_organizational_grade` | tinyint, nullable | Suggested `السوية` for new hires |
| `default_activity_type` | enum, nullable | Suggested `نوع النشاط` |
| `is_described` | boolean, default false | `تم التوصيف` |
| `description` | text, nullable | The job description itself, when written |
| `is_active` | boolean, default true | |

`employees.job_title` (string) becomes `employees.job_title_id` (FK).

Because the table soft-deletes (C23 — `employees` FKs into it), the unique index
counts trashed rows: a deleted title's name cannot be reused until the row is
force-deleted. Same trade-off, and the same reasoning, as `users.email`. Validate
`name` against **all** rows including trashed, or the DB rejects the insert after
validation has already passed.

### 6.2 `employee_documents`

The intake form collects four images per hire: ID front, ID back, graduation
certificate, Sham Cash account photo.

| Column | Type |
|---|---|
| `employee_id` | FK → employees |
| `type` | enum(id_front, id_back, graduation_certificate, sham_cash, contract, other) |
| `title` | string, nullable |
| `file_path` | string |
| `uploaded_by` | FK → users, nullable |

Read access follows §5.7 for `sham_cash`; the rest follow
`hr.employees.view`.

### 6.3 `employee_warnings`

The workbook tracks `نوع الإنذار`, `تاريخ الإنذار`, `رقم الإنذار` — as three
columns, so an employee can hold exactly one warning forever.

| Column | Type |
|---|---|
| `employee_id` | FK → employees |
| `type` | string |
| `number` | string, nullable |
| `issued_on` | date |
| `issued_by` | FK → employees, nullable |
| `notes` | text, nullable |

### 6.4 Reference data — enums, not tables

`activity_type`, `education_level`, `engineering_rank`, `marital_status`,
`experience_level` are **PHP enums**, not lookup tables.

The reasoning is worth stating because it looks like a P4 violation and is not.
These sets are stable — they have not changed between the September 2025 and
February 2026 workbooks. What *does* change is the **monetary value attached to
each case**, and those values are not stored here: they live in Phase 3's
versioned rate table, keyed by the enum case and an effective date. Splitting
it this way means a pay-scale revision is a data change (P4 satisfied) while the
criterion identity stays a compile-time constant the calculator can `match` on
exhaustively.

---

## 7. Data import

**Source of record:** the Excel workbook and the org chart. **Not** the Access
DB (ratified decision #5) — those records move manually later.

### 7.1 The sources disagree. The chart wins.

A reconciliation pass found the intake form is **stale** relative to the chart:

| Discrepancy | Intake form (Dec 2025) | Org chart (Jul 2026) | Workbook (Feb 2026) |
|---|---|---|---|
| `إدارة الإمداد والتوريد` | Absent — its sections hang off `الإدارة المالية` | A division in its own right | Listed as a division, 10 staff, $7,251 payroll |
| `إدارة التسويق` | Named `إدارة التسويق` | `إدارة التسويق وتقنية المعلومات`, with `قسم تقنية المعلومات` added | — |

**Rule:** the PPTX is authoritative for tiers 1–3. The intake form is used
**only** for the office tier (`q3`), because the chart draws offices
inconsistently across slides.

Both discrepancies must be confirmed with HR before the import runs — the chart
is four months newer than the workbook, and a division that gained a section on
paper may not have gained it in fact.

### 7.2 Org tree import

| Form list | Rows | Maps to | Handling |
|---|---|---|---|
| `q1` | 13 | Level 2 `إدارة` | Cross-check against the chart per §7.1 |
| `q2` | 48 | Level 3 `قسم` | Drop `b1000 المدير العام` — it is the level-2 unit itself |
| `q3` | 114 | Level 4 `مكتب` | **Filtered** — see §7.3 |
| `q4` | 197 | `job_titles` | **Dedup by name** — see the §6.1 marker |

⚙️ **`q4` is 197 form rows, not 197 titles.** Since 2026-09-13 the catalogue is
`unique(name)`, so the importer collapses the list by name and discards the `q1`
division code it was previously told to carry across. The row count that lands is
the distinct-name count, which is **not yet known** — it is whatever the 197
rows, cross-checked against the workbook's 691-row sheet, dedup to.

Two things the importer must **report** rather than silently resolve, in the same
spirit as §7.4's data problems: how many rows collapsed, and any name that
arrives with **conflicting** `تم التوصيف` or suggested-grade values across its
duplicates. A conflict there is evidence that the divisions really do mean
different jobs by one word — which is the answer to §9 question 4 arriving late,
and it needs a human, not a tie-break rule.

### 7.3 The office filter — where decision #6 is enforced

Of the 114 `q3` rows, a large minority are not organizational units. Three
drop rules:

1. **Self-referential placeholders.** Rows whose label equals their parent
   section's label — `c1 قسم النقدية` under `b1 قسم النقدية`,
   `c9 قسم الأصول الثابتة` under `b3`, and so on. This is the XLSForm idiom for
   "this section has no offices; pick the section itself." They are form
   plumbing, not units. **Drop.**

2. **Machine and vehicle groupings.** Under `b16 قسم السائقين`: `باكر`,
   `بلدوزر`, `بوك`, `تركس`, `تركس جنزير`, `رافعة`, `سيارة قلاب`, `صهريج`,
   `كليدر`, `لودر`, `مدحلة`, `نباشة`, `نغل`, `الكسارة الراوترية`. These are
   equipment classes that a driver is qualified on. **Drop from the tree** →
   they become asset categories (Phase 8) and/or job titles (`سائق آلية ثقيلة`
   already exists in `q4`).

3. **Workshops and engineering teams.** `ورشة التشغيل`, `ورشة المد`,
   `ورشة التركيب`, `فريق هندسي (1)(2)(3)`, and the `الورشات الفنية` leaves.
   **Drop from the tree** → job titles now, project teams once Phase 5 lands.
   Note that `قسم مجبل الإسفلت`, `قسم معمل البلوك`, `قسم معمل الأنترلوك` and
   siblings under `إدارة المشاريع الإنتاجية` are *genuine sections* (`q2`) and
   stay.

Expected survivors: roughly **70–80 offices**. The exact figure is not
predictable from the files and the filtered list **requires a human review pass
with HR before it is committed** — the import script must produce the proposed
list as a reviewable artefact, not write it directly.

### 7.4 Employee import

242 rows from `قاعدة البيانات`, plus 223 from `إنهاء عمل` imported with
`status = terminated`. Per row: create the `employees` record, create the
opening `employee_assignments` row from the `بداية التعيين` columns, attach
`legacy_number`.

Known data-quality issues visible in the source, which the importer must report
rather than silently absorb: `#N/A` values in the criteria columns of the
`إنهاء عمل` sheet; employees with a blank `أسماء الإدارات`; and
`رقم الشام كاش` values that are not 16 digits.

**The importer is idempotent and re-runnable, keyed on `legacy_number`.** It
will be run several times against review feedback before the real cut.

---

## 8. Explicitly out of scope

Named so they are not quietly assumed, and so the phase does not grow:

- **Payroll** — the rate table, the calculator, payslips. Phase 3.
- **Attendance and leave** — arriving by port, not by build. See
  [legacy-system-merge-plan.md](legacy-system-merge-plan.md).
- **Documents and the approval engine** — same, and it lands *before*
  attendance.
- **Performance evaluation** — `distinction_grade` gets a column here; the
  review cycle that sets it is a later module.
- **Recruitment / CV bank** — 343 CVs and a staged pipeline in the workbook.
  Real, deferred.
- **Contracts** — `نموذج العقود` (قصير / طويل / نقل, duration, renewal).
  Deferred.
- **Training and development** — deferred.
- **The escalation chain walk** — `direct_manager_id` prepares for it (§5.4);
  the vacant-post and self-approval rules remain unspecified and uninvented.
- **Frontend** — React and Flutter are separate projects and are unaffected by
  Phase 1.5 except for the response-shape changes in §5.1 and §5.7.

---

## 9. Open questions

Small enough not to block the start of implementation, but each needs an answer
before the module it touches is finished.

1. **`employee_number` format.** New hires currently get `EMP-00001`; the
   company uses `M024333`. This spec keeps both — the generator for the ERP
   identifier, `legacy_number` for the imported one. Confirm HR is content to
   see a new number, or say the word and the generator adopts the `M` format
   for new hires too.
2. **`مجلس الإدارة` membership.** ✅ **Answered 2026-08-05: yes.** Board members
   are `employees` rows, which is why the unit must exist in the tree at all —
   an employee needs a `department_id`. The project owner also characterised the
   board as *"مجرد وعاء"*: no unit reports to it and no approval escalates to
   it. That is what makes §4.3's placement of it under `الإدارة العامة`
   affordable, and it is a **precondition** — see the §4.3 marker.

   Original note: they have a job title (`عضو مجلس إدارة`, grade 1) in the
   workbook, which suggests yes.
3. **`الحالة` beyond two values.** The workbook shows only `متعاقد` and
   `إنهاء عمل`. Does the company distinguish suspended / on-loan / seconded
   staff, which the current `inactive` case could carry?
4. **Job-title uniqueness.** ✅ **Answered 2026-09-13: one shared title.**
   `unique(name)`, and `job_titles.department_id` is dropped rather than left
   nullable. The full reasoning, the derived replacement for the dropped column,
   and the pivot that is the escape hatch if a division-filtered title picker is
   ever built are in the §6.1 status marker. §7.2 carries the importer
   consequence — dedup by name, and report collisions that disagree.

   Original note: `محاسب` and `أمين صندوق` appear under several divisions in
   `q4`. Confirm these are genuinely per-division rows rather than one shared
   title — it decides whether `(name, department_id)` is unique or `name` alone
   is.

---

## 10. Estimate

**2–3 weeks**, in four commits that each leave the suite green:

| Batch | Content |
|---|---|
| 1 | `Office` tier + `Sector` enum + `departments.sector` + resource/guard updates |
| 2 | `employees` restructure (§5) + the three new tables (§6) + permission additions |
| 3 | Import tooling (§7) + the reviewable org-tree artefact |
| 4 | HR review pass, corrections, real import |

Batch 2 is the one that must land before any production data exists. Batches 3
and 4 are what create that data.
