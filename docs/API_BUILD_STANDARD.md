# Al-Raqi ERP — API Build Standard

> **The binding standard for building any feature in this backend.**
> Read this before writing the first line of a new endpoint, module, or resource.
>
> This is the deep-dive that `START_HERE.md` §13 summarises. Where §13 tells you
> *what* the conventions are, this file tells you *what is required, in what
> order, and what a reviewer will reject.*
>
> **Status of the rules below:** they are **binding**, not aspirational. Where the
> current codebase does not yet comply, that is recorded explicitly in
> [§12 Gap register](#12-gap-register) — with the fix. A gap is never a licence to
> copy the non-compliant pattern into new code.
>
> **Last verified against the code:** 2026-09-08, after the compliance pass.
> Verified by reading every file in `app/`, `php artisan route:list`, and an
> empirical probe of every error path and list endpoint. Sections marked ⚙️ state
> facts about the code and go stale if ignored.
>
> **The pass closed ten of the twelve gaps in [§12](#12-gap-register) and found
> nine more, which are also closed. Two further reviews then found six defects
> in the fixes themselves and five standards violations the pass had walked
> past — all closed.** The suite is 197 tests / 1050 assertions, up from 143 / 644 — the new ones are regression guards, since every gap that
> existed had survived only because nothing asserted against it.

---

## Table of contents

1. [How to use this document](#1-how-to-use-this-document)
2. [The request lifecycle — the spine](#2-the-request-lifecycle--the-spine)
3. [Layer contracts — what each layer may and may not do](#3-layer-contracts--what-each-layer-may-and-may-not-do)
4. [Constraints — the numbered rules](#4-constraints--the-numbered-rules)
5. [Folder structure](#5-folder-structure)
6. [API format — the wire contract](#6-api-format--the-wire-contract)
7. [API structure — naming and permissions](#7-api-structure--naming-and-permissions)
8. [The build workflow — step by step](#8-the-build-workflow--step-by-step)
9. [Worked example — a feature end to end](#9-worked-example--a-feature-end-to-end)
10. [Testing standard](#10-testing-standard)
11. [Review checklist](#11-review-checklist)
12. [Gap register](#12-gap-register)
13. [Anti-patterns, with real examples](#13-anti-patterns-with-real-examples)

---

## 1. How to use this document

**Building a feature?** Read §2 and §3 once, then follow §8 as a procedure and
§11 as your self-review before you open a PR.

**Reviewing a PR?** §11 is the gate. Cite rules by number — "this breaks **C7**"
is a complete review comment.

**Are you an AI agent working here?** This file supersedes
`AI_AGENT_BASE_PROMPT.md`, which is stale in ways that will make you build
insecure code — it now carries a banner saying so. Follow this file.

### The four principles this standard serves

Every rule below is one of the four project principles applied to the API layer.
They are stated in full in `START_HERE.md` §5.2. In one line each:

| | Principle | In the API layer |
|---|---|---|
| **P1** | One fact, one place | One envelope. One permission name. One place a rule is enforced. |
| **P2** | Structure over discipline | Enforce at the layer *every* writer crosses — not at the layer you happen to be editing. |
| **P3** | Explicit over implicit | A forgotten permission, status code, or scope must fail loudly, not default to permissive. |
| **P4** | Data changes, not code | Adding a permission or a tier is a seed row or an enum case, not a redeploy of logic. |

**P2 is the load-bearing one.** If you remember one thing: *a rule that lives only
in a FormRequest protects only HTTP.* The seeder, tinker, a queued job, and a
future module all write around it. That exact mistake once produced four roots in
the org tree.

---

## 2. The request lifecycle — the spine

Every request travels these eight stages **in this order**. A stage may be empty
for a given route; it may never run out of order, and no stage may do another
stage's work.

```
┌─ 1. Global transport ────────────────────────────────────────────┐
│    request id · rate limit · body size · early rejection         │
└──────────────────────────────┬───────────────────────────────────┘
                               ▼
┌─ 2. Route resolution ────────────────────────────────────────────┐
│    ModuleServiceProvider → /api/{module} · SubstituteBindings    │
└──────────────────────────────┬───────────────────────────────────┘
                               ▼
┌─ 3. Identity resolution ─────────────────────────────────────────┐
│    auth:sanctum → who is calling?            401 if nobody       │
└──────────────────────────────┬───────────────────────────────────┘
                               ▼
┌─ 4. Authorization ───────────────────────────────────────────────┐
│    4a  permission:{name}   may they do this VERB?     403        │
│    4b  policy / scope      may they touch THIS ROW?   403/404    │
└──────────────────────────────┬───────────────────────────────────┘
                               ▼
┌─ 5. Input adaptation ────────────────────────────────────────────┐
│    FormRequest → validated() array           422                 │
│    the HTTP request STOPS HERE. Nothing below sees it.           │
└──────────────────────────────┬───────────────────────────────────┘
                               ▼
┌─ 6. Handler (Controller) ────────────────────────────────────────┐
│    call one service · wrap in a Resource · return an envelope    │
└──────────────────────────────┬───────────────────────────────────┘
                               ▼
┌─ 7. Use case (Service) + invariants (Model) ─────────────────────┐
│    business logic · transactions · locks · audit                 │
│    model hooks enforce what every writer must obey               │
└──────────────────────────────┬───────────────────────────────────┘
                               ▼
┌─ 8. Response normalization ──────────────────────────────────────┐
│    success → ApiRespond · error → central exception mapper       │
│    ONE envelope, ONE place. No layer above formats its own JSON. │
└──────────────────────────────────────────────────────────────────┘
```

### The two rules that make the diagram real

**Stage 5 is the transport boundary.** `Illuminate\Http\Request` must not exist
below it. A service receives scalars, arrays, and models — never a request. This
is what makes business logic unit-testable without HTTP, reusable from a console
command or a queued job, and portable if the framework ever changes.

**Stage 8 is the only place a response is shaped.** Not the controller, not the
middleware, not an exception class. One envelope, produced in one place.

⚙️ The order above is **verified as executing** — `php artisan route:list` shows
`api → auth:sanctum → audit → permission:{name}` on every protected route, and
the `api` group resolves to `throttle:api → SubstituteBindings`, with
`SetRequestContext` registered as GLOBAL middleware ahead of routing (so an
unmatched path still gets a correlation id), and `throttle:api` sorted AHEAD of
`auth:sanctum` via an explicit priority list — otherwise unauthenticated traffic
401s before the limiter ever runs.

Stage 1 is real: a request id, a 60/min baseline limit, and tighter named
limiters on login and refresh. Stage 8 is real: one mapper, one envelope.
**Stage 4b still does not exist (GAP-3)** — authorization is verb-level only,
and the field-level gates in `EmployeeResource` are a mitigation of its
consequences, not a substitute for it.

---

## 3. Layer contracts — what each layer may and may not do

This table is the fastest way to answer "where does this code go?".

| Layer | MUST | MAY | MUST NOT |
|---|---|---|---|
| **routes.php** | Declare `auth:sanctum`, `audit`, and a `permission:` for every route | Group by prefix and name | Contain any logic, closure handlers, or an unguarded write route |
| **Middleware** (`app/Shared/Middleware/`) | Be cross-cutting and module-agnostic | Reject early with a status code | Contain domain knowledge, or hand-build a JSON envelope |
| **FormRequest** | Validate shape and field rules; carry the Arabic messages | Call a shared domain guard and surface its violations as field errors | Contain business rules that exist *only* here (P2), or perform writes |
| **Controller** | Call exactly one service method; wrap in a Resource; return via `ApiRespond` | Read `$request->user()` to pass identity down | Query models, open transactions, decide authorization, format errors, or hold `if` branches on business state |
| **Service** | Own business logic, transactions, locks, and audit calls | Accept models, scalars, arrays, and a context object | Accept or touch `Illuminate\Http\Request`; return a `JsonResponse`; know a status code |
| **Model** | Enforce invariants in `saving`/`creating`/`deleting` hooks (P2) | Expose scopes, casts, accessors, relations | Contain HTTP knowledge, or format output |
| **Support guard** (`Support/`) | Be pure: primitives in, violations out; framework-free | Be called from *both* a FormRequest and a model hook | Depend on HTTP, or on a specific caller |
| **Resource** | Shape the public JSON contract; gate sensitive fields | Use `whenLoaded` to avoid N+1 | Perform queries or trigger lazy loads |
| **Exception** | Carry a domain meaning and a message | Carry a status code as data | Build its own `response()->json(...)` — see §13 |

---

## 4. Constraints — the numbered rules

Cite these in review by number.

### Layering

- **C1** — A `Service` must never accept, import, or reach for
  `Illuminate\Http\Request` (including the `Request` facade and the `request()`
  helper). Identity, IP, and user agent are **passed in**, never captured.
- **C2** — A `Controller` action is at most ~10 lines: one service call, one
  Resource, one envelope. If you need a second `if`, the logic belongs in the
  service.
- **C3** — A `Controller` must not **retrieve** a model. No `Model::find()`,
  `::findOrFail()`, `::where()`, no query builder. Use route-model binding, or
  pass an id to the service and let it do the lookup.

  ⚙️ **Eager-loading a model the controller already holds is permitted** —
  `$department->load('parent')` in a `show()`, or loading `roles.permissions`
  before wrapping a user the service returned. This is not a loophole and it is
  worth stating, because the two read alike: *retrieval* is deciding **which
  row** to act on, which is the service's job; *eager-loading* is declaring
  **which relations the Resource will render**, which is the response layer's
  job and belongs where the Resource is chosen. §9's worked example does
  exactly this. The test is whether removing the call changes which row you get
  (a C3 violation) or only how many queries it takes (not one).

  The controller is the **last** place this may happen. A Resource that
  dereferences an unloaded relation is a violation of the Resource contract, no
  matter how convenient — so whoever picks the Resource owes it the relations it
  reads.
- **C4** — Any rule that must hold for **every** writer (not just HTTP) lives in a
  model hook or a shared guard, never only in a FormRequest. **(P2)**
- **C5** — A shared guard (`Support/`) takes primitives and returns violations. It
  imports nothing from HTTP.

### Authorization & security

- **C6** — Every route carries `auth:sanctum` unless it is deliberately public.
  A public route must say why in a comment above it.
- **C7** — Every route carries a `permission:{name}` **or** a one-line comment
  stating the authorization model instead (e.g. owner-scoped). No route is
  silently unguarded.
- **C8** — Reads require `.view`; each write requires its own `.create` /
  `.update` / `.delete`. A read-only role must never be able to mutate.
- **C9** — Authentication and authorization are separate stages with separate
  status codes: **401** = we do not know who you are; **403** = we know, and no.
  Never conflate them.
- **C10** — An authorization decision must not live inside a controller action.
  Verb-level → route middleware. Row-level → a policy or a service scope.
- **C11** — Sensitive fields (salary, national id, account numbers, and anything
  that *implies* them) are gated in the **Resource** by an explicit permission
  check, not by omission-by-habit.
- **C12** — Never expose an exception class, file path, stack trace, SQL, or a
  fully-qualified model name to a client — in any environment. Enforced by
  `ApiExceptionRenderer`; asserted by `ApiEnvelopeTest`.
- **C13** — Every state-changing endpoint is audited. Model changes via
  `HasAuditLog`; non-model actions via `AuditLogService::logAction()`.
- **C14** — New secrets added to any model go into the redaction list in
  `AuditLogService::sanitize()` in the same commit.

### Response

- **C15** — Every response — success **and** error — carries the standard
  envelope with a `success` boolean. No exceptions — framework-generated errors
  included.
- **C16** — Only `ApiRespond` builds an envelope. Never hand-write
  `response()->json(['success' => ...])`.
- **C17** — Errors are mapped to HTTP centrally in `bootstrap/app.php`. A domain
  exception carries meaning and a status code as *data*; it does not render.
- **C18** — Never return a raw Eloquent model or collection. Always a Resource.
- **C19** — Do not use `204 No Content`. Symfony strips the body, so the Arabic
  `message` never reaches the client. Return `200` with a message and no `data`.
- **C20** — All client-facing messages are Arabic and display-ready — a toast can
  show them verbatim.
- **C21** — Lists are paginated (15 default) and returned via
  `ApiRespond::paginated()`, which is the only helper that preserves `meta`.

### Data & schema

- **C22** — Module migrations go in `database/migrations/{Module}/`; shared
  infrastructure in `database/migrations/Shared/`.
- **C23** — Any table another module might FK into gets `softDeletes()`.
- **C24** — An `exists` validation rule on a soft-deletable table must add
  `->whereNull('deleted_at')`. A bare `exists` accepts trashed rows.
- **C25** — No column stores a fact another column already holds, and no column
  caches a derived value without an invalidation strategy. **(P1)**
- **C26** — Enums are int-backed and are a lens over a column, never a second copy
  of the fact. Add indexes for expected query paths.

### Pagination

- **C27** — **Every** collection endpoint is paginated. There is no unpaginated
  list endpoint in this API, regardless of how small the table is today.
- **C28** — `per_page` is client-controllable, defaults to `15`, and is **clamped
  to 1–100**. The clamp is written once and shared, never re-implemented per
  controller.
- **C29** — Every paginated query orders by a column set unique across the result
  set — in practice, end every `ORDER BY` with the primary key. A tied sort makes
  paging non-deterministic.
- **C30** — Client-controlled `sort` and filter parameters are **allow-listed in
  the service**. Never interpolate a query parameter into `orderBy()`. An unknown
  value is a `422`, not a silent fallback.

---

## 5. Folder structure

### Module anatomy

⚙️ A folder under `app/Modules/` containing a `routes.php` **is** a module —
`ModuleServiceProvider::loadModuleRoutes()` discovers it and mounts it at
`/api/{lowercased-folder-name}` with the `api` middleware group.

⚠️ **Routes and migrations are zero-config; MODELS ARE NOT.** Any model that is
audited (`HasAuditLog`) or can be a polymorphic target must be added to the
enforced morph map in `AppServiceProvider` — see §8 step 2. That is the one
registration a new module still owes the app root, and it is the price of
`getMorphClass()` returning a stable alias instead of a PHP class name (C12).
`MorphMapTest` fails the build if it is forgotten, which is the only reason
this is a documented cost rather than a trap.

```
app/Modules/{Module}/
  Controllers/      thin HTTP handlers — one per resource
  Services/         business logic, transactions, locks, audit
  Models/           Eloquent models; invariants in boot hooks
  Requests/         FormRequest validation, Arabic messages
  Resources/        the public JSON contract
  Enums/            int-backed value lenses
  Exceptions/       domain exceptions (meaning + status code as data)
  Support/          pure guards, caches, helpers — framework-free
  Policies/         row-level authorization        [when row scoping applies]
  routes.php        ← this file is what activates the module

database/migrations/{Module}/   auto-loaded, no registration
tests/Feature/{Module}/         one feature test file per resource
```

### Shared code

```
app/Shared/
  Middleware/       cross-cutting, module-agnostic
  Services/         AuditLogService — the single audit writer
  Traits/           ApiRespond (envelope) · HasAuditLog (model audit)
  Support/          RequestContext — identity + correlation, built in middleware
```

### Where a thing goes — decision table

| You are writing… | It goes in |
|---|---|
| A rule about the shape of input | `Requests/` |
| A rule that must hold for seeders and tinker too | `Models/` boot hook, via a `Support/` guard |
| A rule shared by both of the above | `Support/{X}Guard.php`, called from both |
| A transaction or a lock | `Services/` |
| "Which fields does the client see?" | `Resources/` |
| "May this user do this verb?" | `routes.php` → `permission:` |
| "May this user touch this row?" | `Policies/` |
| Something two modules need | `app/Shared/` |
| A cross-cutting concern for every request | `app/Shared/Middleware/` + `bootstrap/app.php` |

**Rule:** if you cannot name the layer, the code is doing two things. Split it.

---

## 6. API format — the wire contract

### URLs

```
{APP_URL}/api/{module}/{resource}
```

- Module prefix is automatic and is the lowercased folder name.
- **Do not repeat the module in the path.** `Departments` is already mounted at
  `/api/departments`, so the resource is registered at the module root — using
  `apiResource('departments')` would produce `/api/departments/departments`.
  ⚙️ See the comment block in `app/Modules/Departments/routes.php`.
- Resource names are plural nouns. Actions are HTTP verbs, not path segments.
  `POST /api/hr/employees`, never `POST /api/hr/createEmployee`.
- Sub-actions that are genuinely not CRUD may be a verb segment:
  `POST /api/auth/roles/assign`.
- **Literal paths are declared before wildcard paths**, or the wildcard swallows
  them. ⚙️ `roles/assign` is declared above `roles/{role}` for this reason.

### Verbs and status codes

| Verb | Success | Meaning |
|---|---|---|
| `GET` collection | `200` + `data` + `meta` | paginated list |
| `GET` single | `200` + `data` | one row |
| `POST` | `201` + `data` | created |
| `PUT` / `PATCH` | `200` + `data` | updated (both accepted; partial-safe) |
| `DELETE` | `200`, message only | deleted |

| Status | Use for | Never for |
|---|---|---|
| `400` | malformed request | validation failures |
| `401` | no/expired/invalid token | permission denial |
| `403` | permission denied, disabled account | unauthenticated |
| `404` | row not found, or hidden by row-scope | a row the user may see |
| `409` | conflict with protected state (system role, root deletion) | validation |
| `422` | validation and invariant violations | anything a client cannot fix |
| `429` | rate limit | — |

**`403` vs `404` for row-scoping:** when a user may not see a row, return `404`.
`403` confirms the row exists, which leaks it.

### The envelope

**Success**

```json
{ "success": true, "message": "تمت العملية بنجاح.", "data": { } }
```

`data` is omitted entirely when there is nothing to return.

**Error**

```json
{ "success": false, "message": "فشل التحقق من البيانات المُدخلة.",
  "errors": { "email": ["..."] } }
```

`errors` is present only for field-level failures.

### Pagination — the list contract

**Every endpoint that returns a collection is paginated. There is no such thing
as an unpaginated list endpoint in this API** (C27). A list that is "small today"
is a list that is large after the import — `users` becomes 242+ rows in Batch 4,
and `job_titles` starts life at 691.

#### The shape — exactly this, on every list endpoint

```json
{
  "success": true,
  "message": "تم جلب الموظفين.",
  "data": [ { }, { } ],
  "meta": {
    "current_page": 1,
    "last_page":    4,
    "per_page":     15,
    "total":        52,
    "from":         1,
    "to":           15
  }
}
```

| Key | Type | Notes |
|---|---|---|
| `data` | array | **Always an array**, never an object, never `null`. An empty page is `[]` |
| `meta.current_page` | int | 1-based |
| `meta.last_page` | int | `1` when there are no results — never `0` |
| `meta.per_page` | int | the size actually applied, after clamping |
| `meta.total` | int | rows matching the query, across all pages |
| `meta.from` / `meta.to` | int \| null | **`null` on an empty page** — clients must handle it |

⚙️ Produced only by `ApiRespond::paginated()`. `meta` is a **sibling** of `data`,
not nested inside it, and the six keys above are the whole contract — do not add
`links`, `path`, or Laravel's other paginator output. A client writes one pager
against these six keys and it works on every list in the system.

**Never build a list response with `success()`.** Passing a `ResourceCollection`
to `success()` nests it under `data` and **silently drops the metadata** — a
`ResourceCollection` only emits its `meta` when it is the top-level response, not
when embedded in an array. The response looks fine and the client cannot page.
That is the entire reason `paginated()` exists.

#### Query parameters

| Param | Type | Default | Bounds |
|---|---|---|---|
| `page` | int | `1` | ≥ 1. Out of range → an empty `data` with correct `meta`, **200 not 404** |
| `per_page` | int | `15` | **1–100.** Out of range is clamped, not rejected |

The `per_page` cap is not decoration (C28). Without it, `?per_page=1000000` is an
unauthenticated-cost denial of service against a table that will hold hundreds of
thousands of audit rows — and this API has almost no rate limiting today (GAP-1),
so nothing else stops it.

**Where `per_page` is resolved:** in the **controller**, which then passes an
`int` to the service. The service signature stays `paginate(int $perPage = 15)`
so it remains callable from a console command or a job with no HTTP present
(C1). A service must never read the query string itself.

```php
// Controller — the only layer that knows a query string exists.
public function index(Request $request): JsonResponse
{
    return $this->paginated(
        data: EmployeeResource::collection(
            $this->employeeService->paginate($this->perPage($request)),
        ),
        message: 'تم جلب الموظفين.',
    );
}
```

⚙️ `perPage()` **is** in `ApiRespond`, so the clamp is written once rather than
copied into every controller — P1. Use it; do not re-derive the bounds.

#### Ordering must be deterministic

**Every paginated query orders by a column set that is unique across the result
set** (C29). In practice: end every `ORDER BY` with the primary key.

```php
Employee::query()->latest()->orderByDesc('id')->paginate($perPage);   // ✓
Employee::query()->latest()->paginate($perPage);                      // ✗
```

Why this is not pedantry — ⚙️ **verified on the seeded tree, 2026-09-08:** all
**119** department rows share a **single** `created_at` value (`SELECT DISTINCT
created_at` returns 1). `->latest()` is `ORDER BY created_at DESC`, so for 118 of
119 rows the sort key carries **no ordering information at all**. Pages 1 and 2
are two separate queries; when the sort is fully tied, nothing in the SQL
standard — or in MySQL or SQLite — requires them to agree on which rows fall
where. A row can appear on two pages while another is never returned.

*Honest scope of the finding:* walking all eight pages under the SQLite test
driver returned all 119 ids with zero duplicates, so the symptom does **not**
currently reproduce. That stability is an accident of a storage engine returning
rows in rowid order when the sort key is exhausted; it is not a guarantee, and
InnoDB choosing a different plan or index between the two queries has no reason
to match it. The precondition is real, verified, and one word wide. Fix it by
adding the tie-break, not by relying on the accident.

The same applies to `orderBy('name')` and `orderBy('email')` — names collide
(`محاسب` appears under several divisions), so those need the tie-break too.

#### Sorting and filtering

When a list needs client-controlled sorting or filtering, keep it declarative
and allow-listed (C30):

- `?sort=name` / `?sort=-name` — leading `-` means descending.
- **Allow-list the sortable columns in the service.** Never interpolate a query
  parameter into `orderBy()`; that is SQL injection through the back door and it
  also exposes internal column names as public API.
- Filters are explicit named params (`?status=active`), validated like any other
  input — a FormRequest is appropriate for a complex `index`.
- An unknown `sort` or filter value is a `422`, not a silent fallback (P3).

#### What must never happen

- A collection returned through `success()` instead of `paginated()`.
- A `->get()` on a table that grows, in anything reachable from an HTTP handler.
  ⚙️ `DepartmentService::all()` and `EmployeeService::all()` exist for select
  inputs and are deliberately **not** wired to a route — keep it that way, or
  give them a hard cap.
- `data` shaped as an object keyed by id. Clients iterate; keep it an array.
- Pagination metadata invented in a controller instead of read from the paginator.

### Headers

| Header | Direction | Required |
|---|---|---|
| `Accept: application/json` | in | **yes** — without it Laravel returns HTML redirects instead of JSON errors |
| `Content-Type: application/json` | in | on every body-carrying request |
| `Authorization: Bearer {token}` | in | every protected route |
| `X-Request-Id` | out | every response. Honoured if the client sends a plausible one; recorded on `audit_logs.request_id` |
| `Retry-After`, `X-RateLimit-*` | out | on `429` |

---

## 7. API structure — naming and permissions

### Permission names

```
{module}.{resource}.{action}
```

⚙️ The permission name is **exactly** the string the route middleware passes and
**exactly** the string seeded into the `permissions` table. There is no mapping
layer: a typo in either place is a silently unguarded or permanently denied
route. Seed it and use it in the same commit.

| Example | Guards |
|---|---|
| `hr.employees.view` | `GET /api/hr/employees` and `/{employee}` |
| `hr.employees.create` | `POST /api/hr/employees` |
| `hr.employees.update` | `PUT`/`PATCH /api/hr/employees/{employee}` |
| `hr.employees.delete` | `DELETE /api/hr/employees/{employee}` |
| `hr.employees.viewSalary` | a **field-level** gate inside the Resource |

**Field-level permissions are real permissions.** When a field is gated, gate its
*inputs* too — exposing an employee's grade and activity type while hiding the
salary they compute to is theatre.

### Route file shape

```php
Route::middleware(['auth:sanctum', 'audit'])
    ->name('{resource}.')
    ->group(function () {
        Route::get('/', [XController::class, 'index'])
            ->middleware('permission:{module}.{resource}.view')->name('index');
        // …
    });
```

**Do not use `apiResource()`.** It collapses all five routes onto one middleware
list, which means reads and writes share a permission — a read-only role could
mutate. ⚙️ Both `Departments` and `HR` deliberately expanded theirs into explicit
routes for exactly this reason; their route files say so.

### The `audit` middleware

Apply to every authenticated group **except** high-frequency polled endpoints,
which would flood the trail. ⚙️ The notifications group is deliberately excluded —
see the comment in `app/Modules/Auth/routes.php`.

---

## 8. The build workflow — step by step

The order matters: each step makes the next one's mistakes impossible.

**1. Model the data first.** Decide the columns. Apply P1 — is any of this a fact
another column already holds, or a derived value? Write the migration into
`database/migrations/{Module}/`. Add soft deletes (C23) and indexes (C26).

**2. Write the model.** `$fillable`, `casts()`, relations. Then ask: *what must be
true of this row no matter who writes it?* Put those invariants in a `saving` /
`creating` / `deleting` hook (C4). If the rule is non-trivial, extract it to a
pure `Support/` guard so the FormRequest can call the same code (C5).

**2b. Register the model's morph alias.** Add it to `Relation::enforceMorphMap()`
in `AppServiceProvider` — a lower-case, namespace-free name such as `employee`.
The map is ENFORCED, so an unmapped model raises rather than silently falling
back to its class name; and because `HasAuditLog` catches its own failures, an
unmapped audited model would lose audit rows *quietly*. `MorphMapTest` catches
it at build time. Remember the alias is a PUBLIC contract (clients read it as
`reference.type`) and a stored value — renaming one later is a data migration.

**3. Add `HasAuditLog`** if field-level change history matters (C13). Extend the
redaction list if you added a secret (C14).

**4. Seed the permissions.** Add the four (or more) `{module}.{resource}.{action}`
rows to `PermissionSeeder`. Do this *before* writing routes so you cannot
mistype (P4).

**5. Write the service.** Business logic, transactions, locks, audit calls.
Signature takes scalars/arrays/models — never a `Request` (C1). If two writes
must not interleave, this is the transaction and locking boundary. ⚙️ See
`DepartmentService::create()` for the pattern: a named cache lock for a
singleton, a row lock on the parent for a child.

**6. Write the FormRequest.** Field rules + Arabic messages. `authorize()` returns
`true` — route middleware owns coarse access. Add `->whereNull('deleted_at')` to
every `exists` on a soft-deletable table (C24). If a shared guard exists, invoke
it in `after()` and surface each violation as a field error.

**7. Write the Resource.** Decide the public contract deliberately — a new model
column is *not* automatically public. Gate sensitive fields (C11). Use
`whenLoaded()` and make sure the service eager-loads what you reference.

**8. Write the controller.** One service call, one Resource, one envelope (C2).
If it is longer than ten lines, something belongs in the service.

**9. Write `routes.php`.** `auth:sanctum` + `audit` + a `permission:` per route
(C6–C8). Literal paths before wildcards. Explicit routes, not `apiResource`.

**10. Write the tests.** See §10. Both the happy path *and* the 403.

**11. Verify.**
```bash
php artisan route:list --path=api    # is the middleware chain what you intended?
php artisan test
./vendor/bin/pint                     # preserves the aligned => style
```

**12. Update the docs.** `API_DOCUMENTATION.md` for the wire contract (the
frontend and Flutter projects read it), and `START_HERE.md` §7 if you changed the
endpoint count. A shipped endpoint that is not in `API_DOCUMENTATION.md` is not
shipped.

---

## 9. Worked example — a feature end to end

`job_titles` — the governed catalogue of job titles, from Batch 2 step 5. Real,
next in the roadmap, and it exercises every layer.

**1. Migration** — `database/migrations/HR/xxxx_create_job_titles_table.php`

```php
Schema::create('job_titles', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
    $table->boolean('is_described')->default(false);   // تم التوصيف
    $table->timestamps();
    $table->softDeletes();                             // C23 — employees will FK here
    $table->index('is_described');                     // C26
});
```

**2. Model** — `app/Modules/HR/Models/JobTitle.php`

```php
class JobTitle extends Model
{
    use HasAuditLog;      // C13
    use SoftDeletes;

    protected $fillable = ['name', 'department_id', 'is_described'];

    protected function casts(): array
    {
        return ['is_described' => 'boolean'];
    }

    // C4 — every writer crosses this, not just HTTP.
    protected static function booted(): void
    {
        static::deleting(function (self $jobTitle): void {
            if ($jobTitle->employees()->exists()) {
                throw new JobTitleInUseException;
            }
        });
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
```

**3. Permissions** — into `PermissionSeeder`, before anything else:

```php
['name' => 'hr.job_titles.view',   'module' => 'hr', 'label' => 'عرض المسميات الوظيفية'],
['name' => 'hr.job_titles.create', 'module' => 'hr', 'label' => 'إنشاء مسمى وظيفي'],
['name' => 'hr.job_titles.update', 'module' => 'hr', 'label' => 'تعديل مسمى وظيفي'],
['name' => 'hr.job_titles.delete', 'module' => 'hr', 'label' => 'حذف مسمى وظيفي'],
```

**4. Service** — `app/Modules/HR/Services/JobTitleService.php`

```php
class JobTitleService
{
    // C1 — no Request anywhere in this signature or body.
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return JobTitle::query()->with('department')->latest()->paginate($perPage);
    }

    public function create(array $data): JobTitle
    {
        return DB::transaction(fn (): JobTitle => JobTitle::create($data));
    }

    public function update(JobTitle $jobTitle, array $data): JobTitle
    {
        $jobTitle->update($data);

        return $jobTitle->refresh();
    }

    // The in-use guard is the model's (C4); this only delegates.
    public function delete(JobTitle $jobTitle): void
    {
        $jobTitle->delete();
    }
}
```

**5. FormRequest** — `app/Modules/HR/Requests/JobTitleRequest.php`

```php
class JobTitleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route middleware owns coarse access
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name'          => [$required, 'string', 'max:255'],
            // C24 — a bare exists would accept a trashed department.
            'department_id' => ['nullable', 'integer',
                Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'is_described'  => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'        => 'اسم المسمى الوظيفي مطلوب.',
            'department_id.exists' => 'الوحدة التنظيمية المحددة غير موجودة أو تم حذفها.',
        ];
    }
}
```

**6. Resource** — `app/Modules/HR/Resources/JobTitleResource.php`

```php
class JobTitleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'is_described' => $this->is_described,
            'department'   => $this->whenLoaded('department', fn () => [
                'id'   => $this->department->id,
                'name' => $this->department->name,
            ]),
            'created_at'   => $this->created_at->toIso8601String(),
        ];
    }
}
```

**7. Controller** — `app/Modules/HR/Controllers/JobTitleController.php`

```php
class JobTitleController extends Controller
{
    use ApiRespond;

    public function __construct(
        private readonly JobTitleService $jobTitleService,
    ) {}

    public function index(): JsonResponse
    {
        return $this->paginated(                      // C21
            data: JobTitleResource::collection($this->jobTitleService->paginate()),
            message: 'تم جلب المسميات الوظيفية.',
        );
    }

    public function store(JobTitleRequest $request): JsonResponse
    {
        $jobTitle = $this->jobTitleService->create($request->validated());

        return $this->created(
            data: new JobTitleResource($jobTitle),
            message: 'تم إنشاء المسمى الوظيفي.',
        );
    }

    public function show(JobTitle $jobTitle): JsonResponse
    {
        return $this->success(
            data: new JobTitleResource($jobTitle->load('department')),
            message: 'تم جلب المسمى الوظيفي.',
        );
    }

    public function update(JobTitleRequest $request, JobTitle $jobTitle): JsonResponse
    {
        $jobTitle = $this->jobTitleService->update($jobTitle, $request->validated());

        return $this->success(
            data: new JobTitleResource($jobTitle),
            message: 'تم تحديث المسمى الوظيفي.',
        );
    }

    public function destroy(JobTitle $jobTitle): JsonResponse
    {
        $this->jobTitleService->delete($jobTitle);

        return $this->success(message: 'تم حذف المسمى الوظيفي.');   // C19 — 200, not 204
    }
}
```

**8. Routes** — appended to `app/Modules/HR/routes.php`

```php
Route::middleware(['auth:sanctum', 'audit'])
    ->name('job-titles.')
    ->group(function () {
        Route::get('job-titles', [JobTitleController::class, 'index'])
            ->middleware('permission:hr.job_titles.view')->name('index');
        Route::post('job-titles', [JobTitleController::class, 'store'])
            ->middleware('permission:hr.job_titles.create')->name('store');
        Route::get('job-titles/{jobTitle}', [JobTitleController::class, 'show'])
            ->middleware('permission:hr.job_titles.view')->name('show');
        Route::match(['put', 'patch'], 'job-titles/{jobTitle}', [JobTitleController::class, 'update'])
            ->middleware('permission:hr.job_titles.update')->name('update');
        Route::delete('job-titles/{jobTitle}', [JobTitleController::class, 'destroy'])
            ->middleware('permission:hr.job_titles.delete')->name('destroy');
    });
```

Nine files. No registration anywhere — the routes file and the migrations folder
are discovered.

---

## 10. Testing standard

⚙️ Feature tests per module under `tests/Feature/{Module}/`. Every batch of work
leaves the suite green — that was the explicit standard for all four Phase 1.5
batches.

**The five tests every resource needs:**

| # | Test | Asserts |
|---|---|---|
| 1 | Happy path per action | `200`/`201`, `success: true`, the JSON shape |
| 2 | **Unauthenticated** | `401` |
| 3 | **Roleless user** | `403` — use `actingAsRolelessUser()` |
| 4 | **Wrong permission** | `403` — use `actingAsUserWithPermissions(['x.y.view'])` then attempt a write |
| 5 | Validation | `422` + `assertJsonValidationErrors([...])` |

Test 4 is the one people skip, and it is the one that catches the highest-impact
mistake: a write route guarded by `.view`.

⚙️ `tests/TestCase.php` already provides `actingAsAdmin()`,
`actingAsRolelessUser()`, and `actingAsUserWithPermissions(array $permissions)`.
Use them; do not hand-roll authentication in a test.

**Also test:**
- Every invariant, at the **model** level — not only through HTTP. That is the
  whole point of putting it in a hook (P2). ⚙️ `DepartmentHierarchyTest` does both.
- Seeder counts **exactly**, so a silent change to reference data fails the build.

---

## 11. Review checklist

A PR that adds or changes an endpoint must satisfy all of these.

**Routing & access**
- [ ] Every new route has `auth:sanctum`, or a comment saying why it is public (C6)
- [ ] Every new route has a `permission:`, or a comment naming its authorization model (C7)
- [ ] Reads guarded by `.view`; each write by its own permission (C8)
- [ ] The permission strings are seeded in `PermissionSeeder`, spelled identically
- [ ] `php artisan route:list --path=api` shows the intended chain
- [ ] Literal paths declared before wildcard paths

**Layering**
- [ ] No `Request` — class, facade, or helper — anywhere in a service (C1)
- [ ] No model query in a controller (C3)
- [ ] No `if` on business state in a controller (C2)
- [ ] Rules that must bind seeders and tinker are in a model hook or guard (C4)
- [ ] No business logic duplicated between a FormRequest and a service

**Response**
- [ ] Every response goes through `ApiRespond` (C16)
- [ ] No hand-written `response()->json(['success' => …])` (C16)
- [ ] No raw model returned (C18)
- [ ] No `204` (C19)
- [ ] Messages are Arabic and display-ready (C20)
- [ ] No class name, path, or trace can reach a client (C12)

**Pagination** (any endpoint returning a collection)
- [ ] It is paginated — no unpaginated list ships (C27)
- [ ] Built with `paginated()`, never `success()` (C21)
- [ ] `meta` carries exactly the six keys, as a sibling of `data`
- [ ] `data` is an array, and is `[]` (not `null`) on an empty page
- [ ] `per_page` is honoured, defaulted to 15, and clamped to 1–100 (C28)
- [ ] `per_page` is resolved in the controller and passed to the service as an `int` — the service never reads the query string (C1)
- [ ] The `ORDER BY` ends with the primary key (C29)
- [ ] Any `sort` / filter parameter is allow-listed in the service (C30)
- [ ] A test asserts page 2 of a seeded set returns the rows page 1 did not

**Data**
- [ ] Migration in `database/migrations/{Module}/` (C22)
- [ ] `softDeletes()` on anything another module may FK into (C23)
- [ ] Every `exists` on a soft-deletable table has `->whereNull('deleted_at')` (C24)
- [ ] No column duplicates or caches an existing fact (C25)
- [ ] Indexes for the query paths you actually added

**Audit & security**
- [ ] State-changing actions are audited (C13)
- [ ] New secrets added to `AuditLogService::sanitize()` (C14)
- [ ] Sensitive fields gated in the Resource, inputs included (C11)

**Verification**
- [ ] `php artisan test` green
- [ ] `./vendor/bin/pint` run
- [ ] The five tests from §10 exist, including the wrong-permission `403`
- [ ] `API_DOCUMENTATION.md` updated

---

## 12. Gap register

Where the codebase does not yet meet this standard. **Each is a known debt, not a
pattern to copy.**

⚙️ **Status as of 2026-09-08: ten of the twelve gaps are closed.** They were
fixed in one pass after a compliance scan re-verified every one of them
empirically. The suite went from 143 tests to 179; the new ones are the
regression guards, because every closed gap below had survived precisely because
nothing tested it.

### Still open

| # | Gap | Breaks | Evidence | Fix |
|---|---|---|---|---|
| **GAP-3** | **No row-level authorization anywhere.** Permissions are verb-level only: `hr.employees.view` reads *every* employee; `departments.update` edits *any* unit. No `Policy` class exists; every `FormRequest::authorize()` returns bare `true` | C10, stage 4b | `grep -r Policy app/` → nothing | Add a thin policy layer for subtree scoping. **Blocked on a product decision: what is a manager's scope?** Partially mitigated — the salary and national-id exposure that made this urgent is now gated field-level (C11) |
| **GAP-12** | **Sensitive fields are gated on READ but not on WRITE.** `hr.employees.viewSalary` decides who may *see* a salary; who may *set* one is still plain `hr.employees.update`. So a caller can write a value they cannot read back | C11 | `EmployeeRequest` vs `EmployeeResource` | Decide the intended rule before the real import. Narrowing write access is a policy decision, not a bug fix, so it was deliberately not taken unilaterally |

### Closed

| # | Gap | Closed by |
|---|---|---|
| **GAP-1** | 25 of 27 endpoints unthrottled | `$middleware->throttleApi()` in `bootstrap/app.php` + an `api` limiter (60/min, keyed on user then IP). The login limiter is now keyed on **ip + lower-cased email**, so one attacker behind a NAT can no longer lock out everyone sharing the address. `AuthThrottleTest` asserts all three |
| **GAP-2** | Every framework error escaped the envelope | `ApiExceptionRenderer`, wired once in `bootstrap/app.php`, typed on `Throwable`. Covers validation, domain, auth, authorization, model-not-found, route-not-found, method-not-allowed, throttle, any `HttpException`, and the generic case. `ApiEnvelopeTest` asserts the shape of each and that no body contains a class name, path or trace |
| **GAP-4** | Four exceptions rendered their own HTTP | The `App\Shared\Exceptions\DomainException` interface. Exceptions carry `statusCode()` as data and no longer import HTTP. The renderer matches the **interface**, so modules stay zero-config — which was the real reason they self-rendered, and it is preserved |
| **GAP-5** | No request context, no request id | `RequestContext` (Shared/Support, bound **scoped** so a queue worker rebuilds it per job) + `SetRequestContext` as **global** middleware, so unmatched routes and 405s are covered too. `X-Request-Id` is echoed on every response, honoured if the client sends a plausible one, and stored on `audit_logs.request_id` |
| **GAP-6** | `AuditLogService` captured the ambient request | It takes `RequestContext` by constructor injection. No facade, no `Auth::id()`, and rows written from CLI or a queue now carry an honest context instead of silent nulls |
| **GAP-7** | `API_DOCUMENTATION.md` stated a false contract | Made true rather than merely corrected — the doc now says every response carries `success`, and it does. The migration notes for the frontend are in §1 of that file |
| **GAP-8** | Dead envelope helpers | Five are now the renderer's vocabulary; `noContent()` is deleted |
| **GAP-9** | `AI_AGENT_BASE_PROMPT.md` stale and harmful | Superseded-by banner at the top pointing here |
| **GAP-10** | Minor placement issues | Self-delete rule moved into `UserService::delete()` behind `CannotDeleteOwnAccountException`; `RoleService::assign/unassign` take ids and do their own loading; the `markRead` route is `whereNumber`-constrained and the controller takes an `int`; the scaffold `GET /api/user` is deleted |
| **GAP-11** | The list contract was not unified | `ApiRespond::perPage()` (clamped 1–100, written once); users and roles paginate; every list query ends `ORDER BY` with the primary key. `PaginationContractTest` asserts the six `meta` keys on all five endpoints and that paging returns every row exactly once |

### Newly found and closed in the same pass

These were **not** in the register — the scan of 2026-09-08 found them.

| Finding | Breaks | What it was |
|---|---|---|
| `national_id` and `salary` ungated | **C11** | Both shipped to anyone holding `hr.employees.view`. Now behind `hr.employees.viewIdentity` / `hr.employees.viewSalary`, as absent keys rather than nulls |
| `national_id` audited in cleartext | **C14** | The redaction list held credentials only, so every employee write recorded a national id verbatim onto an **append-only** table that cannot be scrubbed afterwards. `salary`, `agreed_salary` and `sham_cash_account` are pre-registered for Batch 2 |
| `CheckPermission` hand-built the envelope twice | **C16** | §3 forbids a middleware building JSON. It uses `ApiRespond` now |
| A state-changing endpoint was unaudited | **C13** | `POST /notifications/{id}/read` — the whole notifications group was excluded from `audit` to keep polling noise out of the trail, which silently swept up the one write in it. The exclusion is now per-route |
| `users` and `roles` had no soft deletes | **C23** | Hard-deleting a user **nulled their `user_id` across the entire audit trail** (`audit_logs.user_id` is `nullOnDelete`) and cascaded their notifications away — unrecoverable attribution loss on the people an audit is most often about |
| Bare `exists` on users/roles | **C24** | Latent, and would have become live the moment those tables gained soft deletes. Fixed in the same commit that gave them soft deletes |
| Sanctum migration at the migrations root | **C22** | Moved to `database/migrations/Shared/` |
| Users and Roles were missing §10 tests 2 and 4 | §10 | Neither had an unauthenticated check, and both used a role**less** user where the standard asks for a view-only user attempting a write. Both added |
| Nothing tested pagination at all | §11 | `grep` for `per_page`, `page=2` or `meta` across `tests/` returned **nothing**, which is why three separate list defects shipped invisibly under a green suite |
| The permission catalogue was not asserted | §10 | `PermissionSeederTest` now asserts it exactly, and cross-checks that every permission referenced by route middleware is actually seeded — a mismatch there is a permanently denied route that announces itself in no other way |

### Found by review of the first pass, and closed

⚙️ A review of the compliance pass found **six defects in the fixes themselves**.
They are recorded rather than quietly corrected, because the pattern in them is
the lesson: *five of the six were cases where the mechanism was present and the
test asserted the happy path, so the hole was invisible.*

| Finding | Breaks | What it was |
|---|---|---|
| **The baseline throttle never saw hostile traffic** | C-stage 1 | `throttleApi()` was added, but Laravel's `$middlewarePriority` puts `AuthenticatesRequests` **before** `ThrottleRequests`, so protected routes resolved to `auth:sanctum → throttle:api` and every unauthenticated request 401'd before reaching the limiter. Probed: 70 unauthenticated requests, 70 × 401, **zero 429**. The test that "proved" the limiter worked was authenticated, so it exercised the one path that was never at risk. Fixed by reordering the priority list — note `prependToPriorityList()` cannot do it, being a no-op for a middleware already in the list |
| **`reference.type` published PHP class names** | **C12** | `NotificationResource` returned `reference_type` raw with no morph map, so clients received `App\Modules\HR\Models\Employee` — and `API_DOCUMENTATION.md` printed that as the contract. `audit_logs.auditable_type` stored the same. The resource's own docblock claimed it exposed the reference "without leaking the internal Eloquent morph map". Fixed with an **enforced** morph map (stable lower-case aliases), so an unmapped model fails loudly instead of falling back to the class name |
| **`X-Request-Id` missing on routing failures** | GAP-5's own contract | `SetRequestContext` was registered on the `api` **group**, which runs only on a matched route — so `/api/nope` (404) and a wrong verb (405) carried no correlation id, contradicting the "every response" contract, on exactly the failures a client reports. Now global middleware, wrapping routing itself |
| **`RequestContext` was a singleton, not scoped** | GAP-5 | A queue worker calls `forgetScopedInstances()` between jobs and nothing else, so a singleton survives — every job a worker ever ran would share one correlation id, which is the exact confusion the context exists to remove. Verified: same instance and id across `forgetScopedInstances()`. Now `scoped()`, and a job deliberately gets its own generated id rather than null |
| **The new assign response lazy-loaded in the Resource layer** | §3 Resource contract | `RoleService::assign()` returned `$user->load('roles')`, but `UserResource` dereferences `employee` directly to resolve the display name — so wrapping the result in a Resource fired a `SELECT` against `employees` during serialization. One row here; the same shape on a list is an N+1. Now loads `['roles', 'employee']` |
| **`.claude/settings.local.json` auto-approved a destructive production command** | — | It carried `Bash(APP_ENV=production php artisan migrate:fresh --seed --force)` as a no-confirmation allow. Removed — but removing it was **not sufficient**, because `Bash(php artisan *)` in the same file still auto-approved `php artisan migrate:fresh` unprefixed. Closed properly with an explicit `deny` list (deny beats allow), which blocks `migrate:fresh`, `migrate:reset`, `migrate:rollback`, `db:wipe` and anything carrying `--env=production` / `APP_ENV=production`, while leaving ordinary artisan use unprompted. `/.claude` is now in the repo's own `.gitignore` |

### Found by a second review, and closed

⚙️ A review of the review pass found **three more**, and the first two are the
same failure wearing a different hat: *a regression test that does not
discriminate is not a regression test.*

| Finding | What it was |
|---|---|
| **The lazy-load regression test was a false positive** | It counted queries touching `employees` and asserted exactly one — but the eager load and the lazy load each issue exactly one, so it passed against the broken implementation. Verified by reverting the fix and watching it stay green. It also claimed assign/unassign coverage while exercising only assign. Replaced with a `relationLoaded()` assertion on the service's return value, covering both methods, and verified to FAIL against the reverted fix |
| **`Model::preventLazyLoading()` cannot catch this class of bug** | The obvious "stronger" version of that test also passed against the broken code. ⚙️ `Builder::hydrate()` assigns the per-instance `preventsLazyLoading` flag **only when `count($items) > 1`** — Laravel deliberately exempts single-model results, since one model cannot cause an N+1. Every Resource-layer lazy load in this codebase is on a single-model path (`findOrFail`, route-model binding), so framework strictness gives **no** protection there. Worth knowing before anyone adds `Model::shouldBeStrict()` and assumes they are covered |
| **`scoped()` had no test** | The binding was right, but nothing in the suite ever called `forgetScopedInstances()`, so reverting to `singleton()` stayed green. `RequestContextLifecycleTest` simulates the queue-worker reset; verified to fail under `singleton()` |

Plus doc drift the same review caught: §2 and GAP-5 still described
`SetRequestContext` as api-group middleware after it became global,
`RequestContext`'s own docblock still called itself a singleton, and the test
totals and permission count were stale in two places. All corrected.

### Found by a standards review of the pass, and closed

⚙️ A third review, this one reading the applied changes against these rules
rather than hunting bugs, found five more. Two were **pre-existing violations
the pass had walked past**, which is its own lesson: a compliance pass that only
inspects what it changed will keep missing what it did not.

| Finding | Breaks | What it was |
|---|---|---|
| **The access-token lifetime existed in four places** | **P1** | An env-overridable `config/sanctum.expiration`, a hardcoded `AuthService::ACCESS_TOKEN_EXPIRATION_MINUTES = 15`, and `15 * 60` written twice in `AuthController` — and only the config one responded to `SANCTUM_TOKEN_EXPIRATION`. Setting it to 5 gave a system where Sanctum's guard rejected tokens at five minutes, the stored `expires_at` said fifteen, and clients were told 900 seconds; every client would schedule its refresh for minute 15 on a token dead at minute 5. Config is now the single source, the service reports `expires_in` as a fact about the token it minted, and `null` (Laravel's own default, meaning no expiry) is carried through rather than coerced. `AccessTokenLifetimeTest` asserts the three agree **under a non-default config** — the default case agreed by coincidence, which is why four copies survived |
| **`GET /api/departments/{id}` queried from the Resource layer** | §3 Resource contract | `DepartmentResource` exposes a resolved `sector`, which walks up the tree one query per hop. The list endpoint was covered by the service's `withAncestors()`; `show()` used bare route-model binding, so the walk ran from inside the Resource. Fixed with `Department::loadAncestors()`, sharing one definition of the chain depth with the scope (`ancestorRelationPath()`) so the two cannot drift. ⚠️ **The query count went UP, 3 → 4** — preloading costs one query per level while the lazy walk stopped at the first stored sector. It is a layer fix, not a speed fix, and is recorded as such rather than dressed up |
| **The morph map broke the zero-config module property** | — | Interface-matched exceptions were designed to keep modules zero-config, and then a central `enforceMorphMap` required an `AppServiceProvider` edit for every audited model — while both §5 and `START_HERE` still said "nothing else is registered anywhere". §8 never mentioned it either, so an author following the workflow exactly would miss it. Both claims corrected; registration added as step 2b |
| **C7 unmet on `/logout` and `/me`** | **C7** | Neither carries a `permission:` nor a comment naming its authorization model. Identified during the first audit, judged minor, then neither fixed nor recorded — so it vanished, which is precisely what the register exists to stop. Now documented in the route file as self-scoped |
| **`ApiExceptionRenderer` and `CheckPermission` held success helpers** | cohesion | Both took the whole of `ApiRespond` for its error half, gaining `success()`, `created()`, `paginated()` and `perPage()` they can never legitimately call. Split into an `ApiErrors` trait that `ApiRespond` **composes**, so the error envelope still has exactly one definition (C16 intact) and error-only collaborators take only what they use |

### Deliberately not done

- **`employees.status` is a DB enum + `Rule::in`, not an int-backed PHP enum**
  (C26). Batch 2 rebuilds the `employees` table wholesale; changing it now is
  churn against a ratified plan. Fix it as part of that rebuild.
- **Repo-wide Pint.** Seven files unrelated to this work still fail
  (`ModuleServiceProvider`, `config/auth.php`, `config/sanctum.php`,
  `UserSeeder`, `routes/console.php`, `tests/TestCase.php`,
  `PruneExpiredRefreshTokens`). Every file touched by the compliance pass is
  clean. The rest is a separate, mechanical commit.

**Remaining order:** GAP-12 needs an answer, not a fix. GAP-3 is the most
security-significant thing left and is still blocked on the same product
decision it always was.

---

## 13. Anti-patterns, with real examples

⚙️ Every example below **was real in this codebase** and has since been fixed
(§12). They are kept because they are the mistakes this project actually made,
and the shape of a mistake is more useful than the rule it breaks. Do not
reintroduce them.

**Authorization inside a handler**
```php
// app/Modules/Auth/Controllers/UserController.php  ✗ (fixed)
if ($user->id === $request->user()->id) {
    return $this->error('لا يمكنك حذف حسابك الخاص.', 422);
}
```
Breaks C2, C10, C16 (magic literal instead of the envelope helpers), and — worst —
it protects only this path. `UserService::delete()` called from a console command
had no such guard. **Move the rule into the service.** ✅ Now
`UserService::delete(User, ?int $actingUserId)` throwing
`CannotDeleteOwnAccountException`; the parameter is required with no default, so
a caller must state who is acting or explicitly pass null.

**A controller doing repository work**
```php
// app/Modules/Auth/Controllers/RoleController.php  ✗ (fixed)
$user = User::findOrFail($request->validated('user_id'));
$role = Role::findOrFail($request->validated('role_id'));
```
Breaks C3. Also produced a `404` whose message leaked the model class.
**Pass the ids to the service.** ✅ `RoleService::assign(int, int)` loads them.

**A service reaching for the request**
```php
// app/Shared/Services/AuditLogService.php  ✗ (fixed)
'ip_address' => Request::ip(),
'user_agent' => Request::userAgent(),
```
Breaks C1. Made every service that audits require an HTTP context to unit-test,
and wrote silent nulls from the CLI. **Pass them in.** ✅ `RequestContext` is
constructor-injected.

**A domain exception that knows HTTP**
```php
// app/Modules/Departments/Exceptions/DepartmentIsRootException.php  ✗ (fixed)
public function render(Request $request): ?JsonResponse
{
    return response()->json(['success' => false, 'message' => …], $this->statusCode);
}
```
Breaks C16, C17. Thrown from a model hook, so the domain imported
`Illuminate\Http\Request`. The envelope was duplicated in seven places, so
changing it meant seven edits. **Carry the status code as data; let the central
mapper render.** ✅ `implements DomainException` with `statusCode(): int`.

**A middleware building its own envelope**
```php
// app/Shared/Middleware/CheckPermission.php  ✗ (fixed)
return response()->json(['success' => false, 'message' => '…'], 403);
```
Breaks C16 and §3's layer contract, which lets a middleware reject early with a
status code but never hand-build JSON. It is the same duplication as the
exceptions above, in a layer where it is easier to miss. **Use `ApiRespond`.**

**A whole group excluded from `audit` to silence two of its routes**
```php
// app/Modules/Auth/routes.php  ✗ (fixed)
Route::middleware('auth:sanctum')->prefix('notifications')->group(...)  // no 'audit'
```
Breaks C13. The exclusion was right for the two polled read endpoints and wrong
for the write sitting between them, which changed state and left no trace
anywhere. **Exclude routes, not groups** — and when you exclude one, say in a
comment which routes it covers and why, so the next route added to the group
does not inherit an exemption nobody chose for it.

**A rule that lives only in a FormRequest** — the mistake this project already
paid for. It produced four roots in the org tree because the seeder never crossed
the HTTP layer. The fix became the model's `saving` hook plus a shared guard, and
that pattern — ⚙️ `DepartmentHierarchyGuard`, called from both
`DepartmentRequest::after()` and `Department::booted()` — is the reference
implementation for C4/C5. **Copy that shape.**

**A green suite that asserts only status codes.** Not a code smell, and the most
expensive one here. Every list defect in GAP-11 and every envelope defect in
GAP-2 shipped under 143 passing tests, because the tests asserted `assertOk()`
and `assertForbidden()` and never once looked at a body. `grep` for `per_page`,
`page=2` or `meta` across `tests/` returned nothing at all. **Assert the shape of
what you return, not just the status you return it with.**

---

## What this project already does well — keep doing it

Three patterns are worth protecting, not just preserving:

1. **`DepartmentHierarchyGuard`** — one pure, framework-free class owning every
   structural invariant, invoked from *both* the FormRequest (friendly `422` with
   Arabic field errors) and the model hook (hard refusal on every other write
   path). This is C4 and C5 made concrete, and it is the best design in the repo.
2. **Route-declared, verb-level permissions.** Twenty routes, zero duplicated
   authorization inside a handler, cleanly separated from authentication by
   status code. Do not move any of it into a controller.
3. **Services that never see HTTP.** All six take scalars, arrays, or models.
   `AuthService::login(string $email, string $password)` is the reference
   signature. Keep that line clean.

---

*Written 2026-09-08, from a stage-by-stage architecture review of the running
code. When a rule here and the code disagree, check §12 first — the disagreement
is probably already recorded. If it is not, the code is a new gap, not a new rule.*
