# Phase 1 Architecture Compliance Audit

**Date:** 2026-07-15
**Branch:** `phase_1`
**Commit audited:** `e0dfece` — *Translate all message into Arabic* (plus uncommitted working-tree changes)
**Spec:** `erp-phase1-architecture.md`
**Scope:** read-only. No files modified except this report.

---

## ⚠️ Resolution status (updated 2026-07-17)

> **This audit is a historical snapshot of commit `e0dfece`. The findings below have since been remediated in three batches; the body of the report is preserved unchanged as the record of what was found.** An independent AI review on 2026-07-16 verified the Batch 1 hierarchy work and surfaced the concurrency findings noted below.

| Batch | Commit | Resolves |
|---|---|---|
| **1 — org-tier correction** | `717ef1e` | Enum renumbered to `GeneralAdministration=1/Division=2/Section=3`; labels + `root()` retargeted; `level` default removed; **all hierarchy invariants extracted into one `DepartmentHierarchyGuard`** enforced in both the form requests (422) and the model `saving` hook (every write path); singleton root; undeletable root; seeder rebuilt as a rooted 3-tier Arabic tree with model events ON; tier-neutral controller messages; hierarchy tests rewritten |
| **2 — identity + integrity** | `717ef1e` | `users.name` dropped (name = linked employee's), `employee_id` unique + required by the create API, `last_login_at`; employees `employee_number` (auto-generated) / `national_id` / softDeletes; `manager_id` + `department_id` validation excludes soft-deleted rows; departments `code`/`description`/`is_active`; **create/delete race windows closed with transactions + row locks + a named root-creation lock** |
| **3 — RBAC** | `5983b0d` | `super_admin` (`is_system`, zero explicit permissions) + `Gate::before` bypass replaces the all-permissions `admin` role; system roles immutable at the **service** layer (409); `CheckPermission` fail-open removed; `roles.label`/`is_system` + `permissions.label` with Arabic labels; per-user permission cache with global-version invalidation |
| **4 — docs + audit leftovers** | this commit | `API_DOCUMENTATION.md` re-trued to the shipped API; `audit_logs.old_values`/`new_values` made nullable per spec; `HasAuditLog` added to `Role`/`Permission`; this status section |

**Ratified as deliberate extensions/deviations (Batch 4):** `permissions.module` column name (doc says `group` — reserved word in SQL, keeping `module`); refresh-token rotation layer; `employees.address`/`salary` columns; request-level audit logging; the `Support/` folder inside a module (holds `DepartmentHierarchyGuard`, `PermissionCache`).

**Still open:**
- **Notifications (§7.2)** — the custom schema vs. Laravel's stock notifications table. Deliberately untouched pending a product decision; the custom implementation remains internally consistent and tested.
- **PHP 8.3 target** — `composer.json` allows `^8.2` and the dev environment runs 8.2.x; bump when the runtime is upgraded.
- **React RTL admin frontend** — not started (backend-only phase); §L holds, so no client-side tier mirror exists to migrate.

---

## 1. Baseline

Phase 1 is **substantially built, not unbuilt** — migrations, models, enum, form requests, services, controllers, resources, RBAC tables, audit logging, and tests all exist. The engineering quality is high: the code is heavily documented, the hierarchy validation is thorough, and several of the doc's hardest prohibitions (no `is_manager`, no Spatie, composite pivot keys, password redaction, app-layer delete guard) are correctly honoured.

The problem is not craftsmanship — it is **vintage**. The codebase implements a **two-tier org model (إدارة → قسم)** that predates the doc's general-administration root decision. The code is internally consistent and self-documenting about a design the doc has since superseded. Every `level` value, the seeder, the scopes, and ~411 lines of hierarchy tests encode the old numbering.

Separately, the `users`/`employees` tables were never reshaped to the doc's §4.2/§5 spec, and the `super_admin`/`Gate::before` mechanism was never built. There is **no React admin frontend** at all.

---

## 2. Summary

| Category | Count |
|---|---|
| 🔴 VIOLATION | 7 |
| 🟡 GAP | 11 |
| 🔵 UNDOCUMENTED | 5 |
| ⚪ AMBIGUOUS | 4 |
| **Total** | **27** |

### Top 3 findings by risk

1. **🔴 `DepartmentLevel` is the stale two-tier numbering** (`Division = 1`, `Section = 2`). The doc requires `GeneralAdministration = 1`, `Division = 2`, `Section = 3`. This is the single highest-blast-radius defect in the audit: every `level` integer in the database, the seeder, both tier scopes, `root()`, the form-request tier maths, and the whole hierarchy test suite are built on it. It gets more expensive every day real data exists.
2. **🔴 The root node does not exist and the singleton root rule is unguarded anywhere.** The seeder creates **four** parentless departments. The doc's §3.9 singleton rule has no implementation in any layer, and MySQL cannot enforce it. The org chart is currently a forest, so the §4.7 escalation chain terminates nowhere for every division manager.
3. **🔴 `super_admin` bypass replaced by seeding one role every permission.** `RoleSeeder` grants the `admin` role the entire permission catalogue; there is no `Gate::before` anywhere in the codebase. This is the exact pattern §11 prohibits — every future permission silently locks admin out until someone re-seeds.

**Worth stating plainly:** the doc and code *agree* on a lot. No `spatie/laravel-permission` in `composer.json`. No `is_manager` column or attribute anywhere. No `unique` on `manager_id`. `job_title` is display-only and never used in logic. `parent_id` is correctly declared inside migration 1 with an index and `restrictOnDelete`. Both pivots use composite primary keys with `cascadeOnDelete` and no surrogate `id`. `AuditLogService` redacts `password` and writes via `DB::table()` so `AuditLog` cannot audit itself. The soft-delete children guard exists and is correctly placed in the application layer with an accurate comment explaining why the FK cannot do it. `level` is an integer, not a SQL `ENUM`, and the enum has not leaked into the schema.

---

## 3. Findings

### C. `DepartmentLevel` enum

#### 🔴 Stale two-tier numbering — no `GeneralAdministration` case
- **Doc reference:** §3.6, §3.1, §10
- **Location:** [app/Modules/Departments/Enums/DepartmentLevel.php:36-39](app/Modules/Departments/Enums/DepartmentLevel.php#L36-L39)
- **Expected:** `GeneralAdministration = 1`, `Division = 2`, `Section = 3`
- **Found:** `case Division = 1; case Section = 2;` — only two cases. No general-administration tier exists.
- **Risk:** This is the value every other tier decision is derived from. `level` is the documented single source of truth (§3.5), so a wrong mapping means every stored integer is wrong by one relative to the spec. Because §3.6 explicitly guarantees that new tiers are only free **downward**, inserting `GeneralAdministration` **above** the current tiers is precisely the renumber-every-row data migration the doc warns is unavoidable. The cost is not constant — it scales with rows created before the fix.
- **Direction:** Decide the renumbering strategy before any further department data is created; the enum, seeder, scopes, and hierarchy tests move together as one change.

#### 🔴 `label()` returns two-tier Arabic labels
- **Doc reference:** §3.6, §3.7
- **Location:** [app/Modules/Departments/Enums/DepartmentLevel.php:44-50](app/Modules/Departments/Enums/DepartmentLevel.php#L44-L50)
- **Expected:** `الإدارة العامة` / `إدارة` / `قسم`
- **Found:** `Division => 'إدارة'`, `Section => 'قسم'`. No `الإدارة العامة`.
- **Risk:** These labels reach clients through `DepartmentResource.level_label`, so the stale tier vocabulary is already part of the public API contract.
- **Direction:** Follows the enum renumbering; not separable from it.

#### 🔴 `root()` hardcodes the wrong root tier
- **Doc reference:** §3.9 (root rule), §3.1
- **Location:** [app/Modules/Departments/Enums/DepartmentLevel.php:75-78](app/Modules/Departments/Enums/DepartmentLevel.php#L75-L78)
- **Expected:** the root tier is `GeneralAdministration`; a division must **never** be a root.
- **Found:** `public static function root(): self { return self::Division; }` — asserts a division *is* the root tier.
- **Risk:** This is what [DepartmentRequest.php:133-141](app/Modules/Departments/Requests/DepartmentRequest.php#L133-L141) enforces the root rule against. Validation therefore *actively accepts* multiple parentless divisions — it is not merely failing to block the forest, it is certifying it as correct.
- **Direction:** Retarget once the enum gains its root case.

#### ✅ Enum structure is otherwise correct
- **Location:** [app/Modules/Departments/Enums/DepartmentLevel.php](app/Modules/Departments/Enums/DepartmentLevel.php)
- Backed int enum; `next()` exists (§3.7) and is what the child rule is expressed in terms of; `isDefined()` implements the defined-level registry; enum has not leaked into the schema. No magic-number levels found anywhere — every call site uses `DepartmentLevel::X->value`. The design is right; only the case values are stale.

---

### E. Hierarchy guards

#### 🔴 Singleton root rule is unimplemented in every layer
- **Doc reference:** §3.9, §3.1, §11
- **Location:** absent — grep for `singleton` / "only one root" / `GeneralAdministration` across `app/`, `database/`, `tests/` returns nothing.
- **Expected:** at most one row with `parent_id = null`, enforced in the application layer because MySQL cannot express it.
- **Found:** no guard. [DepartmentRequest.php:132-143](app/Modules/Departments/Requests/DepartmentRequest.php#L132-L143) checks only that a parentless row is level 1 — it never checks whether a parentless row *already exists*.
- **Risk:** §2 principle #2 exactly: nothing below the app layer is enforcing this, and the app layer isn't either. The org chart is a forest today. The §4.7 escalation chain has no terminus for any division manager, which is the specific problem the root node was introduced to solve.
- **Direction:** Add the uniqueness check to the root-rule branch of the shared form request.

#### 🔴 Seeder creates four roots
- **Doc reference:** §3.1, §3.9, §12 (step 5: "الإدارة العامة (the root) first")
- **Location:** [database/seeders/DatabaseSeeder.php:71-104](database/seeders/DatabaseSeeder.php#L71-L104)
- **Expected:** seed the root first, then divisions beneath it.
- **Found:** four departments (`Management`, `Human Resources`, `Finance`, `Engineering`) created via `updateOrCreate` with **no `parent_id` and no `level`** — each falls to `level` default 1 and `parent_id` null. Four roots, flat, no tree. Names are English, not Arabic.
- **Risk:** The seeder is the reference dataset every developer and test fixture inherits, so the forest is what the team's mental model gets calibrated against. It also bypasses the form requests entirely (it writes through the model), so no validation ever sees these rows.
- **Direction:** Rebuild the blueprint as a rooted tree once the enum numbering is settled.

#### ⚪ `WithoutModelEvents` disables the delete guard and audit trail during seeding
- **Doc reference:** §3.8, §7.1
- **Location:** [database/seeders/DatabaseSeeder.php:15](database/seeders/DatabaseSeeder.php#L15)
- **Expected:** not addressed by the doc.
- **Found:** `use WithoutModelEvents;` suppresses all model events, so the `deleting` children guard and `HasAuditLog` are both inert while seeding.
- **Risk:** Legitimate for seed performance and noise reduction, but it means seeded data is never validated by the model-layer guards the doc treats as the real protection. Whether that is acceptable is a judgement call.
- **Direction:** Human decision — confirm intentional, and consider whether the delete guard should be exempt.

#### ✅ Rules 2–6 are correctly implemented
- **Location:** [DepartmentRequest.php:115-172](app/Modules/Departments/Requests/DepartmentRequest.php#L115-L172), [UpdateDepartmentRequest.php:63-120](app/Modules/Departments/Requests/UpdateDepartmentRequest.php#L63-L120)
- Child rule (`parent.level + 1` via `next()`), defined-level rule (`Rule::enum`), parent-not-soft-deleted (`Rule::exists(...)->whereNull('deleted_at')` — the doc's exact concern, handled), no self-reference, and no cycles (walks ancestors, O(depth), with a visited set). All messages are in Arabic. This is a faithful, well-reasoned implementation of §3.9 — it is only the root/singleton half that is missing.

---

### F. Deletion guards

#### 🟡 The root is not protected as undeletable
- **Doc reference:** §3.8, §11
- **Location:** absent — [Department.php:103-114](app/Modules/Departments/Models/Department.php#L103-L114) guards children only; [DepartmentService.php:71-74](app/Modules/Departments/Services/DepartmentService.php#L71-L74) delegates straight to `delete()`.
- **Expected:** الإدارة العامة may never be deleted or soft-deleted by any path.
- **Found:** no root check. Currently unreachable-by-accident only because no root exists.
- **Risk:** Deleting the trunk detaches the entire company. Today the children guard incidentally blocks it (a root has children), but that is a side effect, not a guarantee — a leaf root, or an emptied tree, would delete cleanly.
- **Direction:** Add an explicit root check to the model's `deleting` hook alongside the children guard.

#### ✅ Soft-delete children guard is correct and correctly reasoned
- **Location:** [Department.php:86-114](app/Modules/Departments/Models/Department.php#L86-L114), [DepartmentHasChildrenException.php](app/Modules/Departments/Exceptions/DepartmentHasChildrenException.php)
- The guard is in a model `deleting` hook (covers every path, not just the controller), skips on force-delete, relies on the SoftDeletes global scope so emptied subtrees don't block, and throws with an Arabic 409. The migration comment at [create_departments_table.php:71-74](database/migrations/Departments/2026_06_21_100000_create_departments_table.php#L71-L74) states the `restrictOnDelete` caveat accurately. **No code anywhere relies on `restrictOnDelete` to protect soft deletes.** This is §3.8 done properly.

---

### A. `departments` schema

#### 🟡 `code`, `description`, `is_active` columns absent
- **Doc reference:** §3.3
- **Location:** [database/migrations/Departments/2026_06_21_100000_create_departments_table.php:62-110](database/migrations/Departments/2026_06_21_100000_create_departments_table.php#L62-L110)
- **Expected:** `code` (string, unique, nullable), `description` (text, nullable), `is_active` (boolean, default true).
- **Found:** none of the three. Table has `id`, `name`, `parent_id`, `level`, `manager_id`, timestamps, softDeletes.
- **Risk:** Low individually. `is_active` is the one with downstream weight — without it, deactivating a unit without deleting it has no representation, and callers may reach for soft-delete instead, which the children guard will then block.
- **Direction:** Add in a follow-up migration if still wanted; confirm `code` is actually needed.

#### ⚪ `level` has `->default(1)`
- **Doc reference:** §3.3 ("NOT NULL"), §3.5 ("source of truth")
- **Location:** [create_departments_table.php:93](database/migrations/Departments/2026_06_21_100000_create_departments_table.php#L93)
- **Expected:** `unsignedTinyInteger`, NOT NULL, indexed. The doc specifies no default.
- **Found:** `$table->unsignedTinyInteger('level')->default(1)->index();` — NOT NULL and indexed as required, but with a default.
- **Risk:** The default is what silently turned the seeder's four department rows into four level-1 roots instead of failing loudly. A defaulted source-of-truth column converts "you forgot to set the tier" from an error into a wrong-but-plausible value. Arguably ambiguous rather than a violation — a default may be a deliberate convenience.
- **Direction:** Human decision — confirm whether the default is intentional given it masked the seeder bug.

#### ⚪ `manager_id` column is created in migration 1
- **Doc reference:** §8
- **Location:** [create_departments_table.php:102](database/migrations/Departments/2026_06_21_100000_create_departments_table.php#L102)
- **Expected:** §8 states migration 1 "Creates `departments` **without** `manager_id`".
- **Found:** the *column* is created in migration 1 (nullable, no constraint); only the *FK constraint* is deferred to migration 3.
- **Risk:** None mechanically — the circular-FK problem the sequencing exists to solve is fully avoided, since the constraint is what requires the target table to exist. The code's approach is defensible and its docblock explains it clearly. This is a literal-reading conflict, not a real one.
- **Direction:** Human decision — most likely amend §8's wording to say "without the manager FK constraint".

---

### B. Migration sequencing

#### ✅ Sequencing is correct
- **Location:** `database/migrations/Departments/`, `database/migrations/HR/`, `database/migrations/Auth/`
- Timestamps produce the required order: `..._100000_create_departments_table` → `..._110000_create_employees_table` (with `department_id` → `departments`) → `..._120000_add_manager_fk_to_departments`. `parent_id` is correctly inside migration 1 (§8's explicit note). No duplicate `departments` migration. No out-of-order or renamed files. Migrations are organised into module subdirectories (`Auth/`, `HR/`, `Departments/`, `Shared/`) — see §4 below.

#### ✅ No duplicate `notifications` migration
- **Location:** [database/migrations/Auth/2026_06_21_150000_create_notifications_table.php](database/migrations/Auth/2026_06_21_150000_create_notifications_table.php)
- Exactly one `notifications` migration exists; `notifications:table` was not separately run. (Its *shape* is a separate finding — see §7 below.)

---

### G. `employees`

#### 🟡 `employee_number` and `national_id` absent
- **Doc reference:** §4.2
- **Location:** [database/migrations/HR/2026_06_21_110000_create_employees_table.php:27-48](database/migrations/HR/2026_06_21_110000_create_employees_table.php#L27-L48)
- **Expected:** `employee_number` (string, unique — the company staff number), `national_id` (string, nullable).
- **Found:** neither exists. The table has no unique business key at all; identity is `id` plus a nullable, non-unique `email`.
- **Risk:** `employees` is the documented single source of truth for a person's identity (§4.1) and later modules (payroll, attendance) will need the staff number to reconcile against external records. Adding a unique column to a populated table later requires a backfill and a duplicate-resolution pass.
- **Direction:** Add both columns; decide whether `employee_number` is user-supplied or generated.

#### 🟡 `softDeletes` absent — employees can be hard-deleted
- **Doc reference:** §4.2 ("Never hard-deleted — later phases depend on historical rows")
- **Location:** [create_employees_table.php:47](database/migrations/HR/2026_06_21_110000_create_employees_table.php#L47), [app/Modules/HR/Models/Employee.php:29-31](app/Modules/HR/Models/Employee.php#L29-L31)
- **Expected:** `softDeletes()` on the table and the `SoftDeletes` trait on the model.
- **Found:** `timestamps()` only; the model uses `HasAuditLog` but **not** `SoftDeletes`.
- **Risk:** This one is destructive and asymmetric with the rest of the schema. `departments.manager_id` is `nullOnDelete` and `users.employee_id` is `nullOnDelete` — so deleting an employee **succeeds** and silently strips them as manager from every unit they led and detaches their login. The doc's whole manager model (§4.4) depends on `departments.manager_id` pointers surviving; a hard delete erases that fact with no trace and no recovery. `departments` is protected against exactly this; `employees` is not.
- **Direction:** Add `softDeletes()` and the model trait; verify no code path depends on the current hard-delete behaviour.

#### ✅ `department_id`, `status`, and `job_title` are compliant
- **Location:** [create_employees_table.php:34-45](database/migrations/HR/2026_06_21_110000_create_employees_table.php#L34-L45)
- `department_id` is nullable, `nullOnDelete`, and indexed (via the FK). `status` is the correct three-value enum and is indexed. **No `is_manager` column or attribute exists anywhere in the codebase** (§4.5 — verified by grep across `app/`, `database/`, `tests/`, `resources/`). **`job_title` is never used in logic** — it appears only in `$fillable`, a `nullable|string|max:255` validation rule, and the API resource. Both prohibitions honoured.

---

### H. Manager semantics

#### ✅ Manager modelling is fully compliant
- **Location:** [create_departments_table.php:102](database/migrations/Departments/2026_06_21_100000_create_departments_table.php#L102), [add_manager_fk_to_departments.php:29-32](database/migrations/Departments/2026_06_21_120000_add_manager_fk_to_departments.php#L29-L32), [Department.php:144-147](app/Modules/Departments/Models/Department.php#L144-L147)
- `manager_id` is nullable with `nullOnDelete`. **No `unique` constraint** — §4.6 honoured, one person may manage multiple units. The pointer direction is correct: `Department::manager()` is a `BelongsTo`, manager status is derived from the department pointing at the employee, and nothing is stored on `Employee`. No query, relation, or resource assumes a person manages at most one unit — notably, `Employee` deliberately has **no** `managedDepartment()` relation, which is the correct omission.
- **One note:** [DatabaseSeeder.php:102-103](database/seeders/DatabaseSeeder.php#L102-L103) assigns "first employee of each department manages it" via `$first->id`. `$first` would be null for a department seeded with no staff — not currently reachable, but it is an unguarded null dereference.

---

### I. `users`

#### 🟡 `name` column not dropped; still fillable and still written everywhere
- **Doc reference:** §5, §11
- **Location:** [database/migrations/Auth/2026_05_13_122954_create_users_table.php:16](database/migrations/Auth/2026_05_13_122954_create_users_table.php#L16), [User.php:55](app/Modules/Auth/Models/User.php#L55), [UserFactory.php:39](database/factories/UserFactory.php#L39), [UserSeeder.php:30](database/seeders/UserSeeder.php#L30), [DatabaseSeeder.php:111](database/seeders/DatabaseSeeder.php#L111), [DatabaseSeeder.php:124](database/seeders/DatabaseSeeder.php#L124), [DatabaseSeeder.php:136](database/seeders/DatabaseSeeder.php#L136)
- **Expected:** `users.name` dropped; identity resolved via `$user->employee->name`.
- **Found:** the column exists, is in `$fillable`, and is set by the factory and all three seeder call sites.
- **Risk:** Two sources of truth for a person's name (§2 principle #1) — `users.name` and `employees.name` can already diverge today. The doc's §5 migration-side-effect warning is accurate and currently unaddressed: dropping the column breaks `UserFactory` and every seeder simultaneously. The dependency count grows with each new seeder.
- **Direction:** Drop the column and rework the factory/seeders to link an employee — one coordinated change.

#### 🟡 `employee_id` is not unique
- **Doc reference:** §5 ("FK → `employees`, **unique**, nullable. One login per employee, maximum.")
- **Location:** [database/migrations/Auth/2026_06_21_130000_add_employee_id_to_users_table.php:27-31](database/migrations/Auth/2026_06_21_130000_add_employee_id_to_users_table.php#L27-L31)
- **Expected:** unique constraint.
- **Found:** `foreignId('employee_id')->nullable()->after('id')->constrained('employees')->nullOnDelete()` — no `->unique()`.
- **Risk:** Nothing structurally prevents two login accounts pointing at the same employee. [Employee::user()](app/Modules/HR/Models/Employee.php#L69-L72) is a `hasOne`, so the model layer *assumes* the uniqueness the schema does not enforce — it would silently return an arbitrary one of the duplicates. This is §2 principle #2: the schema can make this state impossible and currently doesn't.
- **Direction:** Add the unique constraint after checking for existing duplicates.

#### 🟡 `last_login_at` absent
- **Doc reference:** §5
- **Location:** absent from [create_users_table.php](database/migrations/Auth/2026_05_13_122954_create_users_table.php) and [add_employee_id_to_users_table.php](database/migrations/Auth/2026_06_21_130000_add_employee_id_to_users_table.php)
- **Expected:** `last_login_at` for basic session/security visibility.
- **Found:** not present. `is_active` **is** present (added in `2026_05_13_124310_add_is_active_to_users_table.php`) and correctly cast.
- **Risk:** Low — no security decision currently depends on it.
- **Direction:** Add alongside the other `users` changes.

---

### J. RBAC

#### 🔴 `super_admin` bypass replaced by granting one role every permission
- **Doc reference:** §6.3, §11
- **Location:** [database/seeders/RoleSeeder.php:24-32](database/seeders/RoleSeeder.php#L24-L32); `Gate::before` absent from the entire codebase (grep across `app/`, `database/` returns nothing, including [AppServiceProvider.php](app/Providers/AppServiceProvider.php))
- **Expected:** `super_admin` bypasses checks via `Gate::before`, so new permissions are automatically covered without re-seeding.
- **Found:** a role named `admin` (not `super_admin`) is granted the entire catalogue via `$admin->permissions()->sync(Permission::query()->pluck('id'))`. No `Gate::before` exists.
- **Risk:** This is the §11 prohibition precisely. Every permission added by a future module is invisible to admin until someone remembers to re-run `RoleSeeder` — a failure that is silent, environment-dependent, and shows up as "the admin can't do X in production only". The doc chose `Gate::before` specifically to make that class of bug impossible.
- **Direction:** Introduce the `super_admin` role and a `Gate::before` bypass; decide what happens to the existing `admin` role.

#### 🟡 Permission resolution is not cached
- **Doc reference:** §6.3, §6.4
- **Location:** [User.php:139-144](app/Modules/Auth/Models/User.php#L139-L144) — grep for `Cache::` across `app/Modules/Auth` and `app/Shared` returns nothing.
- **Expected:** permission resolution is cached, with invalidation centralised in one service that owns both writes and busting.
- **Found:** `hasPermission()` runs a `whereHas` query on every call. The docblock is candid: *"Correctness over speed for now — this runs a query per check."*
- **Risk:** Currently **the safe failure mode** — with no cache there is no stale-permission hazard, which is the §6.4 security bug. The real risk is *sequencing*: [RoleService](app/Modules/Auth/Services/RoleService.php) already performs pivot writes (`sync`, `syncWithoutDetaching`, `detach`) at four sites, and [DatabaseSeeder](database/seeders/DatabaseSeeder.php) and [UserSeeder](database/seeders/UserSeeder.php) write pivots directly, bypassing the service entirely. Whoever adds caching later must catch all of them. §6.4 calls a missed invalidation "silent, intermittent, and extremely hard to reproduce" — and the ad-hoc write sites that would cause it **already exist**.
- **Direction:** When caching is added, route every pivot write through the service that owns invalidation — including the seeders.

#### 🟡 `roles` missing `label` and `is_system`
- **Doc reference:** §6.2
- **Location:** [database/migrations/Auth/2026_06_21_140000_create_roles_table.php:19-24](database/migrations/Auth/2026_06_21_140000_create_roles_table.php#L19-L24)
- **Expected:** `name` (unique slug) ✅, `label` (Arabic display name), `description` ✅, `is_system` (boolean, protects built-in roles from deletion/rename via the API).
- **Found:** `name` (unique), `description`, timestamps. No `label`, no `is_system`.
- **Risk:** `is_system` is the load-bearing omission: [RoleService::delete()](app/Modules/Auth/Services/RoleService.php#L103-L113) and `update()` have **no protection** — the `admin` role can be renamed or deleted through the API. Since that role currently *is* the entire super-admin mechanism (see the violation above), deleting it is an unrecoverable lockout with no guard in front of it. These two findings compound.
- **Direction:** Add both columns and enforce `is_system` in `RoleService::update()`/`delete()`.

#### 🔵 `permissions.module` where the doc says `group`; no `label`
- **Doc reference:** §6.2
- **Location:** [database/migrations/Auth/2026_06_21_141000_create_permissions_table.php:24-30](database/migrations/Auth/2026_06_21_141000_create_permissions_table.php#L24-L30)
- **Expected:** `name` (unique dotted key) ✅, `group` (owning module, indexed), `label` (Arabic display text).
- **Found:** `name` (unique), `module` (indexed), `description` (nullable). The column is **named `module`, not `group`** — but it is indexed and serves the doc's stated purpose exactly ("so permissions can be grouped/listed per module in admin UIs without parsing the name string"). `label` is absent; `description` may be filling that role.
- **Risk:** Naming only — the semantics match. `module` is arguably the better name (`group` is a reserved word in MySQL and needs quoting). Flagged for doc review, not code change.
- **Direction:** Human decision — most likely amend §6.2 to `module`; separately decide whether `label` is needed alongside `description`.

#### 🔵 Permission naming convention is 3-part, doc implies 2-part
- **Doc reference:** §6.2
- **Location:** [create_permissions_table.php:11-18](database/migrations/Auth/2026_06_21_141000_create_permissions_table.php#L11-L18), [CheckPermission.php:27-29](app/Shared/Middleware/CheckPermission.php#L27-L29)
- **Expected:** doc examples are `employees.view`, `employees.create` (module.action).
- **Found:** the code documents `{module}.{resource}.{action}` — e.g. `hr.employees.view`.
- **Risk:** None functionally; the 3-part scheme is strictly more expressive and matches the modular layout. But the doc's examples will mislead whoever seeds the next module's permissions.
- **Direction:** Human decision — likely amend §6.2's examples to the 3-part form.

#### 🔵 `CheckPermission` fails open if the user model lacks `hasPermission`
- **Doc reference:** not addressed by the doc
- **Location:** [app/Shared/Middleware/CheckPermission.php:49-58](app/Shared/Middleware/CheckPermission.php#L49-L58)
- **Expected:** doc does not cover the middleware's fallback behaviour.
- **Found:** `if (method_exists($user, 'hasPermission') && ! $user->hasPermission($permission))` — guarded by a stale `TODO: Replace with actual permission check once RBAC module is built`. If the method is ever absent, **every permission check passes**.
- **Risk:** Not currently exploitable — `User::hasPermission()` exists, so checks are enforced. But the fail-open default is a landmine: any future guard, second user model, or refactor that renames the method silently disables authorization system-wide rather than erroring. The comment's premise ("RBAC not yet implemented") is now false.
- **Direction:** Human review — the RBAC layer exists, so the fallback and its TODO can likely be removed.

#### ✅ Spatie absent; pivots exactly per spec
- **Location:** [composer.json](composer.json), [create_role_permissions_table.php](database/migrations/Auth/2026_06_21_142000_create_role_permissions_table.php), [create_user_roles_table.php](database/migrations/Auth/2026_06_21_143000_create_user_roles_table.php)
- **`spatie/laravel-permission` is not installed** (§6.1 honoured). Both pivots use composite primary keys with **no surrogate `id`** and `cascadeOnDelete` on both FKs — §6.2 implemented precisely. `User::roles()` is a `BelongsToMany`, users may hold multiple roles, and `allPermissionNames()` correctly implements the union model with no per-user grants and no deny rules (§6.3).

---

### K. Audit

#### ✅ Audit implementation is compliant on both mandatory rules
- **Location:** [app/Shared/Services/AuditLogService.php:90-111](app/Shared/Services/AuditLogService.php#L90-L111), [create_audit_logs_table.php](database/migrations/Shared/2026_05_13_125646_create_audit_logs_table.php)
- **Rule 1 (never audit `AuditLog` itself):** honoured structurally — `AuditLogService` writes via `DB::table('audit_logs')->insert()`, not Eloquent, so no model events fire and recursion is impossible. There is no `AuditLog` model using `HasAuditLog`. The reasoning is documented at [AuditLogService.php:26-28](app/Shared/Services/AuditLogService.php#L26-L28).
- **Rule 2 (exclude `password`):** honoured and exceeded — `sanitize()` redacts `password`, `password_hash`, `remember_token`, `token`, `refresh_token`, `secret`, `two_factor_secret`, `two_factor_recovery_codes`, and is applied to both `old_values` and `new_values` on every write path. This matters because [HasAuditLog](app/Shared/Traits/HasAuditLog.php#L36-L38) passes raw `$model->getAttributes()` on create and `getOriginal()` on delete — both of which **do** contain the password hash for `User`. The service is the only thing standing between those and the audit table, and it holds.
- **Schema:** `user_id` nullable + `nullOnDelete` ✅; composite `(auditable_type, auditable_id)` index ✅; `old_values`/`new_values` are `json` ✅; `ip_address` uses `$table->ipAddress()` (Laravel emits `varchar(45)`) ✅.

#### 🔵 `audit_logs` has an extra `description` column and extra indexes
- **Doc reference:** §7.1
- **Location:** [create_audit_logs_table.php:55-88](database/migrations/Shared/2026_05_13_125646_create_audit_logs_table.php#L55-L88)
- **Expected:** the doc's column list omits `description` and specifies only the composite polymorphic index.
- **Found:** an added `description` column (used by `logAction()` for non-model events like `role_assigned`), plus indexes on `event` and `created_at`, and `auditable_type`/`auditable_id` are nullable to support action-level events.
- **Risk:** None — this is a superset that supports a capability (action-level auditing: logins, role grants) the doc's model-only design does not anticipate. The doc is likely the incomplete side here.
- **Direction:** Human decision — likely amend §7.1 to cover action-level audit events.

---

### 7 / notifications

#### ⚪ `notifications` is a custom table, not Laravel's standard schema
- **Doc reference:** §7.2
- **Location:** [database/migrations/Auth/2026_06_21_150000_create_notifications_table.php:10-44](database/migrations/Auth/2026_06_21_150000_create_notifications_table.php#L10-L44)
- **Expected:** §7.2 requires Laravel's stock schema (uuid PK, polymorphic `notifiable`, JSON `data`, `read_at`) so the database channel and in-app notification centre work without adaptation.
- **Found:** a bespoke table — `id` (bigint), `user_id` (FK, cascade), `title`, `body`, `type`, `nullableMorphs('reference')`, `is_read` (boolean, indexed). Its docblock states this is a **deliberate** decision — *"this is a CUSTOM notifications table matching the design doc — not Laravel's built-in"* — and there is a matching [Notification model](app/Modules/Auth/Models/Notification.php), [service](app/Modules/Auth/Services/NotificationService.php), [controller](app/Modules/Auth/Controllers/NotificationController.php), [resource](app/Modules/Auth/Resources/NotificationResource.php), and [test](tests/Feature/Auth/NotificationTest.php) built on it.
- **Risk:** **The code cites "the design doc" as its authority for the exact opposite of what §7.2 says.** One of the two documents is stale, and I cannot determine which from the codebase alone. This is not a case of the code drifting — someone made this call deliberately against a written spec. Consequence either way: `$user->notify()` and the standard `database` channel will not work against this schema without an adapter, which is precisely what §7.2 was protecting. Reversing it now means reworking five files and a test.
- **Direction:** Human decision — reconcile §7.2 against whichever design doc the migration is citing.

---

### L. React admin frontend

#### 🟡 No React frontend exists
- **Doc reference:** §1 (stack: "React (Vite, plain JS), Arabic RTL"), §L checklist
- **Location:** [package.json](package.json), [resources/js/](resources/js/) — contains only `app.js` and `bootstrap.js`
- **Expected:** a React admin (Vite, plain JS, Arabic RTL).
- **Found:** **React is not installed.** `package.json` `devDependencies` are `@tailwindcss/vite`, `axios`, `concurrently`, `laravel-vite-plugin`, `tailwindcss`, `vite` — no `react`, no `react-dom`. `resources/js/` holds the two stock Laravel scaffolding files and nothing else. There is no department UI, no components directory, no frontend routing.
- **Risk:** None today, and this is **the clean finding the brief anticipated**. Every 🔴 in the §L checklist is vacuously satisfied: there is no JS-side `DepartmentLevel` mirror, no hardcoded `{1: 'إدارة', 2: 'قسم'}` map, no two-tier picker, no fixed-depth tree, no one-unit-per-manager assumption — because there is no frontend at all.
- **Worth noting positively:** the backend has already done the right thing to *prevent* the §L drift. [DepartmentResource](app/Modules/Departments/Resources/DepartmentResource.php#L46-L47) ships `level_label` alongside `level`, and its docblock states the reason explicitly — *"clients cannot resolve 2 -> 'قسم' on their own without duplicating the tier table in the frontend — which is the same drift the single-source-of-truth `level` column exists to avoid."* The API contract is already shaped to keep the enum server-side. **The one caveat: it is currently shipping the stale two-tier labels.**
- **Direction:** No action. When the frontend is built, consume `level_label` from the API and never redefine tiers client-side.

---

### D. `Department` model / naming

#### 🔴 Controller messages call every department a "قسم" (section)
- **Doc reference:** §3.2, §11
- **Location:** [DepartmentController.php:45](app/Modules/Departments/Controllers/DepartmentController.php#L45), [:55](app/Modules/Departments/Controllers/DepartmentController.php#L55), [:63](app/Modules/Departments/Controllers/DepartmentController.php#L63), [:73](app/Modules/Departments/Controllers/DepartmentController.php#L73), [:81](app/Modules/Departments/Controllers/DepartmentController.php#L81)
- **Expected:** §3.2 — never write code, comments, variable names, or method names that assume `Department` = a particular tier.
- **Found:** every response message names one specific tier: `'تم جلب الأقسام.'` ("the **sections** were fetched"), `'تم إنشاء القسم.'` ("the **section** was created"), `'تم جلب القسم.'`, `'تم تحديث القسم.'`, `'تم حذف القسم.'`. These endpoints return and mutate units at *any* tier — creating a division returns "the section was created".
- **Risk:** This is the §3.2 imprecision leaking into the **user-facing API contract**, which is the hardest layer to correct later. It is also inconsistent within the module: the form requests get this exactly right, using the tier-neutral `'الوحدة التنظيمية'` throughout ([DepartmentRequest.php:90-97](app/Modules/Departments/Requests/DepartmentRequest.php#L90-L97)), as does the delete exception. The controller is the outlier, and it teaches every API consumer the wrong mental model.
- **Direction:** Adopt the form requests' `الوحدة التنظيمية` vocabulary in the controller.

#### ⚪ Tier-specific scopes and the "roots" comment
- **Doc reference:** §3.2, §3.5
- **Location:** [Department.php:150-167](app/Modules/Departments/Models/Department.php#L150-L167)
- **Expected:** tier scopes are wanted (§D checklist); code must not assume `Department` = one tier.
- **Found:** `scopeDivisions()` and `scopeSections()` are tier-specific by design and read `level` correctly — legitimate. But the `scopeDivisions()` docblock reads *"Only divisions (إدارة) — the roots of the tree"*, which hardcodes the stale two-tier assumption that divisions **are** roots. Under the doc's model, divisions are level 2 and never roots.
- **Risk:** Comment-level only, but it is the same stale premise as `DepartmentLevel::root()` — and comments are what the next developer trusts. The generic `scopeAtLevel(DepartmentLevel $level)` is present and is the correct forward-compatible form.
- **Direction:** Resolve together with the enum renumbering.

#### ✅ Model is otherwise compliant and reads `level` correctly
- **Location:** [app/Modules/Departments/Models/Department.php](app/Modules/Departments/Models/Department.php)
- `parent()` `BelongsTo` and `children()` `HasMany` self-relations ✅. `level` cast to `DepartmentLevel` ✅. `SoftDeletes` ✅. Tier scopes present, including the generic `scopeAtLevel()` ✅. **No code anywhere infers tier from `parent_id IS NULL`** — grep found `whereNull('parent_id')` / `parent_id IS NULL` only inside comments *explaining why that inference is forbidden* ([Department.php:37](app/Modules/Departments/Models/Department.php#L37), [create_departments_table.php:86](database/migrations/Departments/2026_06_21_100000_create_departments_table.php#L86)). The §3.2 naming rule is explicitly restated in the model docblock. §3.5 is well understood by this code.

---

## 4. Documentation gaps (🔵)

Things the code does that the doc does not address. **Recommendations only — these need a human decision, and in each case the doc may be the side that is wrong.**

| # | Code does | Doc says | Recommendation |
|---|---|---|---|
| 1 | **Modular architecture.** All code lives under `app/Modules/{Auth,HR,Departments}/` with per-module `routes.php`, auto-discovered by a [ModuleServiceProvider](app/Providers/ModuleServiceProvider.php). Migrations are in module subdirectories. The enum is `App\Modules\Departments\Enums\DepartmentLevel`. | §3.6 names it `App\Enums\DepartmentLevel`; the doc never mentions modules and implies a stock Laravel layout. | **Amend the doc.** The modular layout is coherent, consistently applied, well-documented, and clearly deliberate. The doc simply predates it. Every `App\...` path in the doc needs updating — §3.6 most concretely. |
| 2 | **`employees` has an `Employee` model in an `HR` module**, and the doc's `employees` table is owned by HR rather than a top-level concern. | §4 treats `employees` as a Phase 1 foundation table with no module owner. | **Amend the doc.** Note that HR owns the people; Departments owns the tree. The cross-module relation is already documented at [Department.php:57-59](app/Modules/Departments/Models/Department.php#L57-L59). |
| 3 | **Extra `employees` columns:** `address`, `salary` (`decimal(12,2)`, default 0). | §4.2's column list omits both. | **Amend the doc**, but flag `salary`: it is the most sensitive column in Phase 1, it is in `$fillable`, and it is exposed to whoever can read an employee. It is correctly audited via `HasAuditLog`, but the doc should state who may read it. Worth a deliberate decision, not a silent addition. |
| 4 | **`refresh_tokens` table + [RefreshToken model](app/Modules/Auth/Models/RefreshToken.php) + [prune command](app/Console/Commands/PruneExpiredRefreshTokens.php)**; `password_reset_tokens` and `sessions` tables. | §1 says "Auth | Sanctum (token)" and nothing about refresh tokens. | **Amend the doc.** A refresh-token flow is a real architectural decision with a table, a model, a scheduled prune, and tests — it deserves a section rather than living only in code. |
| 5 | **[AuditLogMiddleware](app/Shared/Middleware/AuditLogMiddleware.php)** and `AuditLogService::logAction()` for non-model events. | §7.1 describes audit as model-event-driven via a trait only. | **Amend the doc** (see the `audit_logs.description` finding above). Action-level auditing is a genuine capability the doc's model-only framing misses. |

---

## 5. Open questions for the human

1. **Which document is authoritative for `notifications`?** The migration's docblock says it is custom *"matching the design doc"*; `erp-phase1-architecture.md` §7.2 requires Laravel's stock schema. These cannot both be current. **This blocks any decision on the notifications module** — five files and a test hang on the answer.
2. **How should the `DepartmentLevel` renumbering be executed?** Inserting `GeneralAdministration = 1` shifts `Division` to 2 and `Section` to 3 — the upward-growth data migration §3.6 explicitly warns is unavoidable. I need to know whether production or shared-dev data exists before this can be scoped. The blast radius is the enum, `root()`, both tier scopes and their comments, `DatabaseSeeder`, and the ~411-line `DepartmentHierarchyTest` which encodes the two-tier model as its specification. **The cost only grows.**
3. **Is the `admin` role meant to become `super_admin`, or coexist with it?** §6.3 specifies a `super_admin` + `Gate::before` bypass; the code has an `admin` role holding every permission. Renaming, replacing, or adding alongside are three different migrations with different effects on already-seeded environments.
4. **§9.1 (must a manager belong to the unit they manage?) is still unratified** and remains unimplemented — `manager_id` accepts any employee id. Worth noting that [DepartmentRequest.php:80](app/Modules/Departments/Requests/DepartmentRequest.php#L80) validates `manager_id` as `['nullable', 'integer']` with **no `exists:employees,id` rule** — the comment says it was deferred until the employees table existed. That table now exists, so the rule can be added independently of §9.1's outcome. Flagging as ambiguous rather than a finding, since it may be an intentional wait on §9.1.
5. **Is `permissions.module` vs the doc's `group` a rename to ratify?** Recommend ratifying `module` — it matches the modular layout and avoids a MySQL reserved word. Cheap to confirm, and it unblocks seeding the next module's permissions.
6. **Was `WithoutModelEvents` on `DatabaseSeeder` deliberate?** It disables the delete guard and the audit trail during seeding, meaning seeded rows never pass the model-layer guards the doc treats as the real protection (§3.8).
7. **Confirm `level`'s `->default(1)` is intentional.** It is what let the seeder create four level-1 roots silently instead of erroring. A defaulted source-of-truth column turns a forgotten tier into a wrong-but-plausible value.

---

## Appendix — files opened

Every finding above is anchored to a file I read in full. Files opened for this audit:

**Migrations:** `Departments/2026_06_21_100000_create_departments_table.php`, `Departments/2026_06_21_120000_add_manager_fk_to_departments.php`, `HR/2026_06_21_110000_create_employees_table.php`, `Auth/2026_05_13_122954_create_users_table.php`, `Auth/2026_06_21_130000_add_employee_id_to_users_table.php`, `Auth/2026_06_21_140000_create_roles_table.php`, `Auth/2026_06_21_141000_create_permissions_table.php`, `Auth/2026_06_21_142000_create_role_permissions_table.php`, `Auth/2026_06_21_143000_create_user_roles_table.php`, `Auth/2026_06_21_150000_create_notifications_table.php`, `Shared/2026_05_13_125646_create_audit_logs_table.php`

**App:** `Departments/Enums/DepartmentLevel.php`, `Departments/Models/Department.php`, `Departments/Requests/DepartmentRequest.php`, `Departments/Requests/StoreDepartmentRequest.php`, `Departments/Requests/UpdateDepartmentRequest.php`, `Departments/Controllers/DepartmentController.php`, `Departments/Resources/DepartmentResource.php`, `Departments/Services/DepartmentService.php`, `Departments/Exceptions/DepartmentHasChildrenException.php`, `HR/Models/Employee.php`, `Auth/Models/User.php`, `Auth/Services/RoleService.php`, `Shared/Traits/HasAuditLog.php`, `Shared/Services/AuditLogService.php`, `Shared/Middleware/CheckPermission.php`

**Database:** `seeders/DatabaseSeeder.php`, `seeders/UserSeeder.php`, `seeders/RoleSeeder.php`, `factories/UserFactory.php`

**Other:** `composer.json` (grepped for `spatie`), `package.json`, `resources/js/` (listed), `tests/Feature/Departments/DepartmentHierarchyTest.php` (first 40 lines + line count — **not read in full**)

**Not opened** (no findings claimed against them): `PermissionSeeder.php`, `Auth/Models/Role.php`, `Auth/Models/Permission.php`, `Auth/Models/Notification.php`, `Auth/Services/{AuthService,UserService,NotificationService}.php`, all Auth controllers/requests/resources, `HR/{Services,Controllers,Requests,Resources}`, `ModuleServiceProvider.php`, `AppServiceProvider.php` (grepped only, for `Gate::before`), all module `routes.php`, and the remaining test files. **Statements about `Gate::before`, `is_manager`, `job_title`, `whereNull('parent_id')`, `spatie`, and `Cache::` rest on repo-wide greps rather than full reads of those files.**
