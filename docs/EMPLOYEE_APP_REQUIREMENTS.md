# Employee App (تطبيق الموظف) — the requirement source

> **What this is:** the project owner's own analysis of the mobile app every
> employee will carry, recorded **2026-09-21**. It is a *product* statement, not
> a schema — it says what the company will ask this API for, in the words the
> company uses.
>
> **Why it is in `docs/`:** HR is being built now, and several of its remaining
> decisions are cheap today and expensive after the 465-row import. This file is
> the checklist those decisions are made against, so nothing on it is discovered
> late.
>
> **What it does NOT do:** it does not reorder the stages in
> [`legacy-system-merge-plan.md`](legacy-system-merge-plan.md), and it re-opens
> none of the ratified decisions in [`START_HERE.md` §9](START_HERE.md). It adds
> requirements to stages that already exist, names work no stage owns yet (§5),
> and answers two questions the code was already blocked on (§4).
>
> **⚙️ Amended 2026-09-22 — two areas are not ours.** العهد المسلمة (§5.10) and
> السلف (§5.2) belong to **إدارة المالية**, not الموارد البشرية, and neither is
> built now. Both sections keep their entry rather than being deleted, because
> each states the decision and the one dependency that survives it — Stage 4
> payroll still prints a السلف deduction on every payslip that has one.
>
> **Last verified against the code:** 2026-09-21, against `phase_1` with Batch 2
> in the working tree, and against the legacy repo at `work/Raqi-App`.

---

## Table of contents

1. [The spec as given](#1-the-spec-as-given)
2. [Where each area lands](#2-where-each-area-lands)
3. [The one-line summary](#3-the-one-line-summary)
4. [What it settles — two blocked decisions, answered](#4-what-it-settles--two-blocked-decisions-answered)
5. [What it adds that no stage owns yet](#5-what-it-adds-that-no-stage-owns-yet)
6. [The self-service API contract](#6-the-self-service-api-contract)
7. [The import boundary — decided, and still open](#7-the-import-boundary--decided-and-still-open)
8. [Questions for the project owner](#8-questions-for-the-project-owner)
9. [One observation from the legacy side](#9-one-observation-from-the-legacy-side)

---

## 1. The spec as given

Recorded verbatim, because the wording is the requirement. Everything after this
section is commentary on it.

**1 — حساب الموظف**
الاسم + الصورة الشخصية + المسمى الوظيفي · القسم + موقع العمل الحالي · رقم الموظف ·
المدير المباشر · حالة الدوام اليوم · الراتب المستحق لهذا الشهر · عدد أيام وساعات
العمل · رصيد الإجازات · التنبيهات والطلبات الجديدة

**2 — قسم الراتب**
الراتب الأساسي · البدلات · الحوافز · العمل الإضافي · الخصومات · المكافآت · السلف ·
صافي الراتب · حالة الدفع · تاريخ تحويل الراتب — مع فتح كشف الراتب بتفاصيله والرجوع
إلى رواتب الأشهر السابقة

**3 — الدوام والحضور**
حالة اليوم: حاضر / متأخر / إجازة / غياب / عطلة — وبالضغط على اليوم تظهر التفاصيل:
تسجيل الدخول والخروج وساعات العمل

**4 — الإجازات**
طلب إجازة: سنوية / مرضية / بدون راتب … · السبب: إرفاق ملف أو صورة أو كتابة شرح ·
تتطلب موافقة المدير المباشر **وموافقة مدير الموارد**

**5 — الطلبات الإدارية**
طلب سلفة · طلب إجازة · …

**6 — الملف الوظيفي**
المعلومات الشخصية · رقم الهاتف · العنوان · الوظيفة · القسم · تاريخ بدء العمل ·
العقد · الراتب · الشهادات · الخبرات · الهوية · جواز السفر إن وجد · الملفات
والمستندات · العهد المسلمة له (الأصول)

**7 — العقود والمستندات**
عقد العمل · قرارات الترقية · شهادات العمل · كشوف الرواتب · التعاميم الموجهة له ·
خيار **"قرأت ووافقت"** حتى تعرف الإدارة أن الموظف اطّلع على المستند

**8 — الإشعارات**
عند إيداع الراتب · عند الموافقة على طلب · عند التأخير · عند تحديث الراتب · عند
تعميم · عند قرب انتهاء العقد

**9 — تقييم الموظف**
التقييم الدوري · الأهداف · ملاحظات المدير المباشر · الدورات التدريبية · الإيجازات

**10 — لوحة المدير**
الموظفون التابعون له · الحضور · الغياب · التأخير · طلبات الإجازة …
**مدير القسم لا يرى رواتب الشركة كلها — يرى بحسب صلاحياته موظفي قسمه فقط.**

**11 — لوحة HR**
الموظفون · الدوام · الرواتب · الإجازات · العقود · الطلبات · التقارير · التقييمات ·
المستندات · إضافة موظف · تعطيل موظف · تحديد راتبه · إدارة الدوام · إدارة العقود ·
إدخال الحسم · إدخال المكافآت · إدارة السلف · الموافقة على الطلبات · إصدار كشف
الرواتب · استخراج التقارير

---

## 2. Where each area lands

"Stage" refers to [`legacy-system-merge-plan.md` §4](legacy-system-merge-plan.md).
"Exists" means the ERP holds it today, on branch `phase_1`.

| # | Area | Lands in | State today |
|---|---|---|---|
| 1 | حساب الموظف (home) | spans everything | **Composite.** Identity exists; the rest lights up per stage — see §6 |
| 2 | الراتب | **Stage 4** | Nothing. `employees.salary` was deliberately dropped; pay is computed. One line on that screen — السلف — reads a **إدارة المالية** ledger HR does not own and does not build — §5.2 |
| 3 | الدوام | **Stage 3** (ported) | Legacy runs it: GPS + selfie, offline queue, missing-checkout detection. One gap — عطلة, §5.5 |
| 4 | الإجازات | **Stage 3** (ported), approvals from **Stage 2** | Legacy runs it, with multi-step approvals already (`leave_request_approvals.approval_order`). Two gaps — §4.2 and §5.6 |
| 5 | الطلبات الإدارية | **Stage 2** engine + per-type modules | The engine does not exist yet. Adds one consumer to its design brief — §5.3. طلب سلفة is no longer among them; it went to إدارة المالية with the ledger |
| 6 | الملف الوظيفي | **exists**, mostly | ~50 columns, assignments, documents, warnings. Missing: certificates, prior experience, contract dates — §5.8–§5.9. **العهد are إدارة المالية, not HR, and are not built** — §5.10 |
| 7 | العقود والمستندات | **Stage 2** | Ported. **"قرأت ووافقت" is new** — the legacy module has no acknowledgement — §5.4 |
| 8 | الإشعارات | table **exists**, transport in **Stage 3** | `notifications` + endpoints exist; FCM arrives with the port. Six named triggers = an event catalogue — §5.7 |
| 9 | التقييم | **no stage owns it** | ⚠️ The biggest finding in this document — §5.1 |
| 10 | لوحة المدير | ✅ **GAP-3 built 2026-09-24** | A `managed` grant lists exactly the manager's subtree — §4.1 |
| 11 | لوحة HR | spans Stages 1–4 | The dashboard side of every module above; it is a client, not a module. **إدارة السلف is listed there but is not HR work** — §5.2 |

---

## 3. The one-line summary

**Nothing in this spec contradicts the plan. Three things in it have no home in
the plan at all, one of those is a dependency of Stage 4, and two more are
decisions that get expensive the day the import runs. Two further areas turned
out not to be HR work at all (§5.2, §5.10) and are not built.**

Read §4, §5.1 and §7. The rest is confirmation that the stage order was right.

---

## 4. What it settles — two blocked decisions, answered

### 4.1 GAP-3: what is a manager's scope?

[`API_BUILD_STANDARD.md` §12](API_BUILD_STANDARD.md) records GAP-3 — *no
row-level authorization anywhere; `hr.employees.view` reads every employee* — as
**"blocked on a product decision: what is a manager's scope?"**

Area 10 answers it:

> مدير القسم لا يرى رواتب جميع الشركة، وإنما حسب صلاحياته يرى موظفي قسمه فقط.

So the rule is **scope by org-tree subtree**, and a grant is
`permission × scope`, not a permission alone. That is a design the ERP can now
start, and it is the difference between an app a department head can be given and
one only HR can hold.

✅ **Ratified 2026-09-23** (decision 14 in [`START_HERE.md` §9](START_HERE.md)):
**the whole subtree** — every section and office beneath the manager's unit, and
all their staff. Not the unit row alone, and not direct reports alone.

✅ **Built 2026-09-24.** Give a manager's account a role over the scope
«الوحدات التي أديرها» (`scope_type: 3`) and `GET /api/hr/employees` returns their
subtree; asking for anyone else answers 403. See `API_DOCUMENTATION.md` §5.4 and
§8.1.

Two notes for whoever builds it:

- **The scope must be the subtree, not the row.** `departments` is a tree with a
  `level`; a division head who sees their own division row but not the sections
  under it sees almost nobody.
- **Read the legacy design first.** The legacy system began building exactly this
  on 2026-08-31 (`create_authorization_tables`, `RoleAssignment`, a `scope_id`
  that is `NOT NULL` with `0` meaning "no unit applies", and time-bounded
  grants). It is independent evidence that the company needs scoped roles, and
  its migration docblocks record trade-offs worth not re-discovering.

⚠️ This does **not** make the ERP adopt the legacy RBAC — the merge plan is
explicit that that migration runs one way. It means the *scope* concept, which
the ERP lacks, has a worked reference implementation next door.

### 4.2 The leave approval chain is two steps, and the second is HR

Area 4 states it plainly: **موافقة المدير المباشر + موافقة مدير الموارد**.

This is the first concrete operational rule for the escalation chain that
[`START_HERE.md` §12](START_HERE.md) calls *"structure without a walk"*. It says
the chain is not "climb until you reach المدير العام" — it is **the direct
manager, then a functional approver (HR), then done**.

Design consequence for the Stage 2 engine: per-step approver resolution must
support **a role or a named post** as a step, not only "the subject's manager".
The merge plan already asks for that; this spec proves it is needed on day one
rather than later.

The legacy `leave_request_approvals` table already carries `approval_order` and a
`skipped` status, so the port has somewhere to land.

### 4.3 A third, smaller one

Area 9 names the writer of `distinction_grade` (التمييز بحسب التقييم): **the
performance review**. [`START_HERE.md` §11](START_HERE.md) open question 3 asks
what scale that column uses and notes that "the performance-review module that
sets it will need the real answer." This spec confirms such a module is expected
and that the employee sees its output. The scale itself is still unanswered — §8.

---

## 5. What it adds that no stage owns yet

Ordered by how much it costs to discover late.

**Two entries here are not work at all.** §5.2 (السلف) and §5.10 (العهد) were
ratified on 2026-09-22 as **إدارة المالية**, not HR. They keep their numbering
rather than being deleted, because a requirement that was raised and then placed
elsewhere is worth finding later — the alternative is someone asking the same
question in six months and getting no answer from this file.

### 5.1 ⚠️ تقييم الأداء — an evaluation module, and Stage 4 depends on it

**No stage in the merge plan builds performance evaluation.** The legacy system
has no evaluation model either — it exists in neither system.

That matters because:

- `employees.distinction_grade` exists **today** as a free `tinyint` that any
  holder of `hr.employees.update` can set to anything between 0 and 255. No
  module produces it, and no scale bounds it.
- [`legacy-system-merge-plan.md` §2](legacy-system-merge-plan.md) lists
  *performance evaluation (التمييز)* among the five inputs Stage 4 payroll needs.
  Four of the five have an owning stage. This one does not.
- Area 9 makes it employee-visible: التقييم الدوري، الأهداف، ملاحظات المدير،
  الدورات التدريبية.

**Proposal:** an `app/Modules/Performance` module, scheduled either as its own
stage between 3 and 4 or as an explicit first part of Stage 4. Minimum surface:
an evaluation period, a score on a defined scale, the evaluator, manager notes,
and the mapping from score to `distinction_grade`. Goals and training courses can
follow later; the score cannot, because payroll reads it.

### 5.2 السلف — ratified out of scope, 2026-09-22

> **القرار: السلف تتبع إدارة المالية، لا الموارد البشرية. لا تُبنى هنا الآن.**

Area 2 lists السلف beside الخصومات, and area 11 lists **إدارة السلف** as a task on
the HR dashboard. The owning department is **إدارة المالية**, so neither the
ledger nor طلب سلفة is built in HR, and the Stage 2 approvals engine gains no
advance consumer (§5.3).

**Two things survive the decision and must not be lost with it:**

1. **The payslip still carries a السلف line.** Area 2 puts it on a screen this
   API serves, so Stage 4 payroll reads an amount another department owns.
   Whoever specifies Stage 4 owes that boundary a shape — one monthly
   installment figure, per employee per period — even though the module behind
   it is not ours. A deduction with no defined source is a payslip that cannot
   be reconciled.
2. **The modelling note, for whoever builds it there.** An advance is **one
   disbursement and N monthly repayments**; a payslip line is one repayment. If
   the line is the only record, the outstanding balance has no home, and "how
   much does this employee still owe?" is answerable only by summing history.
   The ledger — request, approved amount, disbursement date, installment plan,
   running balance — belongs on the finance side, with each payslip deduction
   line pointing at the installment it settles.

Nothing in HR blocks on this, and nothing in HR should reach across for it: the
coupling is a single read at payroll time, in that direction only.

### 5.3 الطلبات الإدارية — another consumer for the approvals engine

The merge plan tells Stage 2 to design the approval interface against four known
consumers (documents, leave, purchase requests, payroll/expense approval). This
spec adds:

- **الطلبات الإدارية** generally — an open-ended category the company will keep
  adding to
- the **two-step manager → HR chain** from §4.2

⚙️ **طلب سلفة was on this list until 2026-09-22** and is not any more — it left
with the ledger for إدارة المالية (§5.2). That is worth keeping as evidence for
the point below rather than erasing: a whole request type moved to another
department, and a correctly designed engine does not change because of it.

The open-ended category is the important one: it means the engine needs a
*request type* concept carrying its own approval route, so that adding a kind of
request — from HR or from any other department — is configuration rather than a
new module.

### 5.4 "قرأت ووافقت" — acknowledgement is not in the legacy document module

The legacy capability table covers uploads, PDF generation, attachments,
comments, numbering and approvals — **not** a read receipt. Area 7 wants the
company to know an employee has seen a circular.

Small to build, easy to forget: an acknowledgement row per (document, employee)
with a timestamp, plus a "who has not acknowledged yet" read for HR. Stage 2.
Whether it is a legal signature or an informational receipt is a question — §8.

### 5.5 عطلة — there is no holiday calendar

Area 3's day states are حاضر / متأخر / إجازة / غياب / **عطلة**. The legacy
`AttendanceService` models a `working_days` list only, shipped with **no weekend
at all**, and its own docblock names the public holiday calendar as *"the single
extension point"* — one that does not exist yet.

So عطلة has no data source in either system. Stage 3 owes it, and so does Stage 4
— an absence deduction that counts عيد as غياب is a wrong payslip.

### 5.6 مرفقات الإجازة — the legacy leave request has no attachment

`leave_requests` carries `reason` as text and nothing else. Area 4 requires
**إرفاق ملف أو صورة** — which is the whole point of a sick leave (التقرير الطبي).
Stage 3's port must add it. This is the clearest example of the merge plan's own
warning that *a port is not a copy*.

### 5.7 The notification catalogue

Area 8 names six triggers: إيداع الراتب، الموافقة على طلب، التأخير، تحديث الراتب،
تعميم، **قرب انتهاء العقد**. Two notes:

- They are an **event catalogue**, and this repo already has the pattern for one
  in `app/Shared/Support/AuditEventCatalogue.php`. Name the events once; do not
  let each module invent its own strings.
- **قرب انتهاء العقد needs contract end dates, which do not exist** — §5.9. A
  notification with no data source is not a notification.

### 5.8 الشهادات والخبرات — one row each today

`employees` holds a single `education_level` + `qualification` + `specialization`
+ `graduation_date`. Area 6 shows **الشهادات** and **الخبرات** as lists. Either
typed `employee_documents` rows or two small tables. Decide before the import
whether the workbook carries more than one of either — §7.

### 5.9 العقود — there is no contract record

`employees` has `hire_date`, `termination_date` and `employment_type`
(متعاقد / يحتاج تثبيت / جماعة / مثبت). There is **no contract start/end date**,
so:

- area 6's العقد has nothing to show beyond the employment type,
- area 7's عقد العمل is a document with no metadata, and
- area 8's قرب انتهاء العقد cannot fire for anyone.

A contract record (type, start, end, renewal-of) is the natural home, and
`EmployeeDocumentType::Contract` already exists to attach the scan to it.

### 5.10 العهد المسلمة (الأصول) — ratified out of scope, 2026-09-22

> **القرار: العهد تتبع إدارة المالية، لا الموارد البشرية. لا تُبنى هنا.**

Area 6 puts العهد المسلمة on the employee profile, but the owning department is
**إدارة المالية**. So it is recorded here and built nowhere in HR: the profile
screen ships without that card, and no HR table holds an asset.

This also matches the roadmap, where Assets is a *later* module — but the reason
it is out of scope is ownership, not sequence. Even when Assets is built, it
belongs to the finance side of the line.

One note for whoever eventually builds it: it owes a single read this document
already knows the client for — *الأصول المسلمة للموظف س* — and it reaches HR only
by holding an employee id as a **holder reference**. That one FK is the whole
coupling, and it lives on the Assets side.

---

## 6. The self-service API contract

Every screen in this spec is **"my …"**. The API has no such surface today:
`GET /api/auth/me` returns the *user* (id, name, email, roles), not the person,
and everything about the person lives under `/api/hr/employees/{id}` behind
`hr.employees.view`.

### 6.1 The gate conflict — state it before it is discovered in code

`EmployeeResource` hides `agreed_salary`, `sham_cash_account` **and all nine
classification axes** unless the caller holds `hr.employees.viewSalary`. That gate
is correct and deliberate ([`START_HERE.md` §10](START_HERE.md), step 6).

An employee must see **their own** salary. Granting every employee
`hr.employees.viewSalary` would grant them **everyone's**.

⚠️ **Therefore self-access cannot be a permission grant.** It is a second
authorization model — the C7 *self-scoped* model that `/api/auth/me` and the
notification endpoints already use — and the pay block must be visible on
`/api/me/...` while staying gated on `/api/hr/employees/{id}`. The resource that
renders both is the same class, and today it decides on the permission alone. Do
not let the first self-service endpoint solve this by handing out `viewSalary`.

✅ ⚙️ **Ratified 2026-09-23 — and the self block is the whole pay block.** An
employee sees **their own classification axes in full**: السوية التنظيمية، نوع
النشاط، الخبرة، التمييز، and the pay flags, not only the amount (decision 17 in
[`START_HERE.md` §9](START_HERE.md)). So `/api/me/profile` returns for the owner
exactly what `hr.employees.viewSalary` returns to HR about them, and the two
paths differ in *who* they are about, not in *what* they show.

⚠️ **That raises the bar on the import, not on the code.** Every axis becomes
employee-visible on the day the app ships, so every axis must be correct and
defensible **per person** before Batch 4 runs. An axis nobody could read was a
data-quality problem; an axis 242 people read is a conversation with each of
them.

### 6.2 The shape

A `/api/me` surface, self-scoped, carrying no `permission:` middleware, every
endpoint resolving through the authenticated user's own `employee_id`:

| Endpoint | Needs | Available |
|---|---|---|
| `GET /api/me/profile` | employees, job_titles, departments, documents | **now** |
| `GET /api/me/documents` | `employee_documents` endpoints | Stage 2 |
| `GET /api/me/requests` | the approvals engine | Stage 2 |
| `GET /api/me/attendance` | the attendance port | Stage 3 |
| `GET /api/me/leaves` + balance | the leave port | Stage 3 |
| `GET /api/me/payslips` | payroll | Stage 4 |
| `GET /api/me/summary` | all of the above | composite |

**Design `/api/me/summary` to degrade.** Area 1 is one screen spanning five
modules. Built as all-or-nothing it cannot ship until Stage 4. Each card returns
`null` until its stage lands, and the app lights up as the stages complete. That
is what makes an app release possible during Stage 1.

### 6.3 The manager resolver is now app-blocking

Area 1 prints **المدير المباشر** on the home screen, and nothing resolved it —
that was exactly the *"structure without a walk"* limitation. It is also the same
walk the approvals engine needs in Stage 2, so it was built once, in
`app/Shared`, for both callers.

✅ **The rules were ratified 2026-09-23** (decision 15 in
[`START_HERE.md` §9](START_HERE.md)) and amended on 2026-09-24:

1. The manager of the employee's own unit.
2. **If that post is vacant, or the manager IS the person asking, climb** to the
   nearest ancestor unit that has a manager. Both cases take the same branch,
   which is why they were answered together.
3. Terminate at المدير العام. The root always has one, so the walk cannot fall
   off the top.
4. Manual reassignment stays available on top of this — the legacy system
   already ships it (`leave_approval_reassignments`), and an automatic climb is
   not a reason to remove a human override.

> ✅ ⚙️ **BUILT 2026-09-23** — `App\Shared\Services\ManagementChain`, with the
> resolved manager on every employee payload as `manager`. Nine tests, one per
> rule and per API path.
>
> ⚙️ **AMENDED 2026-09-24.** A rule 1 stood above the list — `direct_manager_id`
> when set, recording someone reporting outside their unit head. The project
> owner ratified that this company has no such case, so the column and the
> `direct_manager` key were both removed and the rule went with them. **The org
> tree is the only source of a manager**, which also means the app's home screen
> can never show a stale one: there is nothing stored to go stale.

⚠️ **This section predicted two entry points and was wrong.** It said the home
screen wants "who is my manager" while the approvals engine wants "who approves
this", and that the two differ by the self-skip. Building it showed they are
the same question: a unit head asking who their manager is wants their own
superior too, so the self-skip belongs to both. The service has **one** method,
and the note is kept here rather than deleted so nobody goes looking for a
distinction that was never there.

### 6.4 Devices

The legacy system stores **one** `users.fcm_token` per user. An employee with a
phone and a tablet loses one of them, silently. Stage 3 already plans to port
device listing and per-device logout; land the push token on that same
`device_tokens` table rather than on a column.

---

## 7. The import boundary — decided, and still open

These touch tables the import writes. [`START_HERE.md` §10](START_HERE.md) is
explicit about why the moment matters: *"that window closes exactly once."*

### 7.1 ✅ Ratified 2026-09-22 — the account model

> **1. كل موظف له حساب دخول.** السبب: **تسجيل الحضور والانصراف يتم عبر تطبيق
> الشركة.**
> **2. معرّف الدخول هو رقم الموظف** (`employees.employee_number`), not an email.
> **3. كلمات المرور الأولى مؤقتة، ويفرض النظام تغييرها عند أول دخول.**

> ✅ ⚙️ **BUILT 2026-09-23.** The schema, the login resolution, the endpoint and
> the enforcement all exist and are covered by 16 tests in
> `tests/Feature/Auth/AccountModelTest.php`. What remains of this section is
> **C — what the importer owes**, because the importer does not exist yet.

**The reason is the load-bearing part.** Attendance runs through the app, so an
account is not an IT convenience — it is a **payroll input**. An employee with no
working account cannot be recorded present, and in Stage 4 that silence is
indistinguishable from absence. It arrives on the payslip as a deduction against
someone who came to work every day.

Three consequences follow, and they are the reason this was settled before the
import rather than after it.

**A — What the schema needs.** A migration on `users`, before any account exists:

- `users.email` becomes **nullable**. It stays unique and becomes a contact
  field, not an identity. (Repeated `NULL`s are permitted in a unique index on
  both MySQL and the SQLite test driver, so no partial index is needed.)
- **No `employee_number` column is added to `users`.** The number is a fact about
  the *person*, not about the account, and `users.employee_id` is **already
  `unique`** — so login resolves through the employee row: number → employee →
  its one account. Copying the number onto `users` would be two columns for one
  fact (P1), and it would need a sync hook the day an employee number is manually
  overridden, which decision 9 explicitly permits.
- `must_change_password` (and a `password_changed_at`) on `users`.
- The login endpoint takes **one `login` field**, resolved as an employee number
  first and an email second — because accounts with no employee (the technical
  department, `super_admin`) have no number and must still sign in.
- ⚙️ **The login rate limiter is keyed on `ip + lower-cased email` today.** It
  must be re-keyed on `ip + login`, or the protection quietly stops covering the
  way everybody actually signs in.

**B — "Forced" must be enforced by the API, not by the app.** A temporary
password shared by HR is a credential somebody else may know, and the first thing
it can do is submit attendance. So a session carrying `must_change_password` is
refused everywhere except the change-password endpoint. If the rule lives only in
the Flutter screen, it is a suggestion.

**C — What the importer owes.**

- **Accounts only for `status = active`.** The import brings 223 terminated
  people; none of them gets one.
- **Termination disables the account.** An `employees.status` change to
  terminated sets `users.is_active = false` through a model hook, on every path —
  the same pattern the posting history already uses. Otherwise a departed
  employee keeps a working attendance app.
- **A credential artefact, not a log line.** The importer emits the temporary
  passwords as a list HR distributes, and writes them nowhere else. ✓ Verified:
  `AuditLogService::sanitize()` already redacts `password` and `password_hash`,
  so the audit trail is safe by default — do not add a path that bypasses it.
- **Coverage is an exit criterion.** Stage 3 does not close until every active
  employee has an account they have logged into at least once. A 95 % rollout is
  a payroll defect affecting the other 5 %.
- ⚠️ **Soft deletes will bite the re-run.** `users` soft-deletes and
  `users.employee_id` is unique, and a unique index counts trashed rows — the
  same trap `users.email` and `job_titles.name` already carry. So an account that
  was soft-deleted blocks the creation of a new one for that same employee, and
  the importer is specified as **idempotent and re-runnable**. It must restore
  the trashed row rather than insert beside it.

**Recommendation on the legacy accounts (not yet ratified):** issue fresh
accounts for everyone rather than carrying the legacy ones over. The legacy
`users` table has **no employee number** — only name, email, department — so
matching its accounts to ERP employees is name-based, and the workbook already
proves name matching between company systems fails often enough to need a
dedicated `مقارنة الأسماء` column. Since the new identifier is one the legacy
system never held, carrying the old password hashes buys nothing anyway.

### 7.2 Still open — all three are questions about the source data

| # | Decision | Why it cannot wait |
|---|---|---|
| **1** | **Does the intake data carry a personal photo?** | `EmployeeDocumentType::PersonalPhoto` exists. If the source has one per hire the importer attaches it; if not, the app needs an upload path from day one — area 1 opens on the photo |
| **2** | **Does the workbook hold contract start/end dates?** | If yes, import them with everything else. If no, §5.9's contract record starts empty and قرب انتهاء العقد cannot fire for anyone |
| **3** | **Does it hold more than one certificate, or a prior-experience list?** | Decides between typed documents and two tables — §5.8 |

All three are answerable while reading the workbook for Batch 3 — no one needs to
be asked.

⚙️ **`phone` is settled by 7.1 and needs nothing.** It was on this list because
an identifier must be unique; since the identifier is the employee number, the
phone stays exactly what it is — a nullable, non-unique contact field.

---

## 8. Questions for the project owner

Short, and each one changes something concrete.

✅ **Answered and moved out of this list.** On **2026-09-22**: how a driver or a
site worker signs in, and whether every employee gets an account at all — both in
§7.1, with the reason (attendance runs through the app). On **2026-09-23**:
**manager scope** — the whole subtree (§4.1), and **whether an employee sees
their own classification axes** — yes, in full (§6.1). Six are left.

1. **السلف — the boundary, not the module.** Ownership is settled: إدارة المالية,
   2026-09-22. What is still open is the seam — when Stage 4 payroll needs the
   monthly deduction, does it read a finance ledger through a defined contract,
   or does HR key the figure in by hand each period? (§5.2)
2. **Is "قرأت ووافقت" a signature or a receipt?** A legal acknowledgement needs
   more than a timestamp. (§5.4)
3. **Evaluation: what scale, and how often?** This is open question 3 in
   [`START_HERE.md` §11](START_HERE.md), and §5.1 gives it an owner. Payroll
   reads the answer.
4. **Is there a company directory?** Can an employee look up a colleague's unit
   and extension, or does the app show only their own profile? It is a privacy
   decision, and it changes what §6.2 exposes.
5. **Holidays:** who maintains the عطل رسمية calendar, and does it vary by
   worksite? (§5.5)
6. **The legacy accounts:** fresh credentials for everyone, or carry the existing
   app users over? §7.1 recommends fresh — the legacy `users` table holds no
   employee number, so the match is by name, and the new identifier is one that
   system never had.

---

## 9. One observation from the legacy side

**Stage 0 — the legacy schema freeze — has not started, and the legacy system is
still gaining migrations.** Two landed in the last two days:

```
2026_09_20_000001_add_cancellation_to_leave_requests_table.php
2026_09_21_000001_add_return_to_work_to_leave_requests_table.php
```

Both are leave-flow features, which means they are features the Stage 3 port must
replay, and neither is in this document or in the merge plan. That is not an
argument for freezing today — the merge plan's own wording is *"announce the
freeze; keep it short by moving through the stages."* It is an argument for
knowing what is accumulating, because every migration added after a port is
specified is a migration someone has to notice.

---

## Cross-references

- [`legacy-system-merge-plan.md`](legacy-system-merge-plan.md) — the stage order
  this document attaches to
- [`START_HERE.md` §11–§12](START_HERE.md) — the open questions and known
  limitations several sections here touch
- [`API_BUILD_STANDARD.md` §12](API_BUILD_STANDARD.md) — GAP-3 (§4.1), GAP-12 and
  GAP-13 (§6.1)
- [`phase-1.5-foundation-truth-up.md`](phase-1.5-foundation-truth-up.md) — the
  employee schema every §6 endpoint reads
