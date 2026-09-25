# GAP-3 — row-level authorization: the work order

> ✅ **BUILT 2026-09-24.** Everything in §3 exists and §6 holds; 404 tests pass,
> 42 more than the 362 this started from: 27 in the first pass, 15 added fixing
> what review found (§8, "Found by review"). GAP-3 is in the **Closed** register
> of `API_BUILD_STANDARD.md` §12,
> which now has no open entries. What was decided while building — including the
> two traps in §4 that asked for a decision — is in **§8** at the bottom. Nothing
> was committed (§7 still stands).

> **Read this first, then `START_HERE.md` §9 decisions 14, 18 and 19, then
> `API_BUILD_STANDARD.md` §12 (GAP-3).** This file is the build plan; those two
> are the ratified decisions it implements. Written 2026-09-24 as the handoff
> into a fresh session, so nothing below depends on remembering a conversation.
>
> **State of the tree when this was written:** 362 tests passing, Pint clean on
> every file the project owns (`config/auth.php` and `config/sanctum.php` fail
> and predate all of this work). Nothing is committed — see §7.

---

## 1. What GAP-3 is

`API_BUILD_STANDARD.md` §12: **no row-level authorization exists anywhere.**
Permissions are verb-level only. `hr.employees.view` reads *every* employee;
`departments.update` edits *any* unit. No `Policy` class exists and every
`FormRequest::authorize()` returns bare `true`.

It is the last open gap in the register, and the most security-significant thing
left in the project.

## 2. What is already decided — do not re-open

| # | Decision | Date |
|---|---|---|
| **14** | A manager's scope is **the whole subtree** — every section and office beneath their unit, and all their staff. Not the unit row alone, not direct reports only | 2026-09-23 |
| **18** | Scope lives on the **role assignment**, and is one of: the whole company · a named unit · **«الوحدات التي أديرها»**, derived from `departments.manager_id` | 2026-09-23 |
| **19** | An out-of-scope row asked for directly answers **403 with a clear message**, not 404 | 2026-09-23 |

⚠️ **Why "derive scope from the tree alone" is wrong, in case it looks obvious
again:** it inverts the intent. A user who manages no unit would have no scope
and therefore see *everything*, while a division head would see only their
branch — an ordinary clerk ending up with wider access than the manager above
them. Scope has to be something a grant **says**; the tree is then one of the
things it can say.

⚙️ **Decision 19's cost is accepted knowingly:** a 403 confirms the row exists,
so someone walking ids learns the headcount and which ids are real. A 404 would
not. Judged worth it for an internal ERP behind authentication.

## 3. The build, in order

Four pieces. Each should leave the suite green on its own.

### 3.1 The scope on the grant (migration + model)

`user_roles` is today a bare pivot: `user_id`, `role_id`, `primary(user_id,
role_id)`. It needs to carry a scope.

- `scope_type` — company · unit · managed (an int-backed enum, same convention as
  every other enum here: `DepartmentLevel`, `ActivityType`, …).
- `scope_department_id` — nullable FK, **required exactly when** `scope_type` is
  `unit`, and NULL otherwise. Enforce that in a model hook via a
  `Support/` guard, not only in a FormRequest — the seeders and the importer write
  around FormRequests, and this project has already paid for that lesson once
  (tier rules in `DepartmentRequest` let a seeder create four roots).
- ⚠️ **The composite primary key has to change.** The same role scoped to two
  different units is a legitimate grant — a supervisor over two sections — and
  `primary(user_id, role_id)` forbids it. Decide between widening the key to
  include the scope columns or adding a surrogate `id`; a surrogate is likelier
  to be right, because a nullable column in a primary key is a trap.
- `RoleService::assign/unassign` take the scope. Their current signatures take
  ids only, which is the right shape to extend.

### 3.2 The subtree primitive (Departments)

"Every unit at or beneath these units." `Department` already has the **upward**
walk — `ancestorRelationPath()`, `scopeWithAncestors()`, `loadAncestors()`, all
deriving their depth from `count(DepartmentLevel::cases())` so a new tier cannot
leave them one hop short. **Build the downward twin the same way**, and derive the
bound from the same enum rather than writing a number.

A bounded iterative query (`WHERE parent_id IN (…)`, once per tier) is portable
across MySQL and the SQLite test driver and needs no recursive CTE. Depth is 4.

### 3.3 The scope resolver (Shared)

One service that answers, for a user: **"all rows" or "these department ids".**

- `company` → all.
- `unit` → the subtree of `scope_department_id`.
- `managed` → the subtree of every unit where `departments.manager_id` is this
  user's employee — the tree stays the single source of who manages what (P1),
  which is the whole reason this option exists.
- Several grants → the **union**.
- `super_admin` → all, via the existing `Gate::before` / `hasPermission()`
  bypass. **It must keep bypassing.** A scoped super admin is a locked-out
  administrator.

⚠️ **This belongs in the permission cache, and the cache key must change with
it.** `PermissionCache` stamps every key with a global `rbac:version` and caches
`names($user)` for an hour. A user's scope is part of their RBAC state, so it
caches the same way — and a scope change must bump the same version counter, or
a manager keeps the reach they had for up to an hour after it is revoked.

### 3.4 The enforcement (Policies + query scoping)

`API_BUILD_STANDARD.md` §5 decision table: *"May this user touch this row?" →
`Policies/`*. That is where this goes; it is the one folder the project has been
holding open for it.

- **Lists filter.** A scoped caller's `index` returns only in-scope rows.
  ⚠️ **The pagination count query must be scoped too**, or `meta.total` reports a
  number the caller cannot page to — the `PaginationContractTest` contract is
  that paging returns every row exactly once.
- **Detail, update, delete → 403** (decision 19), with an Arabic message, through
  a `DomainException` so `ApiExceptionRenderer` renders it like everything else.
- **Create** — an employee created into a unit outside the caller's scope is the
  same refusal.

## 4. Traps worth knowing before you start

1. **Employees with `department_id = null`.** They are in nobody's subtree. The
   safe default is that only a company-scoped caller sees them, but it is a
   product question and the import will produce some (a blank `أسماء الإدارات`
   is a known data problem the importer must report). **Ask before inventing.**
2. **Every existing HR test uses `actingAsAdmin()`**, a super admin, so they will
   all keep passing whatever you do. That is not coverage. New tests must use
   `actingAsUserWithPermissions()` with a scoped grant — see
   `EmployeeWriteGateTest` for a test class built entirely around actors who hold
   exactly what they say.
3. **The `manager` embed can name someone outside the scope.** A section head's
   own manager sits one level up, outside their subtree, and `EmployeeStub` is
   deliberately ungated. Leaking a name and a staff number from the org chart is
   almost certainly fine — but decide it on purpose and write it down, rather
   than discovering it in review.
4. **Concurrency guarantees are real only on MySQL.** The shared lock and cache
   store are no-ops or process-local under SQLite, which is what the tests use.
   Anything that must hold under concurrency needs saying so in a docblock.
5. **Scope is not the same question as field gating.** `hr.employees.viewSalary`
   (GAP-12/C11) decides *which fields*; this decides *which rows*. The two
   compose and neither replaces the other.

## 5. Explicitly NOT in the first slice

- **Audit log scoping.** `auth.audit.view` still reads the whole trail. It has
  its own per-type gating (see GAP-13) and scoping it is a separate decision.
- **Departments and job titles.** Start with `employees` — it is where the
  sensitive data is and where لوحة المدير needs it. The others follow the same
  policy shape once it exists.
- **The approvals engine.** Stage 2. It will consume `ManagementChain`, not this.

## 6. Done looks like

- A department head with a `managed` grant lists only their subtree, and
  `meta.total` agrees with what they can page through.
- The same head asking for an employee in another division gets a 403 with an
  Arabic message.
- An HR administrator with a `company` grant sees everything, unchanged.
- A super admin is unaffected.
- Revoking a scope takes effect immediately, not in an hour.
- GAP-3 moves to the **Closed** register in `API_BUILD_STANDARD.md` §12, and the
  register has no open entries left.

## 7. One thing to settle before or after, but not to forget

**Nothing in this repository is committed.** The working tree currently holds
Phase 1.5 Batch 2, سجل التدقيق, the account model, GAP-12, GAP-13,
`ManagementChain`, and the removals of `legacy_number` and `direct_manager_id` —
all uncommitted, all green. GAP-3 will add a migration and a policy layer on top
of that.

That is a lot of work with no checkpoint behind it. Committing is the project
owner's call and nothing here will do it unasked — but starting GAP-3 on top of
an uncommitted tree of this size is worth a deliberate decision rather than an
accident.

## 8. What was decided while building (2026-09-24)

Recorded so none of it is rediscovered in review.

| Question | Answer, and why |
|---|---|
| **Surrogate id or wider key?** (§3.1) | **Surrogate `id`**, plus `unique(user_id, role_id, scope_type, scope_department_id)`. The unique index cannot catch two identical *company* grants (NULL ≠ NULL), so `RoleService::assign` checks under a lock on the user row — real on MySQL, a no-op on SQLite |
| **What do existing grants become?** | **Company.** That is the reach every grant had the day before, so deploying the migration changes nobody's access. Narrowing is a deliberate act afterwards |
| **Assign without a scope?** | **Company**, so the frontend written against the old contract keeps working. **Unassign without a scope** revokes *every* grant of that role — the old meaning of "take this role away" |
| **Union across which grants?** (§3.3) | **Only the grants whose role carries the permission in use.** A plain union across all grants would let a company-wide *view* grant widen a section-scoped *update* grant. `test_the_scope_consulted_is_the_one_on_the_grant_carrying_the_verb` pins it |
| **What is cached?** (§3.3) | **The grants** (type + unit + permissions) in `PermissionCache`, under the same version stamp. **Not** the resolved department ids: the tree and `manager_id` are read fresh, so moving a manager or re-parenting a section takes effect with no flush. The cache key suffix changed (`:perms` → `:rbac`) so entries from the previous release are orphaned, not read |
| **Trap 1: employees with no unit** | **Visible only to a company-wide grant**, and a scoped caller cannot create one or move someone into "no unit". This is the fail-closed default §4 named; ⚠️ **it is still the project owner's call** — if the business wants unit-less rows routed somewhere, the answer is to give them a unit, not to widen the scope |
| **Trap 3: the `manager` embed** | **Not scoped, on purpose.** A section head seeing who their own manager is — a name and a staff number from the org chart — is not a leak, and hiding it would break the app's home screen. Written into `EmployeePolicy`'s docblock |
| **Soft-deleted units** | **Walked.** An office can be soft-deleted with staff still in it; they stay in their division head's subtree rather than disappearing from everyone but a company-wide grant |
| **A departed manager** | A `manager_id` pointing at a soft-deleted employee **manages nothing** — the same reading `ManagementChain` gives it (a vacant post) |
| **Nested routes** | **Scoped too.** `employees/{id}/assignments`, `employees/{id}/warnings` and `warnings/{id}` follow the employee; leaving them open would have been a way around the 403 on the employee itself |
| **Destination checks** | Create, a transfer through update, and a recorded posting all check the **unit the person lands in**, after validation (so a malformed id is a 422, not a 403) |
| **How an out-of-scope row is refused** | A `false` renders the generic "no permission" 403, the same words as a missing verb. Decision 19 asks for a message that says *out of scope*, so the abilities return `OutOfScope::deny($message)` — a denial carrying the code `out_of_scope`, which `ApiExceptionRenderer` recognises and whose message it shows. It is still an answer: `Gate::allows()` and `$user->can()` return false. (The first pass threw `OutOfScopeException` instead, which made every Gate question an exception; replaced after the second review) |

### Found by review, and fixed the same day

| Finding | Fix |
|---|---|
| **Field gates were scope-blind** — trap 5 in §4 said the two compose; the first pass did not make them. `EmployeeResource` and `EmployeeRequest` asked `hasPermission()`, so a section-scoped `viewSalary` beside a company-wide `view` read every employee's pay, and could write it wherever `update` reached | Per row. `RequestRowScopes` resolves each field permission's scope **once per request** (memoized on the base `request()`, which everyone passes — a FormRequest is a copy with its own attribute bag) and the Resource checks `covers()` per row. A write must reach every unit the row sits in before **and** after, so pay cannot be carried across the edge of the grant by a transfer. `EmployeeScopedFieldsTest` |
| **The same hole in the audit trail** (not in the review; found fixing it) — `viewValues` + a section-scoped `viewSalary` read the whole company's pay history | The trail is not row-scoped, so each module permission in `TYPE_VIEW_PERMISSIONS` must now be held **company-wide** (`AccessScope::reachesEverything()`, cache-only). Stricter for every type, not only employees — fail closed until the trail itself is scoped |
| **`Role::users()` bypassed the scope guard** | `->using(UserRole::class)` on both sides. The only role join in use (`NotificationService`) is a `whereHas`, so a user holding a role over two units is still notified once |
| **Staff of a deleted unit lost their manager** — `ManagementChain` started from `$employee->department`, which hides trashed units | The walk loads the unit and its ancestors `withTrashed()` independently of that relation, and skips a trashed unit's `manager_id` (a dissolved unit holds no post), climbing to the live manager above |
| **`direct_manager_id` / `legacy_number` survive on upgraded databases** — both were removed by editing an already-run migration | `2026_09_24_110000_drop_retired_columns_from_employees_table`: conditional, a no-op on fresh installs; `down()` deliberately does not recreate them |

### Found by the second review (2026-09-25), and fixed

| Finding | Fix |
|---|---|
| **The assignments endpoint wrote a pay axis unchecked.** `organizational_grade` is gated by `viewSalary` on `PUT /employees`, but `POST …/assignments` accepted it and the posting copied it onto the employee | `EmployeeAssignmentRequest` applies the same per-row rule: the field must be absent unless the `viewSalary` scope reaches the current unit and any named destination. `test_a_posting_cannot_set_the_grade_outside_the_field_grant` |
| **A narrowed `super_admin` grant was an unlimited bypass.** The resolver matched the role by name and ignored the scope, while the API reported the grant as limited | `GrantScopeGuard` refuses `super_admin` with any scope but company (model hook and a 422 from `AssignRoleRequest`); `PermissionCache` counts only a **company** `super_admin` grant as the bypass, so a row written around the guard reaches nothing |
| **The assigner's own scope bounded nothing.** A unit-scoped `auth.roles.update` holder could grant anyone, themselves included, a company-wide role | `RoleService::ensureWithinReach()`: the grant must lie within the caller's `auth.roles.update` reach (company → company-wide callers only; unit → inside it; managed → every unit the grantee manages today). Revoking is bounded the same way. 403 `GrantOutOfReachException` |
| **A grant on a soft-deleted unit could not be revoked on its own** — unassign required a live unit | `UnassignRoleRequest` accepts a trashed unit; a new grant still needs a live one |
| **An undefined `scope_type` in the table was a 500 on every request for that user** — the enum cast threw while resolving RBAC | `UserRole::scope_type` reads leniently (`tryFrom`, null when undefined); `PermissionCache` drops such a grant, so it reaches nothing |
| **`roles` in the login and `/me` payload listed a role once per grant** | `->unique()`, as `UserResource` already had |
| **`refresh()` on an employee that had been through `ManagementChain` threw** — the manager was stored with `setRelation()` and no relation method behind it | Held in a plain property: `Employee::setResolvedManager()` / `resolvedManager()` |
| **Policies and the field gate re-walked the tree** — each resolved its scope directly, so one PATCH walked it ~5 times | Everything reads through `RequestRowScopes` on `request()` (`forUser()` for policies, which resolves fresh when asked about someone other than the caller). `test_a_transfer_resolves_each_scope_once` pins one lookup per permission |

**Not verified:** the migrations were exercised against SQLite — `user_roles`
up and down with existing grants, the retired-columns drop against a hand-built
upgraded schema — but **not against MySQL**. Run both on a copy of the real
database before production.
