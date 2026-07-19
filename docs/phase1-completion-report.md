# Al‑Raqi ERP — Phase 1 Completion Report

**Status:** Phase 1 complete. All 27 findings of the compliance audit are closed or ratified.
**Branch:** `phase_1`
**Scope of this document:** the foundation layer — the organizational tree, people, identity/access (RBAC), and the cross‑cutting infrastructure (audit, notifications) that every later module builds on.
**Companion documents:** [`docs/erp-phase1-architecture.md`](erp-phase1-architecture.md) (the ratified ADR — currently only §7.2), [`docs/phase1-audit-report.md`](phase1-audit-report.md) (the compliance audit of commit `e0dfece`), [`docs/PROJECT_ARCHITECTURE_REPORT.md`](PROJECT_ARCHITECTURE_REPORT.md) (the "architecture report §16.x" the code docblocks cite).

---

## How to read this document

This is written **for a developer** — the person who wrote it months from now, and whoever inherits the code. It assumes you know Laravel. It is not an executive summary; it goes for depth, and above all it explains **why** each non‑obvious decision was made, because the *why* does not live in the code — the code can only show you *what* is.

Read it in order the first time. §1–§2 set up the frame (what Phase 1 is, and the four principles almost every decision reduces to). §3 walks the schema table by table. §4 traces the behaviours that span tables. §5 is the consolidated decision index — the fastest reference once you know the shape. §6 is the honest list of what Phase 1 does *not* do. §7 is where it sits in the roadmap.

**On sourcing.** Two things are worth knowing up front, because they shaped how this report was written and verified:

1. **The richest, most current record of *why* is the code's own docblocks.** They are unusually thorough and were kept current through the remediation. This report treats them as a primary source and cites them like any other.
2. **The original full architecture spec was never committed.** The audit (`phase1-audit-report.md`) cites a spec — `erp-phase1-architecture.md` §2/§3/§6/§10/§11/§12/§L — that does not exist in the repo in that form. The file that now carries that name holds only the **ratified §7.2 (notifications)**. The rest of the spec's rationale survives only as *paraphrase inside the audit's findings*. Where this report attributes a principle to "§2" or a prohibition to "§11", understand that it is drawing on the audit's characterization of a document that isn't itself in the tree, corroborated by the code. This is called out again in §6.

Every non‑trivial claim below cites a file (and line where useful) so you can jump from the claim to its source. File paths are repo‑root‑relative, matching the audit report's convention.

---

## Table of contents

1. [Purpose and scope of Phase 1](#1-purpose-and-scope-of-phase-1)
2. [Guiding principles](#2-guiding-principles)
3. [The data model — table by table](#3-the-data-model--table-by-table)
   - [3.1 `departments`](#31-departments)
   - [3.2 `employees`](#32-employees)
   - [3.3 `users`](#33-users)
   - [3.4 `roles`, `permissions`, and the two pivots](#34-roles-permissions-and-the-two-pivots)
   - [3.5 `audit_logs`](#35-audit_logs)
   - [3.6 `notifications`](#36-notifications)
   - [3.7 Supporting tables](#37-supporting-tables)
4. [System workflows](#4-system-workflows)
   - [4.1 Building the org tree](#41-building-the-org-tree)
   - [4.2 The guard architecture](#42-the-guard-architecture)
   - [4.3 The escalation / approval chain](#43-the-escalation--approval-chain)
   - [4.4 Permission resolution](#44-permission-resolution)
   - [4.5 Auditing](#45-auditing)
   - [4.6 Notifications](#46-notifications)
   - [4.7 Deletion semantics](#47-deletion-semantics)
5. [Decisions and their rationale — consolidated](#5-decisions-and-their-rationale--consolidated)
6. [Known limitations and deferred items](#6-known-limitations-and-deferred-items)
7. [What comes next](#7-what-comes-next)

---

## 1. Purpose and scope of Phase 1

Phase 1 is the **foundation layer** of the Al‑Raqi ERP. It builds four things and the plumbing that ties them together:

- **The organizational structure** — a single self‑referencing `departments` tree that models the whole company from the general administration down to individual sections ([app/Modules/Departments](../app/Modules/Departments)).
- **People** — the `employees` table, the HR profile of every person, which is the single source of truth for a person's identity ([app/Modules/HR/Models/Employee.php](../app/Modules/HR/Models/Employee.php)).
- **Identity and access** — `users` (login accounts) plus a hand‑rolled RBAC (`roles`, `permissions`, and their pivots), including a super‑admin bypass ([app/Modules/Auth](../app/Modules/Auth)).
- **Cross‑cutting infrastructure** — an append‑only audit trail and a custom notifications system ([app/Shared](../app/Shared), [app/Modules/Auth/Services/NotificationService.php](../app/Modules/Auth/Services/NotificationService.php)).

The whole thing is a **Laravel 12 modular monolith, backend API only.** Code is organized under `app/Modules/{Auth,HR,Departments}/` with a per‑module `routes.php` auto‑discovered by [ModuleServiceProvider](../app/Providers/ModuleServiceProvider.php); migrations live in `database/migrations/{Module}/`. There is no frontend (see §6).

### Why the foundation comes first

Every later module **foreign‑keys into these tables.** Projects will reference employees (who is assigned), organizational units (which department owns the work), and permissions (who may act). Payroll and attendance will reconcile against `employee_number`. That is precisely why the mistakes you can make here are *structural, not cosmetic*: a wrong tier model, a hard‑deletable employee, or a permission cache that goes stale is not a bug in one screen — it is a wrong assumption baked into the shape of every table that later points at it, and undoing it means a data migration once real rows exist.

The audit's top‑three findings all had this character (a stale two‑tier enum whose cost "gets more expensive every day real data exists"; a forest with no root for the approval chain to terminate at; a super‑admin pattern that silently locks admin out as modules are added — [phase1-audit-report.md §2](phase1-audit-report.md)). The remediation batches closed all of them **before any production data existed**, which is the cheapest moment such a fix is ever available. Getting the foundation right first is the entire point of sequencing the work this way.

### What is explicitly *not* in Phase 1

- **No business modules.** No Projects, Finance/payroll, attendance, or inventory. Projects is next (§7).
- **No frontend.** React (Vite, plain JS, Arabic RTL) was in the original stack description but is not built — `package.json` has no `react`/`react-dom`, and `resources/js/` holds only the stock scaffolding ([phase1-audit-report.md §L](phase1-audit-report.md)). This is deliberate: the backend deliberately keeps the tier vocabulary server‑side (`level_label`, see §2/§3.1) so that when a client is built it cannot re‑introduce the drift the single‑source‑of‑truth model exists to prevent.
- **No notification transport.** The notifications system decides *what is stored and for whom*; how a stored row reaches a device (FCM, mail, websockets) is a separate, later decision ([erp-phase1-architecture.md §7.2](erp-phase1-architecture.md)).
- **No multi‑tenancy.** `tenant_id` is noted as a future hook in several docblocks ([User.php](../app/Modules/Auth/Models/User.php), [create_audit_logs_table.php](../database/migrations/Shared/2026_05_13_125646_create_audit_logs_table.php)) but is not implemented.
- **PHP 8.3** is not yet the target — `composer.json` pins `^8.2` ([composer.json:9](../composer.json#L9)).

---

## 2. Guiding principles

Almost every individual decision in Phase 1 is one of four principles applied. State them once; the rest of the report keeps pointing back here. (The audit names the first two explicitly as "§2 principle #1/#2" of the original spec; all four are visibly the drivers of the code and are corroborated by the docblocks cited below.)

**P1 — One fact, one place (single source of truth).** A fact is stored exactly once; everything else is a lens over it or a pointer to it. Two columns describing one fact drift apart the moment someone writes one without the other. Seen in: `users.name` **dropped** — a person's name lives on their `employees` row and is resolved through `users.employee_id` ([create_users_table.php:16‑21](../database/migrations/Auth/2026_05_13_122954_create_users_table.php#L16-L21)); `departments.level` is the tier, and the `DepartmentLevel` enum is "a lens over that int, not a second copy of the fact" ([DepartmentLevel.php:14‑25](../app/Modules/Departments/Enums/DepartmentLevel.php#L14-L25)); manager status is a pointer (`departments.manager_id`), never a duplicated `employees.is_manager` flag ([Department.php:60‑62](../app/Modules/Departments/Models/Department.php#L60-L62)); "may this person use the system" is one flag, `users.is_active`, not employment `status` ([NotificationService.php:36‑42](../app/Modules/Auth/Services/NotificationService.php#L36-L42)).

**P2 — Structure over discipline (make invalid states impossible, at the layer every writer crosses).** Do not rely on people remembering to call the right validation; enforce the invariant where no writer can go around it. This is the single most important idea in Phase 1. Seen in: the hierarchy invariants live in the model's `saving` hook — the one layer the controller, service, seeder, tinker, and future jobs all cross — not only in the form request ([Department.php:96‑139](../app/Modules/Departments/Models/Department.php#L96-L139)); `employee_number` is auto‑generated in a model `creating` hook so *every* write path gets one ([Employee.php:72‑100](../app/Modules/HR/Models/Employee.php#L72-L100)); nullable‑unique indexes make "two logins for one employee" and "two units with the same code" impossible in the schema ([add_employee_id_to_users_table.php:34‑39](../database/migrations/Auth/2026_06_21_130000_add_employee_id_to_users_table.php#L34-L39)); composite pivot primary keys make a duplicate grant impossible ([create_role_permissions_table.php:25](../database/migrations/Auth/2026_06_21_142000_create_role_permissions_table.php#L25)).

**P3 — Explicit over implicit.** A forgotten value should fail loudly, not resolve to a wrong‑but‑plausible default; and a fact should be read from the column that means it, not inferred. Seen in: `departments.level` has **no default** — a writer that forgets the tier errors instead of silently becoming a level‑1 root, which is exactly the bug that once produced four roots ([create_departments_table.php:108‑118](../database/migrations/Departments/2026_06_21_100000_create_departments_table.php#L108-L118)); tier is read from `level`, "never `parent_id IS NULL` — the root is the general administration because level says so, not because it happens to lack a parent" ([Department.php:42‑47](../app/Modules/Departments/Models/Department.php#L42-L47)); `is_system` is set explicitly on the model even though the DB defaults it, so the returned object reflects the truth without a reload ([RoleService.php:56‑65](../app/Modules/Auth/Services/RoleService.php#L56-L65)).

**P4 — Data changes, not code.** Where the domain is expected to grow, growing it should be editing data or a one‑line enum case, not a migration and a redeploy. Seen in: adding an org tier is one line (`case Branch = 4;`) with "no migration, no ALTER, no schema change, no downtime" — the entire reason `level` is an int and not a SQL `ENUM` ([DepartmentLevel.php:14‑26](../app/Modules/Departments/Enums/DepartmentLevel.php#L14-L26)); the generic `scopeAtLevel()` means a new tier is queryable without a new scope ([Department.php:214‑223](../app/Modules/Departments/Models/Department.php#L214-L223)); `super_admin` gains every future permission automatically through the `Gate::before` bypass, with no re‑seed ([AppServiceProvider.php:27‑34](../app/Providers/AppServiceProvider.php#L27-L34)).

Keep these four in mind and most of what follows will read as inevitable rather than arbitrary.

---

## 3. The data model — table by table

### 3.1 `departments`

**Purpose.** A single self‑referencing table that *is* the org chart. Every organizational unit at every tier is a row here. "Department" is the **superordinate term for a unit at any tier** — it is not the Arabic الإدارة العامة (level 1) specifically ([Department.php:20‑32](../app/Modules/Departments/Models/Department.php#L20-L32)). The three tiers today:

| `level` | Enum case | Arabic (`label()`) | Role |
|---|---|---|---|
| 1 | `GeneralAdministration` | الإدارة العامة | The single root — the trunk everything hangs from |
| 2 | `Division` | إدارة | Sits under the root |
| 3 | `Section` | قسم | Sits under a division; deepest tier today |

Source: [DepartmentLevel.php:38‑52](../app/Modules/Departments/Enums/DepartmentLevel.php#L38-L52).

**Schema.**

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `name` | string | |
| `code` | string, nullable, **unique** | Short human code ("HR", "FIN‑01"); nullable‑unique allows many NULLs but forbids duplicates ([:71](../database/migrations/Departments/2026_06_21_100000_create_departments_table.php#L71)) |
| `description` | text, nullable | |
| `is_active` | boolean, default true | Retire a unit from selection lists without deleting it (and without tripping the delete guard) ([:79](../database/migrations/Departments/2026_06_21_100000_create_departments_table.php#L79)) |
| `parent_id` | FK→departments, nullable, indexed, `restrictOnDelete` | The unit directly above; NULL only for the root ([:96‑100](../database/migrations/Departments/2026_06_21_100000_create_departments_table.php#L96-L100)) |
| `level` | unsignedTinyInteger, **NOT NULL, no default**, indexed | Authoritative tier marker ([:118](../database/migrations/Departments/2026_06_21_100000_create_departments_table.php#L118)) |
| `manager_id` | FK→employees (added later), nullable, `nullOnDelete` | The employee in charge; one column serves every tier ([:127](../database/migrations/Departments/2026_06_21_100000_create_departments_table.php#L127)) |
| `timestamps`, `softDeletes` | | Never hard‑deleted |

**Relationships** ([Department.php:159‑191](../app/Modules/Departments/Models/Department.php#L159-L191)): `parent()`/`children()` (both on `parent_id`), `employees()` (the staff of the unit), `manager()` (BelongsTo Employee).

**The decisions embedded here, and why:**

- **Single self‑referencing table (adjacency list), not a table‑per‑tier and not nested sets.** A tier‑per‑table schema "forces a new table, model, and set of FKs for every future tier, and every 'walk the org chart' query becomes a UNION"; here "adding a tier is one enum case. The tree shape is data, not schema" ([Department.php:37‑41](../app/Modules/Departments/Models/Department.php#L37-L41)) — this is **P4**. Nested sets (left/right bounds) would make subtree reads O(1) but every insert/move a re‑numbering of the whole tree; for a low‑write org chart of a few hundred rows that trade is backwards, and the adjacency list keeps writes cheap and the model obvious. (*Note: the nested‑set comparison is my own derivation from the principles; the docblocks argue the adjacency list against table‑per‑tier explicitly, not against nested sets.*)

- **`level` as integer source of truth + a PHP `DepartmentLevel` enum as a lens — not a second column, not a SQL `ENUM`.** The tier name lives only in the enum, backed by the same ints. Adding a future tier is a one‑line enum change (`case Branch = 4;`) with no migration; a SQL enum would force an `ALTER` on every new tier, and a second `type`/`tier` string column alongside `level` would be "two columns describing one fact — a data‑integrity bug waiting to happen" ([create_departments_table.php:30‑37](../database/migrations/Departments/2026_06_21_100000_create_departments_table.php#L30-L37), [DepartmentLevel.php:14‑31](../app/Modules/Departments/Enums/DepartmentLevel.php#L14-L31)). This is **P1 + P4** in one decision. The enum also *doubles as the registry of valid depth*: a level must map to a case, so `tryFrom()` returning null is what validation uses to reject an undefined depth — "there is no separate max‑depth constant to keep in sync" ([DepartmentLevel.php:27‑31, 88‑91](../app/Modules/Departments/Enums/DepartmentLevel.php#L27-L31)).
  - **The honest limitation:** growth *downward* is free (append a case), but growth *above the root* is not. Inserting a new top tier renumbers every existing row's `level` by one — the exact "renumber‑every‑row data migration" the audit flagged as unavoidable ([phase1-audit-report.md finding C](phase1-audit-report.md)). It cost exactly this once already (see the single‑root change below).

- **The single‑root tree — a deliberate change from an earlier rootless design.** The company tree has exactly one parentless row, and it must be the الإدارة العامة (level 1). This was **not** the original model: the code first shipped a **two‑tier, rootless forest** (`Division = 1`, `Section = 2`, and the seeder created four parentless divisions). The root was introduced so that the escalation/approval chain has somewhere to terminate — a division manager in a forest escalates to nothing ([phase1-audit-report.md finding E, §5 Q2](phase1-audit-report.md)). What it cost: the enum was renumbered (`GeneralAdministration = 1`, `Division = 2`, `Section = 3`), which shifted **every** `level` value, the seeder, both tier scopes, `root()`, and the ~400‑line hierarchy test suite — the upward‑growth migration made real. The singleton‑root rule ("at most one parentless row") cannot be expressed in MySQL (no partial unique index), so it lives in the application layer ([DepartmentHierarchyGuard.php:15‑25, 74‑80](../app/Modules/Departments/Support/DepartmentHierarchyGuard.php#L15-L25)).

- **Manager is a *derived* fact — `departments.manager_id` points at the employee — not `employees.is_manager`.** Manager status is "derived from the department pointing at the employee, and nothing is stored on Employee" ([phase1-audit-report.md finding H](phase1-audit-report.md), [Department.php:185‑191](../app/Modules/Departments/Models/Department.php#L185-L191)). This is **P1**: an `is_manager` boolean would be a second, desyncable copy of a fact the pointer already states, and it could not answer "manager of *what*". There is deliberately **no `unique` on `manager_id`** (one person may manage several units) and deliberately **no `Employee::managedDepartment()` relation** (which would wrongly assume one‑unit‑per‑manager) — the audit calls the omission "correct."

- **The circular‑FK migration sequencing — three migrations, and why `parent_id` is exempt.** `departments.manager_id → employees.id` and `employees.department_id → departments.id` each point at the other, so neither can declare its constraint at creation. The resolution splits it across three ordered migrations: (1) create `departments` with the `manager_id` *column* but **no FK constraint** ([create_departments_table.php:120‑127](../database/migrations/Departments/2026_06_21_100000_create_departments_table.php#L120-L127)); (2) create `employees` with its `department_id` FK ([create_employees_table.php:52‑55](../database/migrations/HR/2026_06_21_110000_create_employees_table.php#L52-L55)); (3) add the deferred `manager_id` FK ([add_manager_fk_to_departments.php:29‑32](../database/migrations/Departments/2026_06_21_120000_add_manager_fk_to_departments.php#L29-L32)). Timestamps enforce the order (`100000` < `110000` < `120000`). **`parent_id` is exempt** because a self‑referencing FK is valid at creation — "the table exists the moment it is created," and Laravel emits the constraint as a separate `ALTER TABLE` after the `CREATE` ([create_departments_table.php:22‑28](../database/migrations/Departments/2026_06_21_100000_create_departments_table.php#L22-L28)). The `manager_id` chicken‑and‑egg is a genuinely different problem: it points at *another* table that does not exist yet.

### 3.2 `employees`

**Purpose.** The HR profile of a person — the **single source of truth for identity** (§P1). An employee may or may not have a login. Owned by the HR module; the tree that references them is owned by Departments ([Employee.php:15‑41](../app/Modules/HR/Models/Employee.php#L15-L41)).

**Schema (notable columns).**

| Column | Type | Notes |
|---|---|---|
| `employee_number` | string, **unique** | Stable staff id; auto‑generated `EMP‑00001…` when omitted ([:39](../database/migrations/HR/2026_06_21_110000_create_employees_table.php#L39)) |
| `name` | string | The one place a person's name is stored |
| `national_id` | string, nullable, **unique** | Government id; nullable‑unique (many NULLs, no duplicate real ids) ([:46](../database/migrations/HR/2026_06_21_110000_create_employees_table.php#L46)) |
| `department_id` | FK→departments, nullable, `nullOnDelete`, indexed | |
| `job_title` | string, nullable | **Display only** — never used in logic (§below) |
| `salary` | decimal(12,2), default 0 | The most sensitive column in Phase 1 ([:59](../database/migrations/HR/2026_06_21_110000_create_employees_table.php#L59)) |
| `status` | enum(active/inactive/terminated), default active, indexed | Employment status — *distinct from* account access ([:61‑63](../database/migrations/HR/2026_06_21_110000_create_employees_table.php#L61-L63)) |
| `timestamps`, `softDeletes` | | Never hard‑deleted |

**Decisions and why:**

- **`employee_number` auto‑generated in a model `creating` hook, not in the service.** It lives in the model — "the one layer all writers cross" — "mirroring how the Department hierarchy guard is enforced in a model hook rather than only in the form request" ([Employee.php:72‑90](../app/Modules/HR/Models/Employee.php#L72-L90)). This is **P2**: API, seeder, factory, and tinker all get a number. A supplied number is respected; the generated form uses `withTrashed()->max('id')` so a soft‑deleted employee's number is never reissued, and the `UNIQUE` index is the final backstop against a concurrent collision ([Employee.php:92‑100](../app/Modules/HR/Models/Employee.php#L92-L100)).
- **`SoftDeletes`, never hard‑deleted.** A person is FK'd by `users.employee_id`, `departments.manager_id`, and later modules; hard‑deleting would null those links and destroy history payroll/audit still need ([Employee.php:24‑30](../app/Modules/HR/Models/Employee.php#L24-L30)). The audit had flagged the *absence* of this as "destructive and asymmetric" — deleting an employee used to silently strip them as manager from every unit they led; it is now symmetric with `departments`.
- **`job_title` is display‑only.** It appears only in `$fillable`, a validation rule, and the resource — never in a branch or query. This is a prohibition the code honours: tier/role behaviour is driven by structure (`level`, `manager_id`), not by a free‑text title.
- **`salary` is the one column to watch.** It is `$fillable`, audited via `HasAuditLog`, and currently exposed to anyone who can read an employee. The audit flags that "who may read it" deserves a deliberate decision rather than a silent default ([phase1-audit-report.md §4 item 3](phase1-audit-report.md)) — see §6.

### 3.3 `users`

**Purpose.** The login/identity account. It holds **no name of its own** — a user's display name is the linked employee's, resolved through `users.employee_id → employees.name` ([User.php:29‑34](../app/Modules/Auth/Models/User.php#L29-L34)).

**Schema (notable columns).**

| Column | Type | Notes |
|---|---|---|
| `email` | string, unique | |
| `password` | string, `hashed` cast | |
| `is_active` | boolean, default true, indexed | The account access flag — the "may this person use the system" fact ([add_is_active_to_users_table.php:26‑30](../database/migrations/Auth/2026_05_13_124310_add_is_active_to_users_table.php#L26-L30)) |
| `last_login_at` | timestamp, nullable | Stamped quietly on each login (§4.5) ([:29](../database/migrations/Auth/2026_05_13_122954_create_users_table.php#L29)) |
| `employee_id` | FK→employees, nullable, **unique**, `nullOnDelete` | One login per employee, at most ([add_employee_id_to_users_table.php:34‑39](../database/migrations/Auth/2026_06_21_130000_add_employee_id_to_users_table.php#L34-L39)) |

**Decisions and why:**

- **`users` ⊂ `employees`, `name` dropped (P1).** Storing a name here would give a person two names that "can already diverge today." Identity lives in one place; an account with no employee link "simply has no name" ([create_users_table.php:16‑21](../database/migrations/Auth/2026_05_13_122954_create_users_table.php#L16-L21)). The resources resolve it: `AuthResource`/`UserResource` return `name = employee?->name` ([AuthResource.php:54](../app/Modules/Auth/Resources/AuthResource.php#L54), [UserResource.php:34](../app/Modules/Auth/Resources/UserResource.php#L34)). The create‑user API **requires** an `employee_id` (front door), while the schema keeps it nullable‑unique for unlinked service accounts (backstop) ([CreateUserRequest.php:38‑43](../app/Modules/Auth/Requests/CreateUserRequest.php#L38-L43)).
- **`employee_id` is `nullable().unique()` (P2).** The FK on `users` (not `employees`) means auth can resolve a login's employee, while an employee can exist without a login. `Employee::user()` is a `hasOne` — the model assumes exactly the uniqueness the unique index now guarantees ([Employee.php:110‑118](../app/Modules/HR/Models/Employee.php#L110-L118)).

### 3.4 `roles`, `permissions`, and the two pivots

**Purpose.** A hand‑rolled RBAC. A **role** is a named bundle of permissions; a **permission** is a single fine‑grained capability; users hold roles; a user's effective permissions are the **union** of their roles' permissions (§4.4). No `spatie/laravel-permission` — verified absent from `composer.lock` (only the unrelated transitive `spatie/once` is present).

**`roles`** ([create_roles_table.php](../database/migrations/Auth/2026_06_21_140000_create_roles_table.php)): `name` (unique slug), `label` (Arabic display), `description`, `is_system` (boolean, default false, indexed). `is_system` marks a built‑in role the app depends on; system roles cannot be renamed or deleted through the API — `RoleService` refuses them — "so the RBAC bootstrap can never be edited out from under the app" ([:14‑21](../database/migrations/Auth/2026_06_21_140000_create_roles_table.php#L14-L21)). `is_system` is `$fillable` (so the seeder can set it) but never accepted by a form request, so the API cannot flip it ([Role.php:16‑36](../app/Modules/Auth/Models/Role.php#L16-L36)).

**`permissions`** ([create_permissions_table.php](../database/migrations/Auth/2026_06_21_141000_create_permissions_table.php)): `name` (unique, dotted key), `label` (Arabic), `module` (indexed), `description`. Two settled naming decisions here, both ratified as deliberate deviations from the never‑committed spec:
- The column is **`module`, not the spec's `group`** — `group` is a reserved word in MySQL, and `module` matches the modular layout ([phase1-audit-report.md §4 item / §5 Q5](phase1-audit-report.md)).
- Permission names are **three‑part `{module}.{resource}.{action}`** (e.g. `hr.employees.view`), not the two‑part `module.action` the spec's examples implied — strictly more expressive and aligned with the module layout ([Permission.php:12‑16](../app/Modules/Auth/Models/Permission.php#L12-L16), [PermissionSeeder.php:29‑39](../database/seeders/PermissionSeeder.php#L29-L39)). Note one nuance: Departments became its own module, so its permissions are `departments.*` (matching its route prefix), while employees stay `hr.employees.*` ([PermissionSeeder.php:18‑22](../database/seeders/PermissionSeeder.php#L18-L22)).

**The two pivots** — `role_permissions` and `user_roles` — both use a **composite primary key with no surrogate `id`** and `cascadeOnDelete` on both FKs ([create_role_permissions_table.php:21‑25](../database/migrations/Auth/2026_06_21_142000_create_role_permissions_table.php#L21-L25), [create_user_roles_table.php:21‑25](../database/migrations/Auth/2026_06_21_143000_create_user_roles_table.php#L21-L25)). The composite PK both enforces "no duplicate grant" (P2) and serves as the lookup index. This is the union RBAC model with no per‑user grants and no deny rules.

**Custom RBAC over Spatie — the decision and its accepted cost.** Rolling RBAC by hand keeps the schema and the resolution logic fully under project control (three‑part names, `is_system` protection, an Arabic `label`, a super‑admin *bypass* rather than a grant). The **accepted cost is that caching and invalidation are now ours to get right** — Spatie ships that for free. Phase 1 pays that cost explicitly with `PermissionCache` (§4.4). The audit's judgement that this was worth it is implicit in ratifying the whole RBAC shape; the cost is called out here because it is real and ongoing.

### 3.5 `audit_logs`

**Purpose.** An **append‑only** trail of who changed what and when ([create_audit_logs_table.php:9‑12](../database/migrations/Shared/2026_05_13_125646_create_audit_logs_table.php#L9-L12)). In production the DB user should be granted `INSERT + SELECT` only.

**Schema (notable columns):** `user_id` (nullable, `nullOnDelete` — null for console/seeder/system events), `event` (indexed), `auditable_type`/`auditable_id` (nullable polymorphic — null for action‑level events like login), `old_values`/`new_values` (**json, nullable** — NULL states "nothing to record" more honestly than `[]`; a `created` event has no old values, a `deleted` has no new), `description` (for action‑level events), `ip_address`, `user_agent`, `created_at` (indexed) ([:49‑93](../database/migrations/Shared/2026_05_13_125646_create_audit_logs_table.php#L49-L93)). The nullable `old/new_values` and the `description` column plus the extra indexes are ratified supersets of the spec, which described a model‑only design and did not anticipate action‑level auditing ([phase1-audit-report.md §K, §4 item 5](phase1-audit-report.md)).

How it is written and why it can never audit itself is a workflow — see §4.5.

### 3.6 `notifications`

**Purpose.** In‑app notifications, **one row per recipient user.** This is a **custom table keyed by `user_id`** — deliberately *not* Laravel's stock notifications schema (UUID PK, polymorphic `notifiable`, JSON `data`, `read_at`). This is the one decision recorded in full in the in‑repo ADR ([erp-phase1-architecture.md §7.2](erp-phase1-architecture.md)).

**Schema** ([create_notifications_table.php:34‑43](../database/migrations/Auth/2026_06_21_150000_create_notifications_table.php#L34-L43)): `id`, `user_id` (FK, `cascadeOnDelete`), `title`, `body` (nullable), `type` (nullable, machine category), `reference_type`+`reference_id` (`nullableMorphs` — optional deep‑link to the subject record), `is_read` (boolean, default false, indexed), timestamps.

**Why per‑user storage.** Department and role sends are **snapshot fan‑outs**: the audience is expanded to individual users *at send time*, so the schema never needs to model a department or role as a recipient. The grain follows the semantics (see §4.6). Consequently `$user->notify()` and the stock `database` channel are deliberately unused and would fail against this schema; the `Notifiable` trait remains on `User` only as inert boilerplate, and the custom `notifications()` relation shadows it ([User.php:103‑115](../app/Modules/Auth/Models/User.php#L103-L115)). The `Notification` model deliberately has **no `HasAuditLog`** — notifications are high‑frequency, system‑created, and read‑toggled, so auditing them would flood the trail ([Notification.php:14‑21](../app/Modules/Auth/Models/Notification.php#L14-L21)).

### 3.7 Supporting tables

Beyond the eight core tables: `refresh_tokens` (SHA‑256‑hashed refresh tokens with rotation, replay detection, and a scheduled prune — a full architectural feature ratified as an extension; [create_refresh_tokens_table.php](../database/migrations/Auth/2026_05_13_125323_create_refresh_tokens_table.php), [AuthService.php](../app/Modules/Auth/Services/AuthService.php)); `personal_access_tokens` (Sanctum access tokens); and the framework's `password_reset_tokens`, `sessions`, `cache`, and `jobs` tables. These are infrastructure, not domain, and are called out only so the schema inventory is complete.

---

## 4. System workflows

### 4.1 Building the org tree

Creating a unit flows Controller → Service → Model, with validation on the way in. A `POST /api/departments` hits `DepartmentController::store`, which calls `DepartmentService::create($request->validated())` and returns a `DepartmentResource` ([DepartmentController.php:49‑57](../app/Modules/Departments/Controllers/DepartmentController.php#L49-L57)). Every message the controller returns is **tier‑neutral** — الوحدة التنظيمية ("the organizational unit"), never "قسم" — so the API contract never teaches a consumer that a department is one specific tier ([DepartmentController.php:45,55,63,73,81](../app/Modules/Departments/Controllers/DepartmentController.php#L45)).

The invariants enforced on every create/move (owned by `DepartmentHierarchyGuard`, §4.2):

- **Root rule:** a parentless unit must be الإدارة العامة (level 1).
- **Singleton root:** at most one parentless row may exist.
- **Child rule:** `level = parent.level + 1` exactly — no skipping, and nothing may nest under the deepest defined tier (expressed via `DepartmentLevel::next()`).
- **Defined‑level:** the level must map to an enum case.
- **No self‑reference / no cycle** (update only): a unit cannot be its own parent, nor move under one of its own descendants.

The seeder is the reference dataset and builds a single rooted three‑tier tree with model events **on**, so every seeded row passes the same guard the API enforces (this is the fix for the old "seeder made four roots" bug — [DatabaseSeeder.php:16‑80](../database/seeders/DatabaseSeeder.php#L16-L80)).

### 4.2 The guard architecture

This is the most important story in Phase 1, so it gets room.

**The problem.** Before remediation, the tier rules lived only in `DepartmentRequest` — the HTTP layer. Any non‑HTTP writer (seeder, tinker, job, factory) bypassed them entirely. That is not hypothetical: it is *exactly* how the seeder created four roots ([DepartmentHierarchyGuard.php:27‑32](../app/Modules/Departments/Support/DepartmentHierarchyGuard.php#L27-L32)). Validation that only guards the front door is discipline, not structure (P2).

**The design.** All structural invariants moved into one stateless class, [`DepartmentHierarchyGuard`](../app/Modules/Departments/Support/DepartmentHierarchyGuard.php), which operates on primitives (`level`, `parentId`, `subjectId`) so it is coupled to neither HTTP nor Eloquent and is trivially unit‑testable ([:41‑52](../app/Modules/Departments/Support/DepartmentHierarchyGuard.php#L41-L52)). It returns a list of `HierarchyViolation` value objects (`field` + Arabic `message`), and **two entry points call it**:

1. **The form requests** (`StoreDepartmentRequest`/`UpdateDepartmentRequest` via the shared base `DepartmentRequest`) run it in an `after()` hook and translate each violation into a field‑level **422**, preserving the Arabic front‑door UX ([DepartmentRequest.php:137‑167](../app/Modules/Departments/Requests/DepartmentRequest.php#L137-L167)).
2. **The `Department` model's `saving` hook** runs the *same* guard against the pending attributes and throws `DepartmentHierarchyException` on the first violation ([Department.php:125‑139](../app/Modules/Departments/Models/Department.php#L125-L139)).

**Why this matters.** The model hook is the one layer *every* writer crosses — controller, service, seeder, tinker, factory, future modules. The form request keeps the nice 422s for HTTP; the model hook is the backstop for everything else, because "silent failure on a foundation table is worse than an error" ([DepartmentHierarchyException.php:12‑28](../app/Modules/Departments/Exceptions/DepartmentHierarchyException.php#L12-L28)). The precedent was already set by the soft‑delete children guard, which has always been a model `deleting` hook. The logic exists **once** and is enforced **everywhere** — P2 in its purest form.

A few subtleties worth knowing:
- The `saving` hook reads **raw attributes**, not the cast accessor, so an undefined level surfaces as the domain exception rather than the enum cast's `ValueError` ([Department.php:127‑134](../app/Modules/Departments/Models/Department.php#L127-L134)).
- The cycle check walks *raw* `parent_id` pointers (no level cast) and runs **before** loading the parent, so it stays valid even if a parent sits at a not‑yet‑defined tier; it carries a visited set so a pre‑existing cycle in the data cannot hang the walk. O(depth), bounded by `DepartmentLevel` ([DepartmentHierarchyGuard.php:90‑99, 145‑169](../app/Modules/Departments/Support/DepartmentHierarchyGuard.php#L90-L169)).
- Because the guard checks‑then‑writes, `DepartmentService` is the concurrency boundary: root creation serializes on a named cache lock (`departments:root-create`) — there is no row to lock because the root does not exist yet — and child create/delete take a row lock on the *parent* inside a transaction, so "create a child under X" and "delete X" cannot interleave ([DepartmentService.php:24‑38, 82‑131](../app/Modules/Departments/Services/DepartmentService.php#L82-L131)). This is enforced for real only on MySQL + a shared lock store; on the sqlite `:memory:` test driver it is correctness‑by‑construction (see §6).

Coverage is real: [DepartmentHierarchyTest](../tests/Feature/Departments/DepartmentHierarchyTest.php) exercises 32 cases including `test_a_second_root_is_rejected_on_create`, `test_the_hierarchy_guard_holds_on_a_non_http_write`, and `test_the_children_guard_holds_outside_the_http_layer`.

### 4.3 The escalation / approval chain

**Read this carefully, because it is the clearest case in Phase 1 of "documented as intent, not implemented as code."**

The org tree exists in large part to support an **escalation/approval chain**: an employee resolves to their manager, and unresolved matters climb the tree. The single root was introduced specifically so this chain has a **terminus** — the المدير العام (general manager), who is the `manager_id` of the الإدارة العامة root ([phase1-audit-report.md finding E, §5 Q2](phase1-audit-report.md)). The structural prerequisites are all in place:

- An employee → their department (`employees.department_id`), and a department → its manager (`departments.manager_id`).
- The tree is walkable upward via `parent_id` (the guard and the notification fan‑out both do this).
- A guaranteed terminus: the single undeletable root, whose manager is the المدير العام.

**But there is no code that walks the chain.** A repo‑wide search for escalation/approval logic finds only the `manager()` relation, the `manager_id` column, and the two defensive tree walks (`DepartmentHierarchyGuard::wouldCreateCycle`, `NotificationService::departmentSubtreeIds`) — no resolver that climbs from an employee to their approving manager, no vacant‑post fallback, and **no self‑approval skip**. The specific behaviours the chain will eventually need — *what happens when a manager post is vacant, whether an employee who is their own unit's manager skips a level, where exactly the chain stops* — are **not specified in any in‑repo document** and **not implemented**. They are referenced only as concepts (spec §4.7 escalation, §9.1 "must a manager belong to the unit they manage") in the audit, and §9.1 is explicitly recorded as *unratified* ([phase1-audit-report.md §5 Q4](phase1-audit-report.md)); `manager_id` today accepts any employee id, with no "manager belongs to the unit" rule ([DepartmentRequest.php:107‑111](../app/Modules/Departments/Requests/DepartmentRequest.php#L107-L111)).

So the honest statement is: **Phase 1 lays the structure the approval chain needs and guarantees it a terminus; it does not yet implement the walk, and the vacant‑post and self‑approval‑skip rules are undecided.** (I have deliberately not invented those rules — see the sourcing note in §6.)

### 4.4 Permission resolution

A user's **effective permissions are the union of their roles' permissions.** `User::hasPermission($ability)` short‑circuits `true` for a super admin, otherwise checks membership in the resolved set ([User.php:168‑175](../app/Modules/Auth/Models/User.php#L168-L175)). Two things make this correct and fast:

**`super_admin` is a bypass, not a grant.** The `super_admin` role holds **zero** explicit permissions ([RoleSeeder.php:14‑40](../database/seeders/RoleSeeder.php#L14-L40)). Its power comes from a single `Gate::before` callback that returns `true` for a super admin and `null` (not `false`) for everyone else — `null` lets normal checks decide, `false` would short‑circuit into a deny ([AppServiceProvider.php:27‑34](../app/Providers/AppServiceProvider.php#L27-L34)). The same bypass is honoured by the middleware because it calls `hasPermission()`, which consults `isSuperAdmin()` ([CheckPermission.php:49‑59](../app/Shared/Middleware/CheckPermission.php#L49-L59)). This is **P4**: the old design synced every permission onto one `admin` role, which meant every *new* permission silently locked admin out until someone re‑seeded — a bug that shows up "in production only." A bypass makes that class of bug impossible. For UI purposes a super admin still *resolves* to the full catalogue, so a client can render their full set ([PermissionCache.php:99‑116](../app/Modules/Auth/Support/PermissionCache.php#L99-L116)). Note the middleware has **no fail‑open fallback** — the old `method_exists($user, 'hasPermission')` guard (which passed every check if the method were ever absent) was removed ([CheckPermission.php:49‑59](../app/Shared/Middleware/CheckPermission.php#L49-L59)).

**The cache and its invalidation obligation.** Resolution is served from [`PermissionCache`](../app/Modules/Auth/Support/PermissionCache.php), which caches each user's resolved set under a key that embeds a **global `rbac:version` stamp**. Any RBAC mutation calls `flush()`, which *bumps the version* — instantly orphaning **every** user's cached entry at once, so the next read recomputes; no per‑user key enumeration, no stale reads ([:12‑38, 64‑71, 118‑121](../app/Modules/Auth/Support/PermissionCache.php#L64-L71)). The trade‑off of a global bump is that one change re‑warms everyone lazily — fine for an ERP's change rate, far safer than trying to compute which users a role edit touched.

The **obligation** the audit warned about: every write that changes what a user may do must go through code that flushes. `RoleService` flushes on create/update/delete/assign/unassign ([RoleService.php:80, 127, 151, 173, 192](../app/Modules/Auth/Services/RoleService.php#L80)), and the seeders — which write pivots *directly*, bypassing the service — call `PermissionCache::flush()` themselves ([DatabaseSeeder.php:146‑148](../database/seeders/DatabaseSeeder.php#L146-L148), [UserSeeder.php:41‑42](../database/seeders/UserSeeder.php#L41-L42), [RoleSeeder.php:42‑43](../database/seeders/RoleSeeder.php#L42-L43)). System‑role immutability is enforced at the **service** layer: `RoleService::update()/delete()` throw `SystemRoleException` (409) before touching an `is_system` role ([RoleService.php:98‑164](../app/Modules/Auth/Services/RoleService.php#L98-L164)). Prod caveat: cross‑worker correctness needs a shared cache store; the array driver is fine in tests because each test boots a fresh app.

### 4.5 Auditing

Model changes are captured by the [`HasAuditLog`](../app/Shared/Traits/HasAuditLog.php) trait, attached to `Department`, `Employee`, `User`, `Role`, and `Permission`. It hooks `created`/`updated`/`deleted` and delegates to [`AuditLogService`](../app/Shared/Services/AuditLogService.php). On update it logs only real attribute changes (dropping a timestamp‑only diff), capturing `getOriginal()` (old) and `getChanges()` (new) ([HasAuditLog.php:34‑59](../app/Shared/Traits/HasAuditLog.php#L34-L59)). Audit failures are caught and reported, never allowed to break the main operation ([:83‑88](../app/Shared/Traits/HasAuditLog.php#L83-L88)).

Two invariants the design turns on:

- **The self‑audit loop is avoided structurally.** `AuditLogService` writes via **`DB::table('audit_logs')->insert()`, not Eloquent** — so no model events fire and recursion is impossible. There is no `AuditLog` model using the trait ([AuditLogService.php:26‑57](../app/Shared/Services/AuditLogService.php#L26-L57)).
- **`password` (and its kin) are excluded.** `sanitize()` redacts `password`, `password_hash`, `remember_token`, `token`, `refresh_token`, `secret`, and the two‑factor fields on both `old_values` and `new_values` ([AuditLogService.php:104‑125](../app/Shared/Services/AuditLogService.php#L104-L125)). This is load‑bearing: `HasAuditLog` passes raw `$model->getAttributes()`/`getOriginal()`, which **do** contain the password hash for `User` — the service is the only thing standing between them and the trail. `encode()` returns NULL rather than `'[]'` when there is nothing to record, matching the nullable columns ([:89‑96](../app/Shared/Services/AuditLogService.php#L89-L96)).

There are two complementary layers: **model‑level** (what changed, field‑level diff) via the trait, and **request‑level** (who did what action, when) via [`AuditLogMiddleware`](../app/Shared/Middleware/AuditLogMiddleware.php), which logs each authenticated request as an `api_request` action after the response ([:44‑73](../app/Shared/Middleware/AuditLogMiddleware.php#L44-L73)). Security‑relevant non‑model events (login, logout, token rotation, replay detection, role grants) are logged as actions via `logAction()`. Logins are stamped quietly: `AuthService::login` sets `last_login_at` with `saveQuietly()` so it does **not** emit a `HasAuditLog` "updated" row per sign‑in — the `user_logged_in` action already records the event ([AuthService.php:100‑106](../app/Modules/Auth/Services/AuthService.php#L100-L106)). The `user_id` column is nullable precisely so seeder/console/system writes (which have no auth user) can still be recorded ([DatabaseSeeder.php:33‑38](../database/seeders/DatabaseSeeder.php#L33-L38)).

### 4.6 Notifications

All notification rows are created through **one service, three explicit methods** — `NotificationService::sendToUser / sendToDepartment / sendToRole` ([NotificationService.php:49‑196](../app/Modules/Auth/Services/NotificationService.php#L49-L196)). There is deliberately **no combined multi‑type dispatcher** — the three methods are the ratified surface; a combined form may be layered on later if a real case demands it, but is not pre‑built ([erp-phase1-architecture.md §7.2](erp-phase1-architecture.md)).

**Snapshot fan‑out.** Department and role sends expand to individual users **at send time** and write one plain per‑user row each. The audience is frozen at that moment: someone who joins the department or gains the role *later* does not retroactively receive the notification ([NotificationService.php:24‑47](../app/Modules/Auth/Services/NotificationService.php#L24-L47)). This is *why* the storage is per‑user (§3.6): the schema never models a department or role as a recipient because the expansion happens here, once.

Details that matter:
- **Department sends are subtree‑inclusive.** Sending to a division reaches its own staff *and* every member of its sections; sending to the root reaches the whole company. Anything else would make "notify الإدارة الهندسية" silently skip everyone in its sections. Resolution is an iterative `parent_id` frontier walk — one query per tier, O(depth), with a visited set — reusing the hardened hierarchy ([NotificationService.php:119‑169, 198‑233](../app/Modules/Auth/Services/NotificationService.php#L198-L233)).
- **Uniform eligibility:** a recipient is an account that *exists* and has `is_active = true`; the department path additionally requires a live (non‑soft‑deleted) employee row in the subtree. Employment `status` is **not** consulted — "whether a person may use the system is the account flag, one fact in one place" (P1). Employees with no login receive nothing ([NotificationService.php:38‑42, 131‑138](../app/Modules/Auth/Services/NotificationService.php#L131-L138)).
- **Empty audiences are valid and silent** (0 rows, no exception), and delivery is duplicate‑free ([NotificationService.php:249‑277](../app/Modules/Auth/Services/NotificationService.php#L249-L277)).
- The single writer, `deliver()`, uses a bulk `insert()` — safe precisely because `Notification` has no model events ([:235‑277](../app/Modules/Auth/Services/NotificationService.php#L235-L277)). The read side is scoped to the owning user, so authorization *is* the scoping ([:56‑90](../app/Modules/Auth/Services/NotificationService.php#L56-L90)); the read endpoints are deliberately outside the `audit` group because they are polled frequently and would flood the trail ([routes.php:110‑122](../app/Modules/Auth/routes.php#L110-L122)).

**Transport is a separate concern.** This layer settles *what is stored and for whom*. How a row reaches a device (FCM, mail, websockets, polling cadence) is an open, later decision (§6). The behaviour is covered by [NotificationSendTest](../tests/Feature/Auth/NotificationSendTest.php) (7 cases: subtree reach, ineligibility exclusion, silent empty/trashed targets, active‑only role holders, and rows flowing through the read endpoints).

### 4.7 Deletion semantics

Nothing in Phase 1 is hard‑deleted — `departments`, `employees`, and (soft) `users` all soft‑delete. The delete path has three layers of protection, and one crucial subtlety:

- **The children guard.** A unit with live children may not be soft‑deleted — a model `deleting` hook throws `DepartmentHasChildrenException` (409) ([Department.php:141‑156](../app/Modules/Departments/Models/Department.php#L141-L156)). It skips on force‑delete and relies on the SoftDeletes scope, so an *already‑emptied* subtree does not block its parent.
- **The undeletable root.** The single الإدارة العامة root may never be deleted by **any** path — soft *or* hard, including force‑delete — because deleting the trunk detaches the whole company and, unlike the children case, there is no "empty it first" that makes it safe. The check is keyed off `level` (P3), placed first in the `deleting` hook, and throws `DepartmentIsRootException` (409) ([Department.php:141‑145](../app/Modules/Departments/Models/Department.php#L141-L145), [DepartmentIsRootException.php:11‑25](../app/Modules/Departments/Exceptions/DepartmentIsRootException.php#L11-L25)). The children guard used to block the root only *incidentally* (a populated root has children); a leaf or emptied root would have deleted cleanly — which is why the root needs its own explicit guard.
- **The FK is a last resort, not the guard.** `parent_id` carries `restrictOnDelete`, but **that only fires on a hard `DELETE`.** A soft delete is an `UPDATE`, so the FK never sees it — the real protection against stranding a subtree under a trashed parent is the application‑layer `deleting` hook. This subtlety is stated accurately in the migration comment, and the audit confirms **no code anywhere relies on `restrictOnDelete` to protect soft deletes** ([create_departments_table.php:88‑91](../database/migrations/Departments/2026_06_21_100000_create_departments_table.php#L88-L91), [phase1-audit-report.md finding F](phase1-audit-report.md)).

These domain exceptions self‑render as the standard API envelope rather than requiring a block in `bootstrap/app.php`, which keeps modules zero‑config ([DepartmentHasChildrenException.php:11‑50](../app/Modules/Departments/Exceptions/DepartmentHasChildrenException.php#L11-L50)).

---

## 5. Decisions and their rationale — consolidated

The heart of the report. Each row: what was decided, why, what was rejected, and what it cost. Verified against the current code; the "source of why" column notes where the reasoning is recorded.

| # | Decision | Why | Rejected alternative | Cost / tradeoff | Source of *why* |
|---|---|---|---|---|---|
| 1 | Single self‑referencing `departments` table (adjacency list) | Add a tier = one enum case; the tree is data (P4) | Table‑per‑tier (UNION every query); nested sets (costly writes) | Subtree reads are O(depth) walks, not O(1) | [Department.php:37‑41](../app/Modules/Departments/Models/Department.php#L37-L41) |
| 2 | `level` int + `DepartmentLevel` PHP enum lens | One fact, one place (P1); new tier needs no migration (P4) | SQL `ENUM` (ALTER per tier); a second `type` column (drifts) | Growth *above* the root renumbers every row | [DepartmentLevel.php:14‑31](../app/Modules/Departments/Enums/DepartmentLevel.php#L14-L31) |
| 3 | `level` NOT NULL, **no default** | A forgotten tier must error, not silently become a root (P3) | `->default(1)` | Every writer must state the tier | [create_departments_table.php:108‑118](../database/migrations/Departments/2026_06_21_100000_create_departments_table.php#L108-L118) |
| 4 | Single root (الإدارة العامة), added after an initial rootless design | Gives the escalation chain a terminus | Rootless forest of divisions | Renumbered the enum + every `level` + tests | [phase1-audit-report.md §5 Q2](phase1-audit-report.md) |
| 5 | Singleton‑root enforced in the app layer | MySQL has no partial unique index | Rely on convention | Must be re‑checked on every create/move | [DepartmentHierarchyGuard.php:15‑25](../app/Modules/Departments/Support/DepartmentHierarchyGuard.php#L15-L25) |
| 6 | Hierarchy invariants in a shared guard called by the model hook **and** the form request | Reach every write path, not just HTTP (P2) | Rules only in the form request | Two call sites to keep pointed at one guard (mitigated: guard owns the logic) | [DepartmentHierarchyGuard.php:27‑40](../app/Modules/Departments/Support/DepartmentHierarchyGuard.php#L27-L40) |
| 7 | Manager derived via `departments.manager_id` | One fact (P1); answers "manager of what"; one person may manage many units | `employees.is_manager` boolean | None material | [Department.php:60‑62](../app/Modules/Departments/Models/Department.php#L60-L62); [phase1-audit-report.md §H](phase1-audit-report.md) |
| 8 | `users.name` dropped; name via `employee_id` | Identity in one place (P1) | Duplicate `name` on `users` | Unlinked accounts have null name | [create_users_table.php:16‑21](../database/migrations/Auth/2026_05_13_122954_create_users_table.php#L16-L21) |
| 9 | `users.employee_id` nullable **unique** | One login per employee (P2); service accounts allowed | Non‑unique FK | — | [add_employee_id_to_users_table.php:34‑39](../database/migrations/Auth/2026_06_21_130000_add_employee_id_to_users_table.php#L34-L39) |
| 10 | Circular FK split across 3 ordered migrations; `parent_id` exempt | Two tables can't both declare a mutual FK at creation; a self‑FK can | One migration | Reader must follow the timestamp order | [create_departments_table.php:39‑57](../database/migrations/Departments/2026_06_21_100000_create_departments_table.php#L39-L57) |
| 11 | `employee_number` auto‑generated in the model `creating` hook | Every write path gets one (P2) | Generate in the service only | `withTrashed()` max‑id + unique backstop needed | [Employee.php:72‑100](../app/Modules/HR/Models/Employee.php#L72-L100) |
| 12 | `employees` soft‑deletes | History/FKs must stay resolvable | Hard delete | — | [Employee.php:24‑30](../app/Modules/HR/Models/Employee.php#L24-L30) |
| 13 | Custom RBAC, no Spatie | Full control of schema/names/`is_system`/bypass | `spatie/laravel-permission` | **Caching & invalidation are now ours** | [phase1-audit-report.md §J](phase1-audit-report.md); `composer.lock` |
| 14 | `super_admin` = `Gate::before` bypass, zero explicit perms | New permissions covered automatically (P4); no re‑seed lockout | Grant one role every permission | A bypass role must be guarded from deletion (→ `is_system`) | [AppServiceProvider.php:27‑34](../app/Providers/AppServiceProvider.php#L27-L34); [RoleSeeder.php:14‑40](../database/seeders/RoleSeeder.php#L14-L40) |
| 15 | Per‑user permission cache with a global version stamp | Fast checks; invalidation is trivial and always correct | Per‑user key busting; no cache | One RBAC change re‑warms everyone lazily; needs shared store in prod | [PermissionCache.php:12‑38](../app/Modules/Auth/Support/PermissionCache.php#L12-L38) |
| 16 | System‑role immutability at the **service** layer (409) | The bypass role must never be renamed/deleted | Controller literal‑string check | — | [RoleService.php:98‑164](../app/Modules/Auth/Services/RoleService.php#L98-L164) |
| 17 | Composite pivot PKs, no surrogate `id`, cascade | No duplicate grant (P2); cheap lookup | Surrogate `id` + unique index | — | [create_role_permissions_table.php:9‑25](../database/migrations/Auth/2026_06_21_142000_create_role_permissions_table.php#L9-L25) |
| 18 | Audit writes via `DB::table()`, not Eloquent | Makes self‑auditing recursion impossible | An `AuditLog` Eloquent model | Bypasses casts/events (intended) | [AuditLogService.php:26‑57](../app/Shared/Services/AuditLogService.php#L26-L57) |
| 19 | Redact `password` & secrets before storage | Secrets must never enter the trail | Store raw attributes | Redaction list must track new sensitive fields | [AuditLogService.php:104‑125](../app/Shared/Services/AuditLogService.php#L104-L125) |
| 20 | Custom `user_id`‑keyed `notifications` + snapshot fan‑out | Snapshot semantics make per‑user the right grain | Laravel's stock schema | `$user->notify()`/stock channel unusable by design | [erp-phase1-architecture.md §7.2](erp-phase1-architecture.md) |
| 21 | Soft‑delete everywhere; app‑layer children guard + undeletable root | `restrictOnDelete` doesn't fire on soft deletes | Rely on the FK | Guard must live in the model hook | [Department.php:113‑156](../app/Modules/Departments/Models/Department.php#L113-L156) |
| 22 | `is_active` (account) distinct from employment `status` | "May use the system" is one fact (P1) | Consult employment status for access | Two flags to reason about (intended) | [NotificationService.php:38‑42](../app/Modules/Auth/Services/NotificationService.php#L38-L42) |
| 23 | Refresh‑token rotation + replay detection (ratified extension) | Real session security; theft detection | Long‑lived access tokens only | A table, a model, a scheduled prune | [AuthService.php:29‑50](../app/Modules/Auth/Services/AuthService.php#L29-L50) |
| 24 | `permissions.module` (not `group`); 3‑part names | `group` is a MySQL reserved word; 3‑part matches the module layout | Spec's `group` / 2‑part names | Spec examples mislead until amended | [phase1-audit-report.md §J, §5 Q5](phase1-audit-report.md) |

---

## 6. Known limitations and deferred items

Stated honestly — what Phase 1 does *not* do, and what was consciously left for later.

- **Depth‑based `level` grows freely downward, not upward.** Appending a tier below is a one‑line enum change; inserting a tier *above* the root renumbers every existing row. This is a real, accepted property of the model, not a bug ([DepartmentLevel.php:14‑31](../app/Modules/Departments/Enums/DepartmentLevel.php#L14-L31); [phase1-audit-report.md finding C](phase1-audit-report.md)).

- **The escalation/approval chain is structure without a walk.** The tree, the manager pointers, and the guaranteed terminus (the المدير العام at the root) all exist, but no code resolves an employee's approver or climbs the chain, and the **vacant‑post behaviour, self‑approval skip, and exact stop condition are unspecified** in any in‑repo document (see §4.3). *These were deliberately not invented for this report.*

- **The المدير العام has no one above them.** By construction, the approval chain terminates at the root's manager; there is no escalation past the general manager. That is the intended terminus, noted here so it is not mistaken for a gap.

- **`§9.1` (must a manager belong to the unit they manage?) is unratified and unimplemented.** `manager_id` accepts any employee id, with only an `exists`/not‑trashed check ([DepartmentRequest.php:107‑111](../app/Modules/Departments/Requests/DepartmentRequest.php#L107-L111); [phase1-audit-report.md §5 Q4](phase1-audit-report.md)).

- **Notification transport is undecided.** The storage layer is settled; FCM/mail/websockets/polling is a separate, later decision ([erp-phase1-architecture.md §7.2](erp-phase1-architecture.md)).

- **Concurrency guarantees are real only on MySQL + a shared lock/cache store.** The root‑creation lock and parent row locks (§4.2) and the permission cache (§4.4) are no‑ops / process‑local on the sqlite `:memory:` test driver — correctness‑by‑construction there, genuinely enforced in production ([DepartmentService.php:24‑38](../app/Modules/Departments/Services/DepartmentService.php#L24-L38); [PermissionCache.php:31‑38](../app/Modules/Auth/Support/PermissionCache.php#L31-L38)).

- **`employees.salary` read‑access is not yet governed.** It is `$fillable`, audited, and currently exposed to anyone who can read an employee; who *may* read it is flagged as a decision to make, not made ([phase1-audit-report.md §4 item 3](phase1-audit-report.md)).

- **PHP 8.3 and the React RTL frontend are open.** `composer.json` pins `^8.2`; no React is installed. The frontend absence is clean — the backend already ships `level_label` so a future client never redefines tiers ([DepartmentResource.php:32‑37](../app/Modules/Departments/Resources/DepartmentResource.php#L32-L37)).

### Documentation vs. implementation — divergences found while writing

Per the task's method (code is truth for *what*; docs are the source for *why*; report any drift):

1. **The primary "why" source is largely a stub.** `erp-phase1-architecture.md` contains only the ratified **§7.2**. The §2 principles, §3 per‑decision rationale, §6 RBAC, §10 settled‑decisions index, §11 prohibitions, §12 seeding order, and §L frontend checklist that the audit and this report reference **do not exist in the repo** — the original full spec was never committed. Those sections survive only as *paraphrase inside the audit's findings*, corroborated by the code docblocks. This report leans on the docblocks accordingly and never asserts a numbered spec section as if the file itself contained it.

2. **The code cites a different document than the task's named source.** Docblocks reference an "architecture report §16.4–16.8/§16.14" and "§7.1"/"§12" — these map to [`docs/PROJECT_ARCHITECTURE_REPORT.md`](PROJECT_ARCHITECTURE_REPORT.md) (its §16 "Developer Master Guide") and/or the never‑committed spec, **not** to `erp-phase1-architecture.md`. Multiple numbering schemes coexist across three documents; a reader chasing a "§" citation must know which doc it belongs to.

3. **The audit body describes the *pre‑fix* state.** `phase1-audit-report.md` is a snapshot of commit `e0dfece`; its findings (stale two‑tier enum, four roots, no `Gate::before`, `users.name` present, etc.) were all remediated in the three fix batches. The report's resolution header says so, but the finding *bodies* are historical — do not read them as current. This report cites the audit only for *rationale and the record of what changed*, never for current state.

4. **Notifications: doc and code now agree.** The audit body still frames §7.2 as an unresolved doc‑vs‑code conflict; that was closed by ratification — the migration docblock and `erp-phase1-architecture.md` §7.2 now say the same thing (custom table, snapshot fan‑out).

### Rationale I could not source (marked, not invented)

- **The escalation chain's operational rules** (vacant‑post fallback, self‑approval skip) — not in code, not in any in‑repo doc. Marked undocumented (§4.3).
- **The adjacency‑list‑vs‑nested‑sets comparison** in §3.1 is my own derivation from the principles; the docblocks argue the adjacency list against *table‑per‑tier* explicitly, but do not mention nested sets. Flagged so it is not mistaken for a sourced rationale.

Everything else in this report traces to a cited file or to the audit's record of the decision.

---

## 7. What comes next

**Projects is the next module**, and Phase 1 is precisely what it needs to exist first. A project references people (who is assigned — `employees`), organizational ownership (which unit owns the work — `departments`), and access control (who may create/approve — `permissions`). It also wants the audit trail and notifications from day one. **Projects could not have come first** because every one of those is a foreign key into a Phase 1 table: with no `employees` there is no one to assign, with no org tree there is nothing to scope work to, and with no RBAC catalogue there is nothing to gate the endpoints on. Building Projects before the foundation would have meant inventing throwaway stand‑ins for all three and then migrating off them — the exact structural rework this sequencing avoids.

When Projects (and later Finance, attendance, inventory) are built, they should follow the patterns Phase 1 establishes: a self‑contained module under `app/Modules/` with its own `routes.php` (auto‑discovered), thin controllers over services over models, Arabic API messages, `HasAuditLog` on domain models, permissions seeded as `{module}.{resource}.{action}` and enforced with the `permission:` middleware, and — the load‑bearing lesson of Phase 1 — **invariants enforced at the model layer, where every writer crosses, not only at the HTTP front door.**

The near‑term open items that gate a production cut are collected in §6: the notification transport decision, the escalation‑chain implementation, governing `salary` read‑access, the PHP 8.3 bump, and (when the product needs a UI) the React RTL frontend that consumes `level_label` rather than re‑deriving tiers.
