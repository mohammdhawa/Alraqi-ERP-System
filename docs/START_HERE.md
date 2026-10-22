# Al-Raqi ERP — START HERE

> **The one file to read when you come back to this project.**
> Everything else in `docs/` is a deep-dive on one topic. This file is the map:
> what the system is, how it is built, what is finished, and what to do next.
>
> **Last verified against the code:** 2026-09-23, after the **account model**
> (login by employee number + the forced first-login password change) landed on
> branch `phase_1`, on top of Phase 1.5 **Batch 2**.
> When you change the system, update the sections marked ⚙️ — they state facts
> about the code and go stale if ignored.

---

## Table of contents

1. [What this project is](#1-what-this-project-is)
2. [Status at a glance](#2-status-at-a-glance)
3. [Getting the project running](#3-getting-the-project-running)
4. [Which document to read for what](#4-which-document-to-read-for-what)
5. [Architecture](#5-architecture)
6. [The database, table by table](#6-the-database-table-by-table)
7. [The API surface](#7-the-api-surface)
8. [The roadmap — the real one](#8-the-roadmap--the-real-one)
9. [Where we are, in detail](#9-where-we-are-in-detail)
10. [What to do next — Batch 3, the import tooling](#10-what-to-do-next--batch-3-the-import-tooling)
11. [Open questions waiting for an answer](#11-open-questions-waiting-for-an-answer)
12. [Known limitations](#12-known-limitations)
13. [Conventions — how code is written here](#13-conventions--how-code-is-written-here)
14. [Arabic to English glossary](#14-arabic-to-english-glossary)

---

## 1. What this project is

An **ERP system for شركة الراقي للإنشاءات**, a Syrian construction company.
This repository is the **backend only** — a Laravel API. The React web dashboard
and the Flutter mobile app are separate projects that consume this API.

Built by one developer (the technical department manager) with AI assistance.

**Two systems exist today, and that matters:**

| | This ERP | The legacy system |
|---|---|---|
| Stack | Laravel 12, backend only | Laravel 10 + React + Flutter |
| State | Foundation built, **no production data** | **In daily production use** |
| Holds | Org tree, employees, users, RBAC, audit, notifications | Documents, approvals, attendance, leave |

The ratified decision is to **merge the legacy system into this one**, module by
module, with the ERP as the host. Not a rewrite, not a big bang. See §8.

### Technology stack

- **PHP** `^8.2`, **Laravel** `12.x`
- **Auth:** Laravel Sanctum (bearer tokens) + a custom refresh-token table with rotation
- **Database:** MySQL in production; SQLite `:memory:` in tests
- **Cache / queue / session:** database driver (see `.env.example`)
- **Formatting:** Laravel Pint (`pint.json` is configured to preserve the aligned `=>` style)
- **No Spatie permission package** — RBAC is custom, on purpose (see §5.6)
- **Locale:** Arabic (`APP_LOCALE=ar`). API messages are Arabic strings.

---

## 2. Status at a glance

| | |
|---|---|
| **Branch** | `phase_1` |
| **Test suite** | **618 passing**, 2664 assertions (2026-10-15) — the latest work is the §4.4 importer; its handoff is the last entry of `EMPLOYEE_APP_V1_WORK_ORDER.md` §8 |
| **Working tree** | Batch 2, سجل التدقيق, the account model and GAP-3/14/15 are committed on `phase_1`; check `git status` for anything since |
| **Open gaps** | none — `API_BUILD_STANDARD.md` §12 (GAP-16 to 19 all closed 2026-09-28) |
| **Production data** | none — the database has never held a real employee |

**Done:** Phase 1 (the whole foundation) + Batches 1 and 2 of Phase 1.5
(departments, then the employees rebuild).

**Not done:** Batches 3 and 4 of Phase 1.5 — the import tooling and the real data
import.

**Next:** the Employee App v1 pilot, due 2026-11-01 — [`EMPLOYEE_APP_V1_WORK_ORDER.md`](EMPLOYEE_APP_V1_WORK_ORDER.md). It starts with §10, Batch 3.

---

## 3. Getting the project running

```bash
composer install
```

```bash
php artisan migrate:fresh --seed
```

```bash
php artisan test
```

```bash
./vendor/bin/pint
```

First time on a new machine: `cp .env.example .env` then `php artisan key:generate`
before migrating.

**What seeding gives you** (`database/seeders/DatabaseSeeder.php`):

1. `PermissionSeeder` — the 18 permission rows (16 route-level + 2 field-level)
2. `RoleSeeder` — the `super_admin` role
3. `DepartmentSeeder` — **the real Al-Raqi org tree**, 119 rows, read from the HR
   workbook and cross-checked against the org chart
4. `DevDepartmentSeeder` — invented units, fake staff, and a login account.
   **Skipped when `APP_ENV=production`.**

That split is deliberate. The tree used to be seeded with invented units like
`قسم تطوير البرمجيات` — harmless while it was a demo fixture, and not harmless
the moment 242 real employees are imported against `departments.code`, because
neither a developer nor an import script can tell a fabricated unit from a real
one by looking.

---

## 4. Which document to read for what

`docs/` holds nineteen files written at different times. Several describe states the
code has since moved past. This table says which one to trust for what.

| Document | Trust it for | Caution |
|---|---|---|
| **START_HERE.md** (this file) | Current state, the map, what to do next | — |
| **`API_BUILD_STANDARD.md`** | **How to build any feature.** The binding rules, the layer contracts, the build workflow, the review checklist | Its §12 gap register lists where the code does not yet comply — read it before copying an existing pattern |
| `phase-1.5-foundation-truth-up.md` | **The active plan.** §5–§7 are your next work | §4 is done; its status markers record what changed |
| `legacy-system-merge-plan.md` | **The real phase order** (stages 0–5) | Supersedes the design doc's phases 2 and 3 |
| **`EMPLOYEE_APP_REQUIREMENTS.md`** | **What the employee mobile app needs from HR**, mapped onto the stages, plus the decisions it forces before the import | Requirements captured 2026-09-21, not scheduled work. It settles GAP-3 and names work no stage owns |
| ⚙️ **`EMPLOYEE_APP_V1_WORK_ORDER.md`** | **The plan for October 2026**: the app's v1 pilot on the ERP, built through Batch 3/4, attendance, leave and the approvals engine. Its §3 lists thirteen decisions with defaults and deadlines | Written 2026-10-05; nothing in it is built yet |
| ⚙️ **`LEGACY_TO_ERP_MAPPING.md`** | **What every legacy attendance, leave, approval, device and notification table, route and permission becomes** in the ERP, and which data moves | First pass, 2026-10-05. Documents are not mapped yet (November) |
| **`GAP3_SCOPED_AUTHORIZATION_WORK_ORDER.md`** | **The build plan for row-level authorization** — the four pieces, the traps, and what is out of the first slice | Written 2026-09-24 as a handoff. The decisions it implements are §9 (14, 18, 19); this file is the *how* |
| `phase1-completion-report.md` | Why Phase 1 is shaped the way it is; the decision index in §5 | Written before Phase 1.5; its "what comes next" (§7) is superseded |
| `API_DOCUMENTATION.md` | The API contract, for frontend work | Reflects today's employees shape — Batch 2 will change it |
| `PROJECT_ARCHITECTURE_REPORT.md` | Deep architecture reference; code docblocks cite its §16 | Long; several numbering schemes coexist across docs |
| `SYSTEM_ARCHITECTURE_GUIDE.md` | Architecture walkthrough | Overlaps the report above |
| `al-raqi-erp-design.md` | The original nine-module vision, in Arabic | **Phases 2 and 3 are superseded**; keep it for the later modules |
| `erp-phase1-architecture.md` | Only §7.2 (notifications) is ratified | Mostly a stub — the original full spec was never committed |
| `phase1-audit-report.md` | Historical record of the 27 audit findings | **Describes the pre-fix state.** Do not read as current |
| `AI_AGENT_BASE_PROMPT.md` | The task-framing and output-format sections only | ⚠️ **Its architecture and technical-debt sections are stale and misleading** — it calls the `permission` middleware a placeholder (it is full RBAC), references `App\Models\User` (deleted), and says tests are minimal (143 pass). Use `API_BUILD_STANDARD.md` for the rules |
| `Guide-Line/1_Auth.md` | Auth module guideline | — |

**Rule of thumb:** the code's own docblocks are the richest and most current
record of *why* — they were kept up to date deliberately. When a document and the
code disagree, the code is truth for *what*, and the docblock is usually truth
for *why*.

---

## 5. Architecture

### 5.1 The shape: a modular monolith

One Laravel application, split into self-contained modules under `app/Modules/`.
Three exist today:

```
app/Modules/
  Auth/           users, roles, permissions, login, refresh tokens, notifications
  Departments/    the organizational tree
  HR/             employees
```

Each module has the same anatomy:

```
Auth/
  Controllers/    thin — validate in, call a service, return a resource
  Services/       all business logic lives here
  Models/         Eloquent models; invariants enforced in model hooks
  Requests/       FormRequest validation
  Resources/      JSON shaping (the API response contract)
  Enums/          typed value lenses
  Exceptions/     domain exceptions mapped to HTTP codes
  Support/        guards, caches, small helpers
  routes.php      the file that activates the module
```

**Modules register themselves.** `app/Providers/ModuleServiceProvider.php` scans
`app/Modules/`; any folder containing a `routes.php` is loaded automatically with
the prefix `/api/{module-lowercased}` and the `api` middleware group. The same
provider loads migrations from every subdirectory of `database/migrations/`.

**To add a module:** create `app/Modules/{Name}/` with a `routes.php`, and
`database/migrations/{Name}/` for its tables.

One exception to "nothing else to register": an audited or polymorphic model
must be added to the morph map in `AppServiceProvider` (`MorphMapTest` fails
the build otherwise). Routes and migrations remain discovery-based.

Shared, cross-module code lives in `app/Shared/`:

```
app/Shared/
  Middleware/CheckPermission.php     the `permission:` route middleware
  Middleware/AuditLogMiddleware.php  the `audit` route middleware
  Services/AuditLogService.php       writes the audit trail
  Traits/ApiRespond.php              the standard response envelope
  Traits/HasAuditLog.php             put on a model to audit its changes
```

### 5.2 The four principles

Almost every non-obvious decision in this codebase is one of these four applied.
They are stated in `phase1-completion-report.md` §2 and are worth memorizing —
they make the code read as inevitable rather than arbitrary.

**P1 — One fact, one place.** A fact is stored exactly once; everything else is a
lens over it or a pointer to it. Two columns describing one fact drift apart the
moment someone writes one without the other.
*Examples:* `users` has no `name` column — a person's name lives on their
`employees` row, reached through `users.employee_id`. Manager status is
`departments.manager_id`, never an `employees.is_manager` flag. Seniority years
(`سنوات القدم`) are never stored — they are `hire_date` and today's date.

**P2 — Structure over discipline.** Make invalid states impossible at the layer
*every* writer crosses. Never rely on someone remembering to call the validator.
*Examples:* the org-tree invariants live in the `Department` model's `saving`
hook — the one layer the controller, service, seeder, tinker and future jobs all
pass through — not only in the FormRequest. `employee_number` is generated in a
`creating` hook so every write path gets one. Nullable-unique indexes make "two
logins for one employee" impossible in the schema itself.
**This is the single most important idea in the codebase.**

**P3 — Explicit over implicit.** A forgotten value should fail loudly, not resolve
to a wrong-but-plausible default.
*Example:* `departments.level` has **no default** — a writer that forgets the tier
gets an error instead of silently creating a second root. That exact bug once
produced four roots.

**P4 — Data changes, not code.** Where the domain will grow, growing it should be
editing data or adding one enum case — not a migration and a redeploy.
*Examples:* adding the `مكتب` tier was literally one line (`case Office = 4;`).
`super_admin` gains every future permission automatically through the
`Gate::before` bypass, with no re-seed.

### 5.3 The request path

```
HTTP request
  -> api middleware group
  -> auth:sanctum          is there a valid access token?
  -> audit                 record the request
  -> permission:{name}     does this user hold the permission?
  -> Controller            thin
  -> FormRequest           validation, Arabic messages
  -> Service               business logic, transactions, locks
  -> Model                 invariants in saving/creating hooks
  -> Resource              JSON shaping
  -> ApiRespond envelope   { success, message, data }
```

### 5.4 Identity: employees are not users

The founding design decision, and the reason the two tables are separate:

- **`employees`** — every person who works at the company, whether or not they
  ever log in. 242 active people, most of whom will never touch the system.
- **`users`** — only those with system access.
- `User belongsTo Employee` through a **nullable unique** `users.employee_id`.
  Every user is an employee; not every employee is a user. Nullable allows
  service accounts; unique forbids two logins for one person.

`users.is_active` ("may this account log in") is deliberately **separate** from
`employees.status` ("is this person employed"). Two different facts.

### 5.5 The organizational tree

A single self-referencing `departments` table (an adjacency list). Four tiers,
held in the int-backed `DepartmentLevel` enum:

| Level | Enum case | Arabic |
|---|---|---|
| 1 | `GeneralAdministration` | الإدارة العامة |
| 2 | `Division` | إدارة |
| 3 | `Section` | قسم |
| 4 | `Office` | مكتب |

**Invariants**, owned by `DepartmentHierarchyGuard` and called from both the
model's `saving` hook and the FormRequest:

- exactly **one** row with `parent_id IS NULL`, and it must be level 1
- a child's level must be exactly its parent's level + 1
- a department cannot be its own ancestor (no cycles)
- the root cannot be deleted; a department with children cannot be deleted
- tier is read from `level`, **never** from `parent_id IS NULL`

**`sector` (الجهة)** is an attribute, not a tier — the ratified answer to a real
question. It is declared **only on level-2 (`إدارة`) rows** and **inherited** by
everything beneath by walking up to the first non-null ancestor. It is never
copied down: a re-parented section would then carry a stale sector and hide the
truth from its whole subtree. Two cases: `1 = المكاتب الإدارية`,
`2 = الأجسام الإدارية`.

**Seeded shape:** 1 root + مجلس الإدارة + مكتب المدير العام + 13 divisions +
53 sections + 50 offices = **119 rows**, asserted exactly in
`tests/Feature/Departments/DepartmentSeederTest.php`.

One relationship is **knowingly wrong**: `مجلس الإدارة` sits *under* the
administration the general manager heads, whereas in reality the board oversees
him. `parent_id` there means administrative containment, not authority. It is
affordable only because the board is a container and nothing escalates to it —
**whoever writes the escalation walk must skip it.** If a decision ever genuinely
needs board sign-off, this compromise expires and the tree becomes five-deep.

### 5.6 RBAC

Custom-built, no Spatie package, so the schema, the naming, `is_system` and the
bypass are all fully controlled.

- **Permissions** are named `{module}.{resource}.{action}` — e.g.
  `hr.employees.create`. The name is *exactly* the string the route middleware
  passes, so the seeded rows are what make authorization work at all.
- **Roles** hold permissions through a composite-primary-key pivot, so a
  duplicate grant is impossible by schema.
- **`super_admin` holds zero permissions.** Its power is a `Gate::before` bypass
  in `AppServiceProvider`, keyed on the role name. Every future permission is
  therefore covered automatically with no re-seed (P4). `is_system = true` makes
  the role un-renamable and un-deletable at the service layer (HTTP 409).
- **Permission cache** — per user, invalidated by a global version stamp, so one
  RBAC change lazily re-warms everyone and can never serve a stale grant.
  Requires a **shared** cache store in production; it is process-local under the
  SQLite test driver.

⚙️ The **30** permissions today — 27 route-level:
`departments.{view,create,update,delete}`,
`hr.employees.{view,create,update,delete}`,
`hr.job_titles.{view,create,update,delete}`,
`hr.assignments.{view,create}`,
`hr.warnings.{view,create,update,delete}`,
`auth.users.{view,create,update,delete}`, `auth.roles.{view,create,update,delete}`,
`auth.audit.view`;
plus 3 **field-level**, checked inside a Resource rather than by any route:
`hr.employees.viewSalary`, `hr.employees.viewIdentity` and
`auth.audit.viewValues`. Without them the keys are absent from the payload, not
null. `PermissionSeederTest` asserts the catalogue exactly and cross-checks that
every permission any route references is actually seeded.

`hr.assignments` has **no update or delete, deliberately**: the posting history
is append-only, because editing a historical posting changes what a payslip was
computed against with no trace of the original. There is no route either.

`auth.audit` goes further and has **no write action at all**. `audit_logs` is
append-only by design — in production the DB user should hold INSERT and SELECT
only — so there is no power to grant. `auth.audit.viewValues` is separate from
`.view` because `AuditLogService::sanitize()` redacts the salary but not the
classification axes it is computed from, so a single permission would make the
trail a way around `hr.employees.viewSalary` (see GAP-13 in
`API_BUILD_STANDARD.md`).

⚙️ **`viewValues` is necessary but not sufficient** (2026-09-20). The values
also require the view permission of the module the row is about —
`hr.employees.view` for an `employee` row, `auth.users.view` for a `user` row.
Without that, `viewValues` was a read-around for *every* module's permissions,
not only `viewSalary`: `HasAuditLog` copies the whole record into `new_values`,
so the trail republished every audited table to anyone who could read the trail.
`AuditLogDetailResource::TYPE_VIEW_PERMISSIONS` is the map, and it fails closed.

✅ ⚙️ **And since 2026-09-23 the map holds a LIST per type, all required** —
`employee` carries `hr.employees.view` **and** `hr.employees.viewSalary`. That
closes the last of GAP-13: the axes are not redacted in the trail (deliberately
— redaction there is permanent and would erase "who changed this grade, and to
what"), so reading them has to cost the same permission as reading them on the
employee.

⚙️ Batch 2 widened `viewSalary`. It no longer gates one amount but the whole pay
block *and every classification axis* — `activity_type`,
`organizational_grade`, `experience_level`, `engineering_rank`, the four flags,
`agreed_salary`, `sham_cash_account`. The axes are the inputs the salary is
computed from, so gating the result while publishing them would be theatre.

### 5.7 Audit trail

`audit_logs` records who changed what, when, and from where. Two entry points:
the `audit` route middleware (request-level) and the `HasAuditLog` trait on
models (change-level, with `old_values` / `new_values` JSON).

Two deliberate details: writes go through `DB::table()` rather than Eloquent, so
auditing can never recurse into auditing itself; and `password` and other secrets
are **redacted before storage** — that redaction list must grow as new sensitive
fields are added.

`user_id` is nullable on purpose: seeders, console commands and jobs have no
authenticated user.

### 5.8 Notifications

A custom `notifications` table keyed by `user_id`, with **snapshot fan-out**:
when a company-wide message is sent, one row is written per recipient, capturing
the message as it was at that moment. Laravel's stock notification schema and
`$user->notify()` are unusable here by design.

**Transport is still undecided** (FCM / mail / websockets / polling). The merge
plan closes this in Stage 3 by adopting the legacy system's working FCM layer
rather than inventing a new answer.

### 5.9 Deletion

Soft deletes everywhere on domain tables. Employees are **never** hard-deleted:
users, departments (`manager_id`) and every future module hold FKs into them, and
their identity must stay resolvable after they leave. Deleting sets `deleted_at`;
the row survives. Because `restrictOnDelete` does not fire on a soft delete, the
"has children" guard lives in the model hook instead.

⚙️ **`users` and `roles` gained soft deletes on 2026-09-08**, and `users` is the
one that mattered. `audit_logs.user_id` is `nullOnDelete`, so hard-deleting an
account **nulled that user's id across the entire audit trail** — turning every
action they ever took anonymous, on an append-only table, for exactly the people
an audit is most often asked about: the ones who left. It also cascaded away
their notifications. Deleting a user now revokes their tokens explicitly (a soft
delete fires no cascade) and leaves the row resolvable.

The accepted trade-off: the unique indexes on `users.email`, `users.employee_id`
and `roles.name` still count trashed rows, so a deleted account's email cannot be
reused until the row is force-deleted. That is deliberate — letting a new person
inherit a departed one's email would let them inherit that identity's meaning in
the audit trail too.

### 5.10 API conventions

Every endpoint returns the same envelope:

```json
{ "success": true, "message": "تمت العملية بنجاح.", "data": {} }
```

```json
{ "success": false, "message": "فشل التحقق من البيانات المُدخلة.", "errors": { "email": ["..."] } }
```

⚙️ **There are no exceptions — every response carries `success`**, including
`401`, `404`, `405`, `422`, `429` and `5xx`. That was not true until 2026-09-08:
all of those came from Laravel's own handlers as a bare `{"message": …}`, and the
`404` from route-model binding leaked the fully-qualified model class even with
`APP_DEBUG=false`. `ApiExceptionRenderer` is now the one place an error is
shaped. Still trigger the client's refresh flow on the **`401` status code**, not
on `success` — a `403` is also `success: false` and must never refresh.

Messages are Arabic and display-ready. Every list endpoint is paginated — 15 per
page by default, `?per_page=` honoured and clamped to 1–100, with a six-key
`meta` block as a sibling of `data`. `403` means a permission denial or a
disabled account.

---

## 6. The database, table by table

Migrations live under `database/migrations/{Module}/` and are auto-loaded.

### `departments` — the org tree

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `name` | string | |
| `code` | string, nullable **unique** | the import key |
| `description` | text, nullable | |
| `is_active` | bool, default true | `المبيعات` is seeded `false` pending its meeting |
| `parent_id` | FK to self, nullable | exactly one row may be NULL |
| `level` | tinyint, indexed, **no default** | 1–4, the tier |
| `sector` | tinyint, nullable, indexed | declared at level 2 only, inherited downward |
| `manager_id` | FK to `employees`, nullable | added in a later migration to break the circular FK |
| timestamps + `deleted_at` | | soft deletes |

The circular FK between `departments` and `employees` is split across three
ordered migrations: create departments (no manager FK) -> create employees (with
the department FK) -> add the manager FK to departments. `parent_id` is exempt,
because a self-referencing FK can be declared at creation.

### `employees` — every person who works here ⚙️ (rebuilt in Batch 2)

Roughly 45 columns now, in six groups. The full list is in the migration
`database/migrations/HR/2026_09_13_110000_restructure_employees_table.php`, which
also records what was dropped and why.

| Group | Columns |
|---|---|
| Identity | `employee_number` (unique, auto-generated, **overridable** — a hand-typed `M024333` is kept as given), `first_name`, `middle_name`, `last_name`, `mother_name`, `national_id`, `gender`, `birth_date`, `birth_place`, `origin_governorate`, `residence_governorate`, `nationality`, `second_nationality` |
| Family | `marital_status`, `wives_count`, `sons_count`, `daughters_count` |
| Contact | `phone`, `phone_secondary`, `email`, `address` |
| Military | `did_military_service`, `military_discharge_date` |
| Education | `education_level`, `qualification`, `specialization`, `is_graduated`, `graduation_date` |
| **Classification (pay-determining)** | `activity_type`, `organizational_grade`, `experience_level`, `engineering_rank`, `industrial_experience_years`, `has_hazard_pay`, `has_scarcity_pay`, `has_dual_role_pay`, `distinction_grade` |
| Employment | `department_id`, `job_title_id`, `employment_type`, `workplace`, `hire_date`, `termination_date`, `status` |
| Pay reference | `sham_cash_account` (16 digits, unique), `salary_mode`, `agreed_salary` |
| | timestamps + `deleted_at` |

**Three things are deliberately absent**, and each has a reason worth keeping:

- **`salary`.** For `salary_mode = scale` — the large majority — pay is computed
  from the criteria above, not stored. Caching it means changing the company
  scale factor silently makes every row wrong.
- **`name`.** Three parts, plus a `full_name` accessor. Storing both is two
  columns for one fact — the failure mode the workbook demonstrates with its
  `مقارنة الأسماء` column.
- **`seniority_years`.** It is `hire_date` and the payroll period.

Also absent by the owner's ruling (2026-09-13): **الديانة، المذهب،
السوابق الجنائية**. The intake form collects them; no requirement here reads
them, so they are not imported.

⚠️ `organizational_grade` (السوية التنظيمية) is a **position grade** and
`departments.level` is a **unit tier**. Both are 1–5 and they are *not* the same
fact.

### `job_titles` — the governed catalogue ⚙️ (new in Batch 2)

`name` (**unique, company-wide — no `department_id`**),
`default_organizational_grade`, `default_activity_type`, `is_described`
(`تم التوصيف`, indexed), `description`, `is_active`, timestamps + soft deletes.

A title staff hold — or ever held — cannot be deleted (**409**). The unique index
counts trashed rows, so a deleted name stays reserved.

### `employee_assignments` — سجل التعيين ⚙️ (new in Batch 2)

`employee_id`, `department_id`, `job_title_id`, `organizational_grade`,
`from_date`, `to_date` (NULL = current), `reason`, `legacy_workplace`,
`legacy_manager_name`, `legacy_job_title` ⚙️ (2026-10-15), `notes`, timestamps +
soft deletes. The three `legacy_*` columns are import-only free text from the
workbook's `بداية التعيين` columns; no endpoint writes them.

Replaces the workbook's three frozen `بداية التعيين` columns *and* its separate
`ملف النقل` transfer sheet. `employees.department_id` survives as the
denormalised current pointer because it is on every hot query path; the two are
kept in step by a model hook **in each direction**, so no writer can
desynchronise them. The recursion is broken by the assignment→employee direction
writing through `DB::table()` (no model events) — the same technique
`AuditLogService` uses so auditing cannot audit itself.

At most one row per employee may have `to_date IS NULL`. That is a partial unique
index, which MySQL does not have, so it lives in the model's `saving` hook.

### `employee_documents`, `employee_warnings` ⚙️ (new in Batch 2)

`employee_documents` — schema and model only, **no endpoints yet**. It exists so
the Batch 3/4 importer can attach the intake form's four images per hire; upload
and download belong to the Documents module arriving by port in Stage 2.
⚠️ Its `ShamCash` type is a payment account and must read through
`hr.employees.viewSalary` when those endpoints are built.

`employee_warnings` — `type`, `number`, `issued_on`, `issued_by`, `notes`. Rows,
not the workbook's three columns, which let an employee hold exactly one warning
forever. `sequence` is **derived at read time**, so withdrawing #2 of 3
renumbers the third.

### `users` — accounts that can log in

`id`, `email` (unique), `email_verified_at`, `password`, `last_login_at`,
`remember_token`, `is_active`, `employee_id` (FK, **nullable unique**),
timestamps. **No `name` column** — that is P1 in action.

⚙️ **Shipped 2026-09-23** — the account model in §9. `email` is **nullable** (a
contact field, not the identity), and `must_change_password` + `password_changed_at`
are new. The login identifier is the employee number, resolved through the
already-unique `employee_id`: **no number is copied onto this table.**

### `refresh_tokens`

`id`, `user_id` (FK), `token_hash` (sha-256, 64 chars), `revoked` (bool),
`expires_at`, `created_at`, index on `(user_id, revoked)`.

Rotation with **replay detection**: presenting an already-used refresh token is
treated as theft. `PruneExpiredRefreshTokens` is the scheduled cleanup command.

### `roles`, `permissions`, and the two pivots

- `roles` — `name` (unique), `label`, `description`, `is_system` (indexed)
- `permissions` — `name` (unique), `label`, `module` (indexed), `description`
  (the column is `module`, not `group`, because `group` is a MySQL reserved word)
- `role_permissions` — `(role_id, permission_id)` composite PK, cascade
- `user_roles` — `(user_id, role_id)` composite PK, cascade

### `audit_logs`

`user_id` (nullable), `event`, `auditable_type` + `auditable_id`, `old_values` /
`new_values` (JSON), `description`, `ip_address`, `user_agent`, `request_id`,
`created_at`.

Indexed for the queries the read surface actually runs: `(event, created_at)`
for the default screen (`event_group=model,auth` ordered newest-first, against a
table that is ~99% `api_request` rows), `(auditable_type, auditable_id)` for a
record's own history, plus `user_id`, `request_id`, `ip_address` and
`created_at`. **Every filter key in `AuditTrailService::FILTERABLE` is backed by
one, and adding a key means adding the index in the same commit** — this is the
one table that grows without bound, and it also takes the most INSERTs in the
system, so an index here is paid for on every authenticated request.

### `notifications`

`user_id` (FK, cascade), `title`, `body`, `type`, nullable morph `reference`,
`is_read` (indexed), timestamps.

### Framework tables

`personal_access_tokens` (Sanctum), `cache`, `cache_locks`, `jobs`,
`job_batches`, `failed_jobs`, `sessions`, `password_reset_tokens`.

---

## 7. The API surface

Base URL `{APP_URL}/api`. Bearer token in `Authorization`. ⚙️ **60 routes**
today (`route:list`, 2026-10-16) — the 41 below, the sessions and device-token
routes of work order §4.3 (six), and thirteen attendance routes. The last two
groups are rows 42–60 at the end of the table.

⚙️ **Every** route carries the baseline `api` throttle at 60/min; login and
refresh carry tighter named limiters on top. Every response — success and error
— carries the standard envelope and an `X-Request-Id` header.

| # | Method | Path | Auth | Permission |
|---|---|---|---|---|
| 1 | POST | `/api/auth/login` | public | — (5/min) |
| 2 | POST | `/api/auth/refresh` | public | — (10/min) |
| 3 | POST | `/api/auth/logout` | bearer | — |
| 4 | GET | `/api/auth/me` | bearer | — |
| 5 | POST | `/api/auth/password` | bearer | — (self-scoped; the only route that clears `must_change_password`) |
| 6–9 | GET/POST/PUT/DELETE | `/api/auth/users` | bearer | `auth.users.*` |
| 10–13 | GET/POST/PUT/DELETE | `/api/auth/roles` | bearer | `auth.roles.*` |
| 14 | POST | `/api/auth/roles/assign` | bearer | `auth.roles.assign` |
| 15 | POST | `/api/auth/roles/unassign` | bearer | `auth.roles.assign` |
| 16 | GET | `/api/auth/notifications` | bearer | owner-scoped |
| 17 | GET | `/api/auth/notifications/unread-count` | bearer | owner-scoped |
| 18 | POST | `/api/auth/notifications/{id}/read` | bearer | owner-scoped |
| 19–20 | GET/GET | `/api/auth/audit-logs` (+ `/{id}`) | bearer | `auth.audit.view` — **reads only** |
| 21–25 | GET/POST/GET/PUT/DELETE | `/api/departments` | bearer | `departments.*` |
| 26–30 | GET/POST/GET/PUT/DELETE | `/api/hr/employees` | bearer | `hr.employees.*` |
| 31–35 | GET/POST/GET/PUT/DELETE | `/api/hr/job-titles` | bearer | `hr.job_titles.*` |
| 36–37 | GET/POST | `/api/hr/employees/{employee}/assignments` | bearer | `hr.assignments.{view,create}` |
| 38–39 | GET/POST | `/api/hr/employees/{employee}/warnings` | bearer | `hr.warnings.{view,create}` |
| 40–41 | PUT/DELETE | `/api/hr/warnings/{warning}` | bearer | `hr.warnings.{update,delete}` |
| 42 | POST | `/api/auth/logout-all` | bearer | — (every session) |
| 43–44 | GET/DELETE | `/api/auth/sessions` (+ `/{id}`) | bearer | self-scoped |
| 45 | POST | `/api/auth/notifications/read-all` | bearer | owner-scoped |
| 46–47 | POST/DELETE | `/api/me/device-tokens` | bearer | self-scoped |
| 48–49 | GET/POST | `/api/me/attendance` | bearer | self-scoped — no permission (Q7) |
| 50 | POST | `/api/me/attendance/sync` | bearer | self-scoped |
| 51 | GET | `/api/me/attendance/{record}/selfie` | bearer | self-scoped |
| 52 | GET | `/api/attendance/records` | bearer | `attendance.records.view`, row-scoped |
| 53 | GET | `/api/attendance/records/{record}/selfie` | bearer | `attendance.records.view`, row-scoped |
| 54 | PATCH | `/api/attendance/records/{record}/checkout` | bearer | `attendance.records.correct`, row-scoped |
| 55–56 | PATCH/DELETE | `/api/attendance/records/{record}/reject` | bearer | `attendance.records.reject`, row-scoped |
| 57–59 | GET | `/api/attendance/report` (+ `/monthly`, `/employee`) | bearer | `attendance.records.view`, row-scoped |
| 60 | GET | `/api/attendance/absent-today` | bearer | `attendance.records.view`, row-scoped |

Full request and response bodies are in `API_DOCUMENTATION.md`. A Postman
collection lives in `postman/`.

**For the frontend:** login and `/me` both return the user's `permissions` array
— gate the UI on it rather than making an extra request. Refresh on `401` only,
never on `403`.

⚠️ **Contract changes on 2026-09-08**, documented in `API_DOCUMENTATION.md` §1:
`/api/auth/users` and `/api/auth/roles` are now **paginated**, and `salary` /
`national_id` are omitted from employee payloads unless the caller holds the
matching field-level permission.

⚠️⚙️ **BREAKING contract change on 2026-09-13 (Batch 2)** — every client that
reads an employee must be updated. Written up as
[`API_DOCUMENTATION.md` §8.0](API_DOCUMENTATION.md) with migration notes:

- `name` → `first_name` / `middle_name` / `last_name` + read-only `full_name`
- `job_title` (string) → `job_title_id` (FK) + an embedded `job_title` object
- `salary` → **removed** (`agreed_salary` exists only on the إتفاق track)
- `status: "active"` → `status: 1` + `status_label: "نشط"`, matching how `level`
  / `level_label` already ship on departments. The old `inactive` case is now
  **`موقوف` (suspended)**, which closes §11 open question 2.
- every enum field ships as `{field}` (int) + `{field}_label` (Arabic)

`/api/auth/me` and `/api/auth/users` still return `name` — that key is an
*account's* display name and is unchanged. Only the employee resource split.

---

## 8. The roadmap — the real one

There are two roadmaps in `docs/`, and they disagree. This section says which one
is live.

**The original plan** (`al-raqi-erp-design.md`) had nine modules built in order:
employees/departments/permissions, attendance and leave, payroll, tasks,
projects, purchasing, warehouses, assets, finance.

**What changed:** the company already runs a legacy Laravel system in production
that *does* documents, approvals, attendance and leave. Rebuilding those from a
spec would throw away working, battle-tested code. So the ratified plan
(`legacy-system-merge-plan.md`) is to **absorb the legacy system module by
module**, and the phase order changed accordingly.

### The live sequence

| Stage | Work | State |
|---|---|---|
| **0** | Freeze legacy schema changes (bug fixes excepted) | not started |
| **1** | **Phase 1.5 truth-up + real data import** | **in progress — 1 of 4 batches done** |
| **2** | Documents + a shared approval engine (ported from legacy) | not started |
| **3** | Attendance + Leave (ported from legacy) | not started |
| **4** | Payroll (built new) | not started |
| **5** | Decommission the legacy backend | not started |
| later | Tasks, Projects, Purchasing, Warehouses, Assets, Finance | design doc phases 4–9 |

⚙️ **Reordered 2026-10-05 (decision 25 in §9).** The approvals engine is built
with **leave as its first consumer**, so Stages 2 and 3 now run as: approvals +
attendance + leave (a pilot by 2026-11-01), then documents onto the same engine
(November), then the Stage 3 parallel month and cutover (~2026-12-01). One
approval implementation, as before — only the order of its consumers changed.
The plan is [`EMPLOYEE_APP_V1_WORK_ORDER.md`](EMPLOYEE_APP_V1_WORK_ORDER.md).

⚙️ **One dependency is missing from this table.** Stage 4 payroll reads
`التمييز` (`employees.distinction_grade`), and **no stage builds the performance
evaluation that sets it** — neither system has one.
[`EMPLOYEE_APP_REQUIREMENTS.md` §5.1](EMPLOYEE_APP_REQUIREMENTS.md) records that
gap and the rest of what the employee mobile app needs; its §7 lists the
decisions that must be taken before Batch 4 runs.

### Why this order

- **Documents before attendance** because of the approval engine. Leave ships
  with its own approval flow; if Leave were ported first the ERP would gain a
  second approval implementation to unify later. Extract the shared engine once,
  then port Leave onto it.
- **Attendance is the highest-risk cutover** — used every morning by everyone,
  and the Flutter app holds an *offline queue* that can contain records created
  before the cutover and submitted after it. The migration must accept
  legacy-format submissions for a grace period (a fortnight is the opening
  proposal), not assume every phone updates on cutover day.
- **Payroll after attendance** because it needs attendance, leave, evaluation,
  org grade and the employee criteria all in one database.
- **Projects moved after payroll.** The HR workbook is evidence that HR and
  payroll are the live pain; Projects has no upstream dependents waiting.

### Two things the old design doc gets wrong about payroll

Both are already flagged in `phase-1.5-foundation-truth-up.md` §5.6:

1. `salary_structures` with fixed housing/transport/food allowances is **not how
   this company pays**. It needs a *versioned, effective-dated criteria rate
   table* holding the value of each criterion case plus the company scale factor
   (`النسبة المئوية للقيمة`, currently `0.75`). The monthly workbook snapshots
   are that table's version history.
2. The `إتفاق` track — a flat negotiated figure — bypasses the calculation
   entirely and must be a first-class path, not a special case.

What the design doc gets **right** and must be kept: the
`amount_calculated` / `amount_final` pair on payslip lines. HR reviews and
adjusts a generated draft, and the original survives the adjustment. That is
exactly what the workbook does by hand today.

⚙️ **A rule neither document states: a payslip snapshots its own inputs.** The
rate table versions the *rates*, and `employee_assignments` versions the grade,
but nothing records the *employee's* other criteria as they stood when the
payslip was computed — `activity_type`, `experience_level`, `engineering_rank`,
the three flags, `distinction_grade`, the seniority years used, `salary_mode` —
and those are plain current-value columns on `employees`. The audit log keeps
their old values, but stamped with when someone typed the change, not when it
took effect, so payroll cannot read them back. So each payslip line records the
criterion, the case it applied, and the rate-table version it read. An approved
payslip then explains itself even after the employee row or a posting is later
corrected. Whether the criteria *also* need effective-dated history on the HR
side is a separate question — §11, question 6.

### Exit criteria worth writing down

- **Stage 1:** the ERP holds the real tree and all 242 active + 223 terminated
  employees, HR has reviewed the import, and **the ERP is the identity source of
  truth**.
- **Stage 2:** documents and approvals run entirely on the ERP; the legacy
  document endpoints are dark.
- **Stage 3:** a full month of attendance runs on the ERP, closes cleanly, and
  reconciles against the legacy figure for the same month. Do not skip the
  parallel run.
- **Stage 4:** one month's payroll reconciles against the workbook line by line
  **before anyone is paid from it**.

---

## 9. Where we are, in detail

### Phase 1 — complete

The foundation: the org tree, employees, users, permission-based RBAC, the audit
log, and notifications. All 27 findings of the compliance audit are closed or
ratified. The remediation is the reason for several of the shapes described in
§5 — a stale two-tier enum, four roots, a missing `Gate::before`, and a duplicate
`users.name` were all fixed in that pass.

### Phase 1.5 — why it exists

After Phase 1 shipped, four real company artefacts were examined for the first
time:

| Artefact | What it is |
|---|---|
| `هيكلية شركة الراقي للانشاءات.pptx` | The official org chart, 15 slides |
| `Program_HR_2_2026.xlsx` | The live HR + payroll workbook, 16 sheets, 242 staff |
| `برنامج الموارد حسب المعايير شهر 9.xlsx` | The same workbook five months earlier |
| `موظف جديد.xlsx` | The new-hire intake form (XLSForm / Kobo) |

They showed the foundation was **structurally correct but factually short of the
real company**, in three ways:

1. The tree stopped at `قسم`. The company has a `مكتب` tier below it.
2. `employees` carried 9 business columns. HR tracks about 50, and three of them
   are classification axes that drive pay.
3. `employees.salary` was a single decimal. Salary here is **computed from
   criteria**, not stored as a number.

Phase 1.5 fixes all three **before the 242 real employees are imported** — the
cheapest moment such a fix is ever available, and a moment that passes exactly
once.

### The four batches

| Batch | Content | State |
|---|---|---|
| **1** | `Office` tier + `Sector` enum + `departments.sector` + resource/guard updates + the real tree seeded | **done, 2026-08-05/06** |
| **2** | `employees` restructure (§5) + the new tables (§6) + permission additions | **done, 2026-09-13** |
| **3** | Import tooling (§7) + the reviewable org-tree artefact | **not started — do this next** |
| **4** | HR review pass, corrections, the real import | not started |

Estimate for the whole of Phase 1.5 was 2–3 weeks, in four commits that each
leave the suite green.

### Six decisions already ratified by the project owner (2026-07-30)

These are binding — do not re-open them:

| # | Question | Answer |
|---|---|---|
| 1 | Is `الجهة` a tier or an attribute? | **Attribute** — a `sector` column |
| 2 | Is `اللقب` an official identifier? | **No** — full name only, first + middle + last |
| 3 | Is `مكان الراتب` a payroll grouping? | **No** — finance pays everyone. Column dropped |
| 4 | Currency? | **USD only** — no `currency` column anywhere |
| 5 | Migrate the Access DB (`HR-Raki.accdb`)? | **No** — records move manually later |
| 6 | Do `ورشة` / `فريق` / machine groupings belong in the tree? | **No** — they are job titles and asset categories |

Two more were answered on 2026-08-05: board members **are** `employees` rows; and
`إدارة التسويق وتقنية المعلومات` follows the chart, with a
`قسم تقنية المعلومات` under it.

Four more on **2026-09-13**:

| # | Question | Answer |
|---|---|---|
| 7 | Is a job title per-division or company-wide? | **One shared row.** `job_titles.name` is unique and `department_id` is dropped — `محاسب` is a single title with a single description however many divisions employ accountants. Was §11 question 3 |
| 8 | Should the ERP store الديانة، المذهب، السوابق الجنائية? | **No.** The intake form collects them; nothing here reads them, so they are never imported. Data that is not stored cannot leak |
| 9 | `employee_number` format | **Auto-generate when blank, allow a manual override** where it is unique. ~~`legacy_number` keeps the imported `M024333`~~ — ⚙️ **amended 2026-09-23, see below: that column is dropped.** Closes §11 question 1 |
| 10 | Scope of the HR build | **Batch 2 as ratified.** Payroll, attendance, leave and documents are *not* rebuilt — attendance, leave and documents already run in production in the legacy system and arrive by port (Stages 2–3); payroll is Stage 4 |

And one on **2026-09-13**: a job title is **one shared, company-wide row** —
`job_titles.name` is unique and the `department_id` column is dropped, so `محاسب`
is a single title with a single job description no matter how many divisions
employ accountants. This was open question 3 in §11. The reasoning, and the
`job_title_department` pivot that is the escape hatch if a division-filtered
title picker is ever needed, are in
[`phase-1.5-foundation-truth-up.md` §6.1](phase-1.5-foundation-truth-up.md).

And two on **2026-09-22**: **العهد المسلمة للموظف (assets) and السلف (advances)
belong to إدارة المالية, not to HR.** Both requirements appear on employee-app
screens, so both are recorded — but neither is built in the HR module. The
profile screen ships without the assets card, and no HR table holds an asset or
an advance ledger. See
[`EMPLOYEE_APP_REQUIREMENTS.md` §5.10](EMPLOYEE_APP_REQUIREMENTS.md) and
[§5.2](EMPLOYEE_APP_REQUIREMENTS.md).

⚠️ **One dependency survives the السلف decision.** The payslip in Stage 4 still
carries a السلف deduction line, so payroll reads a monthly installment figure
that a finance module owns. That seam needs a defined contract before Stage 4 is
specified — it is question 4 in §11.

And three more on **2026-09-22**, all from the employee-app requirements:
**every employee gets a login account**, **the login identifier is the employee
number** (`employees.employee_number`, not an email), and **the first password is
temporary and the API refuses everything until it is changed**.

⚙️ **The reason is the binding part, not the choice.** Attendance is recorded
through the company app, so an account is a **payroll input** rather than a
convenience: an employee without a working one cannot be marked present, and
Stage 4 cannot tell that silence from absence — it lands on the payslip as a
deduction against someone who came to work every day. Account coverage therefore
belongs in Stage 3's exit criteria beside the parallel run.

✅ ⚙️ **SHIPPED 2026-09-23.** `email` is nullable (contact, not identity),
`must_change_password` and `password_changed_at` exist, login takes a single
`login` field resolving an employee number through the already-unique
`users.employee_id` — no number copied onto `users` — and `POST /api/auth/password`
is the one route that clears the flag. `EnsurePasswordIsChanged` runs on the
whole `api` group, so a temporary password can reach only that route, `logout`
and `me`. Changing the password revokes every other session. 16 tests in
`tests/Feature/Auth/AccountModelTest.php`.

⚠️ **This is a breaking change for every client** — the login field was `email`.
`API_DOCUMENTATION.md` carries the notice at the top and in §3.1/§3.5.

What is **not** done: the five obligations this puts on the Batch 3 importer —
accounts for active employees only, termination disabling an account, the
credential artefact, the soft-delete re-run trap, and coverage as a Stage 3 exit
criterion. They are in
[`EMPLOYEE_APP_REQUIREMENTS.md` §7.1](EMPLOYEE_APP_REQUIREMENTS.md) and belong to
the importer, which does not exist yet.

And one on **2026-09-23**: **`employees.legacy_number` is dropped.** The company
keeps **one** staff number, not two. The column was added in Batch 2 to hold the
`M024333` series so paper records stayed findable; the project owner ruled it
unnecessary. Because the database holds no production data, the column was
removed from the Batch 2 migration **itself** rather than dropped by a second
migration — the same reasoning that put the whole employees rebuild before the
import.

It touched the migration, `Employee::$fillable`, `EmployeeRequest` (rule and
Arabic message), `EmployeeResource`, the free-text search, two tests, and eight
passages across four documents. **Nothing HR needs is lost** — a hand-typed
`employee_number` is always respected, so the `M024333` series can continue in
the one column that remains, and the search finds it there. ⚠️ **One thing did
break: the importer's idempotency key.** It was `legacy_number`. That is settled
below.

### ⚙️ Seven decisions taken 2026-09-23 — the blocking round

Taken together, deliberately: each one was holding up work, and several are
cheaper to answer as a set than one at a time.

| # | Question | Answer | What it unblocks |
|---|---|---|---|
| 11 | **The staff number after the import** | **New generated numbers** (`EMP-00001`). The `M024333` series is not carried in. The importer keys its re-runs on **a staging table mapping source row → employee**, not on a domain column | Batch 3. Consistent with decision 10 — no legacy identifier returns to `employees` by the back door |
| 12 | **`إدارة الإمداد والتوريد`** | **It exists. The seeded tree is correct** — `SUP`, a division, per the org chart | Batch 3. Closes §11 question 1 |
| 13 | **GAP-12 — writing a field you cannot read** | **من لا يرى لا يكتُب.** Writing `agreed_salary`, `sham_cash_account` or any of the nine classification axes requires `hr.employees.viewSalary`; writing `national_id` requires `hr.employees.viewIdentity` | The asymmetry the real import would have shipped with. No new permission needed |
| 14 | **GAP-3 — a manager's scope** | **The whole subtree.** A division head sees every section and office beneath them and all their staff — not just the rows whose `department_id` is their own unit | Row-level authorization, and لوحة المدير with it |
| 15 | **The escalation chain's operational rules** | ~~`direct_manager_id` if set, else~~ (amended 2026-09-24, decision 20) the manager of the employee's unit; **if the post is vacant or the requester is that manager, climb to the nearest ancestor that has one**; terminate at المدير العام. Manual reassignment stays available, as the legacy system already provides | The manager resolver — which the app home screen needs and the Stage 2 approvals engine needs. "Structure without a walk" ends here |
| 16 | **GAP-13 — the audit trail leaking the axes** | **`auth.audit.viewValues` implies `hr.employees.viewSalary`.** The two are never granted apart | The reversible option was taken on purpose: redaction would have destroyed "who changed this person's grade, and to what" permanently, on an append-only table |
| 17 | **Does an employee see their own classification axes?** | **Yes — in full**, including السوية التنظيمية، نوع النشاط، الخبرة and التمييز | `/api/me`. ⚠️ This raises the bar on the import: every axis must be correct and defensible **per person** before the app ships, because each employee will read their own |

⚠️ **A decision is not a fix.** Six of these need code that does not exist yet.
⚙️ **Updated 2026-09-23, later the same day:** decisions 13, 15 and 16 were
**built** — GAP-12 and GAP-13 are in the **Closed** register, and
`ManagementChain` walks the escalation chain. ⚙️ **2026-09-24:** decisions 14,
18 and 19 are built too — GAP-3 is closed. ⚙️ **2026-09-26:** so is **GAP-15**,
which it left behind — outside the employee routes a scoped grant acted
company-wide, and three permissions could widen their own scope. Decisions
21–23 below closed it. ⚙️ **2026-09-28:** **GAP-16** too — a scoped role
assigner was bounded in WHERE they granted, not in WHAT; decision 24 below
closed it.

### Two more on 2026-09-23 — how GAP-3 is actually built

Decision 14 settled the **extent** of a manager's scope (the whole subtree). It
did not say how the system knows a given user is limited to a branch, and that
turned out to be the harder half.

| # | Question | Answer |
|---|---|---|
| 18 | **Where does a user's scope come from?** | **A scope on the ROLE ASSIGNMENT**, which is one of: the whole company, a named unit, or **«الوحدات التي أديرها»** — derived from `departments.manager_id` |
| 19 | **An in-scope caller asks for an out-of-scope row directly** | **403 with a clear message**, not a 404 |

⚠️ **Why "derived from the tree alone" was rejected, and it is worth knowing
because it is the obvious idea:** it inverts the intent. A user who manages no
unit would have no scope and therefore see everything, while a division head
would see only their branch — so an ordinary clerk would end up with wider
access than the manager above them. Scope has to be something a grant SAYS, and
the tree is then one of the things it can say.

The «وحداتي» option is what keeps P1 intact: ~100 managers need no hand-assigned
scope, and `departments.manager_id` stays the single place recording who manages
what. A per-grant unit is still available for the cases the tree cannot express
— a departmental assistant who is not the manager.

⚙️ **Decision 19 accepts a known cost, knowingly.** A 403 confirms the row
exists, so someone walking ids can learn how many employees the company has and
which ids are real. A 404 would not reveal that. The clearer message for the
user and for support was judged worth it — this is an internal ERP behind
authentication, not a public API.

### ⚙️ One on 2026-09-24 — `direct_manager_id` is dropped

| # | Question | Answer |
|---|---|---|
| 20 | **Does an employee ever report outside their unit head?** | **No.** A manager is always the one the hierarchy gives. `employees.direct_manager_id` is removed, and the `direct_manager` API key with it |

The column was added in Batch 2 to record exactly that exception, and the API
carried it for eleven days beside the resolved `manager`. The project owner
ratified that the exception does not occur here — so it was a column for a case
that never arises, which is a second copy of what `departments.manager_id`
already says, waiting to disagree with it (P1).

**Nothing from the source data is lost.** The workbook's «المدير المباشر» is free
text that predates the employee table, and
`employee_assignments.legacy_manager_name` already keeps that raw string for a
human to read — which is where it was always going to land.

⚠️ **One obligation lands on the Batch 3 importer.** Where the source names a
manager who is NOT the one the tree resolves, the importer must **report** the
discrepancy rather than drop it silently — the same rule it already follows for
`#N/A` criteria and malformed Sham Cash numbers. A disagreement between the
workbook and the org chart is a fact about the company, and the import is the
last moment anyone will be looking at both.

What it touched: the Batch 2 migration (the column is simply not in it now), the
model's `directManager` and `directReports` relations, the request rule and its
two Arabic messages, two Resource keys, the service's eager-load list, rule 1 of
`ManagementChain`, and six tests — four of them deleted, because the thing they
covered no longer exists.

### ⚙️ Three on 2026-09-26 — what a scoped grant is worth (GAP-15)

| # | Question | Answer |
|---|---|---|
| 21 | **Which permissions does a grant narrower than the company confer?** | **Only those whose scope the server enforces.** Everything else counts only from a company-wide grant — an allow-list (`PermissionReach`), so a permission added later is company-only until something enforces its scope |
| 22 | **`auth.roles.update` guarded both assigning roles and editing role definitions — how are they separated?** | **A new permission, `auth.roles.assign`.** Assigning is reach-bounded and may be scoped; `auth.roles.update` edits what a role carries, which changes every holder company-wide, and is company-only. Every role holding `update` was given `assign` by migration. ⚠️ The reach bound covers where, not what (GAP-16) — scoping `assign` waited on that fix, and until it landed the server refused a non-company grant of a role carrying it (422). ⚙️ Fixed 2026-09-28 by decision 24; the 422 is gone |
| 23 | **What about reading the company catalogues from a scoped grant?** | **Honoured from any grant**: `departments.view`, `hr.job_titles.view` and `auth.roles.view`. The org chart, the job titles and the role list are company configuration with no personal data, and a scoped user's pickers need them. Their writes stay company-only |

Why it mattered: GAP-3 scoped the employee routes, but every other route checked
the permission by name, so a unit-scoped grant acted company-wide there — and
`departments.update`, `auth.users.*` and role editing change who manages which
unit, which account is which employee, and what a role carries: the inputs of
scope itself. A scoped grant could widen its own scope. Nothing was exploitable
yet (every grant was still company-wide), which is exactly why it had to close
before the rollout narrows any.

**What this leaves as follow-on work, not as a gap:** real subtree scoping for
department writes and for the audit list. Until it exists those permissions are
company-only — refused from a scoped grant, never quietly company-wide.

**⚠️ Deploy order.** Run `php artisan migrate` **before** the code takes
traffic: `2026_09_26_100000_split_auth_roles_assign_permission` creates
`auth.roles.assign` and gives it to every role holding `auth.roles.update`.
Code first means a 403 on assign/unassign for every role administrator short of
a super admin until the migration runs.

What the deploy changes, stated plainly: nobody loses the ability to ASSIGN,
but any existing grant narrower than the company stops conferring its
company-only permissions the moment the code is live — that is GAP-15's point.
Every grant is documented as company-wide today, so it should touch nobody; the
migration checks rather than trusts that:

- it **refuses to run**, before writing anything, if a role carrying
  `auth.roles.update` is granted over a unit or managed scope — the copy would
  turn it into a scoped `auth.roles.assign`, which GAP-16 forbids. It names the
  grants; narrow or revoke them and migrate again;
- it **reports** (log and STDERR) every scoped grant that stops conferring
  something, and what;
- rolling it back **refuses** while a role holds `assign` without `update`: the
  role would lose assigning, and handing it `update` instead would add role
  editing. Whoever rolls back chooses.

**And one gap found the same day: GAP-16** (⚙️ closed 2026-09-28, below). A
review of this work showed the reach check on `auth.roles.assign` bounds *where*
a grant applies, not *what* the role carries — a scoped assigner could hand
themselves any role inside their unit. Two smaller findings from the same review
were fixed at once: a managed grant to someone who manages nothing is refused to
a scoped assigner (it passed unchecked), and a scoped grant that would confer
nothing at all is a 422 rather than a 200.

### ⚙️ One on 2026-09-28 — what an administrator may hand out (GAP-16)

| # | Question | Answer |
|---|---|---|
| 24 | **What may a role administrator hand out, and to whom does that bound apply?** | **Only what they hold themselves, over the same units — for every administrator but a super admin, company-wide ones included.** Three rulings, taken together: (a) the **subset rule** binds company-wide assigners as well as scoped ones; (b) **`super_admin` is granted and revoked by a super admin only**; (c) **revoking is bounded exactly as granting is** — nobody takes away what they could not have given |

**What it means in practice.** A company-wide HR administrator who does not hold
`hr.employees.viewSalary` cannot hand out a payroll role — not to themselves,
not to anyone. Only a super admin can hand out any role at all. The cost,
accepted: a role carrying a permission no ordinary administrator holds has to be
granted by a super admin.

**It reached further than assigning, on purpose.** Adding a permission to a
role you already hold gives you that permission with no grant at all, so the
same bound applies to role **definitions**: creating, editing (every permission
added or removed) and deleting a role require each permission touched to be held
**company-wide** — a definition is a company-wide object, whoever holds it.

**Why (a) and (c) mattered.** Without (a), `auth.roles.assign` held company-wide
would still be every permission in the catalogue, one self-grant away. Without
(c), a lesser administrator could strip a senior one's access — not an
escalation, but the same failure of least privilege pointed the other way.

**What changed for the rollout.** The 422 that refused a scoped grant of a role
carrying `auth.roles.assign` existed only because this rule did not. It is gone:
a section's administrator may now hold `auth.roles.assign` over their section,
and hand out, there, only what they hold there. Built in
`RoleService::ensureMayGrant()` and `ensureHoldsCompanyWide()`; tested in
`RoleSubsetRuleTest`; contract in `API_DOCUMENTATION.md` §5.2–§5.6.

### ⚙️ One on 2026-10-05 — the Employee App moves onto the ERP

**25. The Flutter app is kept and re-pointed at the ERP, the approvals engine is
built with leave as its first consumer, and v1 ships as a pilot.** The pilot
targets 2026-11-01 with legacy still running and still the record of pay; legacy
retires only after the Stage 3 parallel month. This reverses the merge plan's
documents-before-leave order without giving up its reason — one approval engine,
built once. Scope, the thirteen open decisions and the calendar are in
[`EMPLOYEE_APP_V1_WORK_ORDER.md`](EMPLOYEE_APP_V1_WORK_ORDER.md); the table-by-table
port is [`LEGACY_TO_ERP_MAPPING.md`](LEGACY_TO_ERP_MAPPING.md).

---

## 10. What to do next — Batch 3, the import tooling

⚙️ **Batch 2 is done (2026-09-13).** Jump to
[Batch 3](#batch-3--import-tooling) for the next work. The rest of this section
is the record of what Batch 2 built and why — it is the reference for anyone
touching `employees` now, and it is written in the imperative because that is how
it was specified.

> ⚙️ **Two things are in flight ahead of Batch 3 (2026-09-24), and both are
> written down rather than remembered:**
>
> 1. ~~**GAP-3, row-level authorization** — decided, not built.~~ ✅ **Built
>    2026-09-24** for employees, per
>    [`GAP3_SCOPED_AUTHORIZATION_WORK_ORDER.md`](GAP3_SCOPED_AUTHORIZATION_WORK_ORDER.md)
>    (its §8 records what was decided while building). ⚙️ **GAP-15**, which it
>    left behind, was registered and closed on 2026-09-26 (§9, decisions
>    21–23). Narrowed grants can roll out — ~~except `auth.roles.assign`,
>    which stays company-wide until GAP-16 is fixed~~ — ⚙️ `auth.roles.assign`
>    included since **GAP-16** closed on 2026-09-28 (decision 24).
> 2. **Batch 3 itself gained a prerequisite and an obligation** — the `users`
>    migration must land first (§9, the account model), and the importer now owes
>    a report wherever the source names a manager the org tree disagrees with
>    (§9, decision 20).
> 3. ⚙️ **And a second prerequisite (2026-09-28): GAP-19** — ✅ **closed the
>    same day.** Correcting an employee's `hire_date` did not move their opening
>    posting, and the importer re-runs against HR corrections. It moves now, in
>    the model; see "Two things the importer gets for free" below.

### Why it had to happen before the import

The database had **no production data**. Changing `employees` then cost a
migration and some test updates; after the import it would have cost a data
migration against 465 real rows on a system HR depends on. That window closes
exactly once, and Batch 2 went through it.

### What Batch 2 did, in the order it was done

**Step 1 — split the name.** `employees.name` becomes `first_name`,
`middle_name`, `last_name`, with a `full_name` accessor that joins them. This is
the one breaking change, so do it first and alone.
*Blast radius:* `EmployeeResource`, `EmployeeRequest`, `Employee::$fillable`, the
seeders, and `tests/Feature/HR/EmployeeTest.php`. All in the same commit.
*Why not keep both:* storing parts **and** a full string is two columns for one
fact (P1). The workbook proves the failure mode — it carries `الإسم الثلاثي`,
`الاسم القديم`, *and* a `مقارنة الأسماء` column whose only job is to flag where
the two disagree. That third column is a bug report about the data model.

**Step 2 — identity and education columns.** `gender`, `birth_date`,
`birth_place`, `governorate`, `marital_status`, `wives_count`, `children_count`;
then `education_level`, `qualification`, `specialization`, `is_graduated`,
`graduation_date`.
*Trap:* `education_level` is an **ordered scale that carries a salary value**.
Back the enum by int and keep the cases in ascending order — the order is
load-bearing.

**Step 3 — the classification axes.** ⚠️ **The most important correctness note in
the whole project:**

> `السوية التنظيمية` (`organizational_grade`) is a **position grade**.
> `departments.level` is a **unit tier**. Both are integers 1–5. They are **not
> the same fact** and must never be conflated — a section head (`السوية 3`) can
> sit in a division (`level 2`) row. Conflating them silently corrupts every
> payslip.

Columns: `activity_type` (engineering / financial / administrative / industrial /
security), `organizational_grade`, `experience_level`, `engineering_rank`,
`industrial_experience_years`, plus the four flags `has_hazard_pay`,
`has_scarcity_pay`, `has_dual_role_pay`, `distinction_grade`.

The three experience axes are **mutually exclusive by `activity_type`**. Enforce
that in the model's `saving` hook, not only in the FormRequest — that is P2, the
lesson Phase 1 names as load-bearing.

Do **not** store `سنوات القدم` (seniority years). It is `hire_date` plus the
payroll period. Storing it would need a nightly job to keep 242 rows honest, and
the day that job fails, every payslip is wrong.

**Step 4 — employment and pay reference.** ~~`legacy_number`~~ (⚙️ **dropped
2026-09-23** — see §9; the column is not in the migration any more),
`termination_date`, `workplace`, ~~`direct_manager_id`~~ (⚙️ **dropped
2026-09-24** — see §9, decision 20),
`sham_cash_account` (exactly 16 digits), `salary_mode` (scale / agreed),
`agreed_salary` (required when `agreed`, NULL otherwise).

**Drop `employees.salary`.** For the majority — `salary_mode = scale` — the
number is not a stored fact, it is the output of the criteria calculation.
Caching a derived value with no invalidation strategy means that changing the
company scale factor silently makes all 242 rows wrong. The rate table and the
calculator belong to Stage 4.

⚙️ **This is where `direct_manager_id` was specified, and it is now gone
(2026-09-24).** The paragraph used to read: *"NULL for almost everyone, and NULL
means resolve from the org tree; the column exists only to record the exception —
someone who reports outside their unit head."* The exception turned out not to
exist in this company, so the tree is the only source and
`App\Shared\Services\ManagementChain` is what reads it. Kept as a record because
the reasoning for adding the column was sound — it was the premise that was
wrong, and that is worth telling apart.

**Step 5 — the four new tables.**

- `employee_assignments` — posting history. Replaces the workbook's three frozen
  `بداية التعيين` columns. First row is the initial posting; `to_date IS NULL`
  means current. `employees.department_id` stays as the denormalised *current*
  pointer because it is on every hot query path — keep the two in step in **one**
  `saving` hook so no writer can desynchronise them.
- `job_titles` — the governed catalogue (197 rows in the intake form, 691 in the
  workbook sheet, with a `تم التوصيف` flag). `employees.job_title` (string)
  becomes `employees.job_title_id` (FK). **`name` is unique and there is no
  `department_id`** — ratified 2026-09-13, see §9. The two source lists are form
  rows, not distinct titles; the importer dedups by name and reports any name
  whose duplicates disagree on `تم التوصيف` or suggested grade. Validate `name`
  against trashed rows too — the table soft-deletes, so the unique index counts
  them, exactly as `users.email` does.
- `employee_documents` — the four images the intake form collects per hire.
- `employee_warnings` — the workbook stores warnings as three columns, so an
  employee can hold exactly one warning forever. Make it rows.

**Step 6 — gate salary access.** Add the permission
**`hr.employees.viewSalary`**. `EmployeeResource` omits `agreed_salary`,
`sham_cash_account` **and every classification axis** unless the requester holds
it. The axes are gated too, on purpose: knowing someone's grade and activity type
is knowing their salary to within a rounding error, so gating the amount while
exposing its inputs would be theatre.

**Step 7 — update `API_DOCUMENTATION.md`** for the new employee response shape,
and note the `viewSalary` permission. The frontend projects read that file.

### Reference data stays as PHP enums, not tables

`activity_type`, `education_level`, `engineering_rank`, `marital_status`,
`experience_level` are enums. This looks like a P4 violation and is not: the sets
are stable (unchanged between the Sept 2025 and Feb 2026 workbooks). What changes
is the **monetary value** attached to each case, and those values live in Stage
4's versioned rate table keyed by enum case and effective date. So a pay-scale
revision is a data change, while the criterion identity stays a compile-time
constant the calculator can `match` on exhaustively.

### Batch 3 — import tooling

⚙️ **Since 2026-10-05 Batch 3 is the first half of a larger plan** — the Employee
App v1 pilot (decision 25). Build order and calendar are in
[`EMPLOYEE_APP_V1_WORK_ORDER.md`](EMPLOYEE_APP_V1_WORK_ORDER.md) §4–§5; what
follows here is still the specification of the importer itself. ⚙️ **One
correction to the paragraph below:** the `users` migration it asks for first
**is done** — it shipped on 2026-09-23 with the account model (`76f533c`).

**Everything the importer writes into now exists**, which is the point of having
done Batch 2 first: `employees` in its real shape, the `job_titles` catalogue to
dedup titles into, `employee_assignments` to carry the opening posting, and
`employee_documents` to attach the intake form's four images per hire.

⚙️ **One exception, added 2026-09-22: `users` does not yet exist in the shape
Batch 3 needs.** The account decisions in §9 mean the import also creates a login
for every active employee, and `users.email` is still `unique NOT NULL` while the
identifier is now the employee number. **Do the `users` migration first, as its
own step, before the importer is written** — it is the same reasoning that put
Batch 2 ahead of Batch 3, one tier down. The migration and the five rules it
forces on the importer are in
[`EMPLOYEE_APP_REQUIREMENTS.md` §7.1](EMPLOYEE_APP_REQUIREMENTS.md).

Two things the importer gets for free and should not re-implement:

- **The opening posting writes itself.** Creating an employee with a
  `department_id` opens a `تعيين` assignment dated from their `hire_date`, via a
  model hook. Do not create it separately — a second open posting is refused.
  ⚙️ **And it follows corrections (GAP-19, 2026-09-28):** a re-run that fixes a
  `hire_date` moves that posting with it, and a correction the history cannot
  follow (past a later transfer) raises `AssignmentConflictException` — report
  it like any other refused row. Updating an existing employee that changes no
  unit, title or grade writes no posting (a create-then-update used to write a
  spurious one; fixed the same day).
- **Every invariant is enforced at the model layer**, not just over HTTP, so a
  bad row raises `EmployeeClassificationException` with an Arabic message naming
  the field. That is the hook to catch and turn into the import report.

**Sources:** the Excel workbook and the org chart.
**Not** the Access DB. Where sources disagree, **the chart wins** for tiers 1–3;
the intake form is used **only** for the office tier, because the chart draws
offices inconsistently across slides.

Of the 114 office rows in the intake form, a large minority are not
organizational units and must be dropped: self-referential placeholders (a row
whose label equals its parent's — the XLSForm idiom for "no offices here"),
machine and vehicle groupings under `قسم السائقين` (باكر، بلدوزر، رافعة…), and
workshops and engineering teams. Expect roughly **70–80 survivors**.
The filtered list **requires a human review pass with HR before it is
committed** — the script must emit a reviewable artefact, not write the tree
directly.

The employee importer must **report** rather than silently absorb the known data
problems: `#N/A` in the criteria columns of the `إنهاء عمل` sheet, employees with
a blank `أسماء الإدارات`, and `رقم الشام كاش` values that are not 16 digits.

✅ ⚙️ **The importer key, settled 2026-09-23** (decision 11 in §9). The key is not
decoration: the importer runs **several times against HR review feedback** before
the real cut, and without one, run two duplicates run one. `legacy_number` was
that key until the column was dropped.

**The replacement is a staging table owned by the importer**, mapping *source row
→ employee id*, and **not** a column on `employees`. Everyone gets a newly
generated `EMP-00001`; the `M024333` series is not carried in. Three properties
this buys, which are the reason it was chosen over reusing `employee_number` or
`national_id`:

- **No legacy identifier re-enters the domain schema.** That is the whole point
  of decision 10, kept rather than undone one batch later.
- **Coverage is total.** `national_id` is nullable and not certainly present on
  all 465 rows, and a row without one would duplicate on every re-run — which is
  precisely the failure the key exists to prevent.
- **The mapping is reviewable.** It is a table HR can be shown: this sheet row
  became this person. A domain column would have buried it in the record.

What it costs: the staging table and its own tests, plus HR learning new numbers
for everyone.

**Batch 4 — the real import.** 242 active rows plus 223 from `إنهاء عمل` with
`status = terminated`, each with an opening `employee_assignments` row.

---

## 11. Open questions waiting for an answer

### For HR / the project owner

Questions 1–3 were **closed on 2026-09-13** and are recorded as ratified
decisions 7–10 in §9. What is left:

1. ✅ **Closed. Both source discrepancies are resolved, and this entry was stale
   in both halves.** `إدارة التسويق` vs `إدارة التسويق وتقنية المعلومات` was
   answered on **2026-08-05** (§9) and this question was never updated;
   `إدارة الإمداد والتوريد` was confirmed on **2026-09-23** as a real division
   (decision 12). `DepartmentSeeder` already seeds both exactly as confirmed —
   `MKT` with `MKT-IT` under it, and `SUP` — so no code changes. **Batch 3 is no
   longer blocked by the tree.**

2. ⚙️ **Does an engineering employee's pay read the numeric experience table, the
   engineering-rank table, or both added together?** New, and found while
   building Batch 2. `phase-1.5-foundation-truth-up.md` §5.3 says the three
   experience axes are mutually exclusive by activity type, which reads as
   forbidding `experience_level` on engineering staff. The company's own pay
   matrix contradicts that: it carries **two** experience rate tables, one keyed
   to `إداري/مالي/أمني` (خبرة 1–5) and one to `هندسي/صناعي` (1–5), so an
   engineer holds an experience level **and** a rank.

   The schema stores every input either way, and
   `EmployeeClassificationGuard` enforces only what both sources agree on — a
   rank belongs to engineering work, industrial years to industrial work —
   because the stricter reading would have rejected real rows at import time.
   **This does not block Batch 3. It blocks the payroll calculator (Stage 4).**

3. ⚙️ **What scale does `distinction_grade` (التمييز بحسب التقييم) use?** The
   Phase 1.5 spec types it as a tinyint without stating a range; the later HR
   scan models it as a monetary amount instead. It is stored here as a grade and
   validated only against the column's own range, because inventing a bound
   would be guessing. The performance-review module that sets it will need the
   real answer.

4. ⚙️ **Six questions are left of the eight that arrived with the employee-app
   requirements** (2026-09-21) — the seam between payroll and the finance السلف
   ledger, whether "قرأت ووافقت" is a signature or a receipt, the evaluation
   scale, whether there is a company directory, who maintains the عطل رسمية
   calendar, and what happens to the legacy accounts. **Two were answered on
   2026-09-23** and are decisions 14 and 17 in §9: a manager's scope, and whether
   an employee sees their own classification axes.
   Each of the rest is listed with its consequence in
   [`EMPLOYEE_APP_REQUIREMENTS.md` §8](EMPLOYEE_APP_REQUIREMENTS.md); §7.2 of
   that file holds the three left that are answerable from the workbook alone.
   **The login questions are no longer among them** — they were ratified on
   2026-09-22, see §9.

5. ✅ **Closed the day it opened (2026-09-23).** The importer key, orphaned by
   dropping `legacy_number`, is a staging table owned by the importer — decision
   11 in §9, with the reasoning in §10.

6. ⚙️ **Are changes to an employee's pay criteria ever back-dated or prorated?**
   New (2026-10-05). For example, a promotion in experience level "effective last
   month, pay the difference", or a flag such as الخطورة switched on mid-month and
   paid for part of it. The grade already has dated history
   (`employee_assignments`); the other criteria — `experience_level`,
   `engineering_rank`, `industrial_experience_years`, the three flags — hold only
   their current value. `distinction_grade` is left out: its history will be the
   performance-review records that set it (question 3).

   - **If no** — a change applies from the next payroll run — the payslip
     snapshot in §8 is sufficient and the schema stays as it is.
   - **If yes** — the calculator needs the value in force on each date, which
     means an append-only, effective-dated history for those criteria, in the
     same pattern as `employee_assignments`.

   Either answer is cheap after the Batch 4 import — a history table can be
   backfilled with each employee's current values dated at go-live — so **this
   does not block Batch 4. It blocks the payroll calculator (Stage 4).**

### For the merge

1. **Cutover windows and sign-off** — who authorises each stage, and what notice
   does the company get? Stage 3 needs a named date and a message to every
   employee with the app.
2. **FCM project** — reuse the legacy Firebase project and its registered device
   tokens, or issue a new one? Reuse is the lower-risk default.
3. ✅ **Legacy audit trail — not migrated** (2026-10-16): no data moves from
   legacy at all; it stays in the archived snapshot.
4. **Document numbering continuity** — continue the sequence unbroken across the
   cutover, or restart? Almost certainly continue; confirm with
   `إدارة الديوان والأرشيف`.
5. **Grace-period length** for legacy-format offline attendance submissions.

### Technical, undecided

- **Notification transport** — closes in Stage 3 by adopting the legacy FCM layer.
- **Must a manager belong to the unit they manage?** Unratified and
  unimplemented; `manager_id` currently accepts any existing employee.
- ✅ ~~**The escalation chain's operational rules**~~ — **ratified and built
  2026-09-23** (decision 15 in §9): climb to the nearest ancestor with a manager
  when the post is vacant or the requester is that manager, terminate at المدير
  العام, keep manual reassignment. `ManagementChain` implements it; eleven tests
  in `tests/Feature/HR/EmployeeManagerTest.php`, one per rule.
- **PHP 8.3 bump** — `composer.json` still pins `^8.2`.

---

## 12. Known limitations

Stated honestly, so none of these is mistaken for a bug.

- **The tree grows downward freely, not upward.** Appending a tier below is one
  enum line. Inserting a tier *above* the root renumbers every existing row. This
  is an accepted property of depth-based `level`, and it is exactly why the board
  compromise in §5.5 was taken rather than going five tiers deep.
- ✅ ~~**The escalation chain is structure without a walk.**~~ **Closed
  2026-09-23.** `App\Shared\Services\ManagementChain` walks it by the rules
  ratified that day (decision 15 in §9), and `EmployeeResource` now carries a
  resolved `manager` — the only manager there is, since `direct_manager_id` was
  dropped on 2026-09-24. What remains unbuilt is
  the *consumer* — the Stage 2 approvals engine that will climb the same chain,
  and the manual reassignment that sits on top of it.
- **The المدير العام has no one above them.** By construction the chain
  terminates there. Intended, not a gap.
- ✅ ~~**Pay-field WRITE access is ungoverned.**~~ **Closed 2026-09-23.** A write
  to `agreed_salary`, `sham_cash_account` or any classification axis now requires
  `hr.employees.viewSalary`, and `national_id` requires
  `hr.employees.viewIdentity` — من لا يرى لا يكتُب, decision 13 in §9. The field
  must be **absent**, not merely empty, so a `null` cannot clear a salary its
  sender is not allowed to read. A test derives the read-gated set from
  `EmployeeResource` and asserts it matches the write-gated list, so the two
  cannot drift apart again. Was GAP-12.
- ⚙️ **A direct write to `employees.department_id` rewrites the open posting
  rather than appending to history.** The model hook keeps the pointer and the
  open assignment in step on every path, so they can never disagree — but it can
  only infer "something changed" and records `نقل داخلي` dated today. A transfer
  with a real reason or a real effective date must go through
  `POST /api/hr/employees/{employee}/assignments`. This is a deliberate split,
  not a gap: the alternative is smuggling history fields onto a person's
  profile.
- ⚙️ **`employee_documents` has a table and no endpoints.** The importer will
  write it; upload and download arrive with the Documents module in Stage 2.
  Whoever builds them owes the `ShamCash` type the `viewSalary` gate — a photo
  of a payment account is the account number.
- **Concurrency guarantees are real only on MySQL with a shared lock and cache
  store.** The root-creation lock, the parent row locks and the permission cache
  are no-ops or process-local under the SQLite test driver — correct by
  construction there, genuinely enforced in production.
- **No frontend in this repo.** The absence is clean: the API already ships
  `level_label` so a client never re-derives tier names.
- **Doc drift worth knowing:** `DatabaseSeeder`'s docblock says the tree is 118
  rows; the seeder and its test say 119. The test is right.

---

## 13. Conventions — how code is written here

> This section is the summary. **`API_BUILD_STANDARD.md` is the full standard** —
> the numbered constraints, the per-layer contracts, the twelve-step build
> workflow, a worked example, the PR review checklist, and a register of where
> the code does not yet comply. Read it before building a feature.

**Adding a module**
1. `app/Modules/{Name}/` with `Controllers/ Services/ Models/ Requests/ Resources/`
2. a `routes.php` inside it — that alone activates it
3. `database/migrations/{Name}/` for its tables
4. seed its permissions as `{module}.{resource}.{action}` in `PermissionSeeder`
5. guard every route with the `permission:` middleware — reads need `.view`,
   writes need their own `create` / `update` / `delete`, so a read-only role
   cannot mutate anything

**Writing the code**
- Controllers stay thin. Business logic goes in a Service.
- **Invariants belong in the model hook**, where every writer crosses — not only
  in the FormRequest. If you can enforce it in exactly one place, enforce it there.
- Domain models get `HasAuditLog`.
- API and validation messages are Arabic.
- Enums are int-backed lenses over a column, never a second copy of the fact.
- Any table a later module might FK into gets soft deletes.
- Run `./vendor/bin/pint` before committing. The config deliberately preserves
  the aligned `=>` style — do not let a formatter undo it.

**Tests**
- Feature tests per module under `tests/Feature/{Module}/`.
- Every batch of work should leave the suite green; that was the explicit
  standard for all four Phase 1.5 batches.
- Seeder counts are asserted **exactly** (`DepartmentSeederTest`), so a silent
  change to the real tree fails the build. Keep it that way.

**Git**
- Work happens on `phase_1`; `main` is the default branch.
- Commit messages here are descriptive sentences, not conventional-commit
  prefixes — e.g. *"Seed the actual company tree; split invented fixtures out of
  it"*.

---

## 14. Arabic to English glossary

The domain is Arabic and the code is English. This is the mapping used throughout.

| Arabic | Code / English | Notes |
|---|---|---|
| الإدارة العامة | general administration | level 1, the single root |
| إدارة | division | level 2 — the tier that declares `sector` |
| قسم | section | level 3 |
| مكتب | office | level 4, added in Batch 1 |
| الجهة | `sector` | an attribute, not a tier |
| المكاتب الإدارية / الأجسام الإدارية | `Sector::AdministrativeOffices` / `AdministrativeBodies` | the two cases |
| مجلس الإدارة | the board | a container only; nothing escalates to it |
| المدير العام | the general manager | the approval chain's terminus |
| السوية التنظيمية | `organizational_grade` | **position** grade 1–5 — never `departments.level` |
| نوع النشاط | `activity_type` | selects which experience axis applies |
| الخبرة | `experience_level` | the administrative / financial / security axis |
| المؤهل العلمي | `education_level` | an ordered scale that carries a salary value |
| سنوات القدم | seniority | **never stored** — derived from `hire_date` |
| الخطورة / الندرة / إزدواجية العمل | `has_hazard_pay` / `has_scarcity_pay` / `has_dual_role_pay` | pay flags |
| التمييز بحسب التقييم | `distinction_grade` | set by performance review |
| نظام رواتب / إتفاق | `salary_mode = scale` / `agreed` | the two pay tracks |
| النسبة المئوية للقيمة | the company scale factor | currently `0.75`; belongs in the rate table |
| الرقم الوظيفي | `employee_number` | ⚙️ **Amended 2026-09-23.** Mapped to `legacy_number` until that column was dropped; the `M024333` values now go in the one staff-number column, or nowhere |
| رقم الشام كاش | `sham_cash_account` | exactly 16 digits |
| ~~متعاقد / إنهاء عمل~~ | ~~`status = active` / `terminated`~~ | ⚙️ **Superseded 2026-09-13 — this mapping read two facts as one.** See the two rows below |
| نشط / موقوف / منتهي الخدمة | `EmployeeStatus` | *Is this person employed?* موقوف replaces the vague `inactive` |
| متعاقد / يحتاج تثبيت / جماعة / مثبت | `EmploymentType` | *On what footing?* A separate column. The workbook's `الحالة` held both questions, and the old glossary row above silently picked one |
| الاسم الثلاثي | `first_name` + `middle_name` + `last_name` | `full_name` joins them; never a column |
| اسم الأب / الكنية / اسم الأم | `middle_name` / `last_name` / `mother_name` | the middle name IS the father's name, and is searched as such |
| سجل التعيين | `employee_assignments` | posting history; `to_date IS NULL` = current |
| تعيين / نقل داخلي / ترقية | `AssignmentReason` | why a posting row exists |
| المسمى الوظيفي | `job_titles.name` | one shared, company-wide row |
| الإنذار | `employee_warnings` | rows, not three columns; `sequence` is derived |
| المرحلة الدراسية | `EducationLevel` | 13 rungs, ordered by pay value — **not** by the reference schema's `rank_order` |
| المحافظة | `Governorate` | an enum of the 14, killing the حماة/حماه drift |
| الخدمة العسكرية | `did_military_service` | routine HR data here |
| تم التوصيف | `job_titles.is_described` | output of قسم التحليل والتوصيف |
| قاعدة البيانات | the workbook's main sheet | 242 employees |
| دليل الهيكل والمسميات الوظيفية | the structure and job-titles sheet | 691 rows; the tree's source of record |

---

*Written 2026-09-07 after a two-month gap. If you are reading this after another
long gap: §2 tells you where you are, §10 tells you what to do, and §4 tells you
which other document to open.*
