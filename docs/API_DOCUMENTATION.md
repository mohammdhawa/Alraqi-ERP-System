# Al‑Raqi ERP — API Documentation (Phase 1)

> Reference for frontend implementation. Covers every endpoint shipped in Phase 1:
> **Auth**, **Users**, **Roles & Permissions (RBAC)**, **Notifications**, **Departments**, and **HR / Employees**.
>
> ⚠️ **ALSO 2026-09-23 — `viewSalary` / `viewIdentity` now gate WRITES.**
> Sending a pay field or `national_id` without the matching permission is a
> **422** naming the field, and `null` is refused like a value. Employee rows in
> the audit trail need `hr.employees.viewSalary` for their `old_values` /
> `new_values`. See §2 (field-level permissions) and §9.
>
> ⚠️ **BREAKING, 2026-09-23 — login takes `login`, not `email`.** Every client
> must change its login call. The identifier is now the employee number, an
> account may have no email at all, and a new **`POST /api/auth/password`**
> exists. While `must_change_password` is `true` the API answers **403** on
> everything except that route, `/auth/logout` and `/auth/me`. Read §3.1 and
> §3.5 before touching the auth code.
>
> ⚙️ **Changed 2026-09-23 — `legacy_number` is gone.** The employee payload no
> longer carries it, `search` no longer matches it, and it is rejected on create
> and update. The company keeps **one** staff number — `employee_number` — and a
> hand-typed `M024333` is stored exactly as given, so searching by a number off a
> paper file still works. **If a list column or a profile header shows a separate
> old-number field, remove it**; `FRONTEND_BATCH2_WORK_ORDER.md` asked for one
> and that instruction is withdrawn.

---

## 1. Conventions

### Base URL

```
{APP_URL}/api
```

All routes below are relative to `/api`. Each module is mounted under its own prefix:

| Module        | Prefix               |
| ------------- | -------------------- |
| Auth / RBAC   | `/api/auth`          |
| Notifications | `/api/auth/notifications` |
| Departments   | `/api/departments`   |
| HR            | `/api/hr`            |

### Content type

- Send `Content-Type: application/json` and `Accept: application/json` on every request.
- Always send `Accept: application/json` — it makes Laravel return JSON errors (e.g. 401/422) instead of HTML redirects.

### Authentication

Authentication uses **Laravel Sanctum bearer tokens** with a custom refresh‑token rotation layer.

- Send the access token on every protected request:

  ```
  Authorization: Bearer {access_token}
  ```

- **Access token** lifetime: **15 minutes** by default (`expires_in: 900` seconds).
  Read `expires_in` from the login/refresh response rather than hardcoding 900 —
  it is derived from server config (`SANCTUM_TOKEN_EXPIRATION`) and is `null` if
  access tokens are configured never to expire.
- **Refresh token** lifetime: **30 days**, single‑use (rotated on every refresh).
- On `401 Unauthorized`, call `POST /api/auth/refresh` with the refresh token to obtain a new pair, then retry the original request.
- Tokens are per‑user and replace each other: logging in (or refreshing) **deletes the previous access token**, so a user effectively holds one active access token at a time.

**Recommended client storage**

| Token         | SPA (browser)                        | Mobile             |
| ------------- | ------------------------------------ | ------------------ |
| access_token  | in‑memory                            | secure storage     |
| refresh_token | JS‑readable store (see note)         | secure storage     |

> ⚠️ **Not an httpOnly cookie (Phase 1).** `POST /api/auth/refresh` reads the refresh token from the **JSON request body** — there is no cookie-reading path on the server. An httpOnly cookie can't be read by JS to put it in the body, so it won't work. Store the refresh token where your SPA code can read it (e.g. memory + a `localStorage`/`sessionStorage` fallback to survive reloads), accepting the usual XSS trade‑off. Keeping it out of the body would require a backend change.

### Standard response envelope

Almost every endpoint returns the **same envelope**, so you can write a single parser.

> ✅ **There are now NO exceptions. Every response carries `success`** — success and error, application-generated and framework-generated, in every environment.
>
> **This changed on 2026-09-08, and it changed in your favour.** The previous
> version of this section listed `401` and `429` as the only two bare responses.
> That was wrong, and wrong in the direction that costs you: `422` validation —
> the most common error any client handles — also arrived with **no `success`
> key**, as did `404`, `405` and `5xx`. The `404` from a bad id additionally
> leaked the internal model class name into `message`. All of it now goes
> through one mapper.
>
> **What this means for your code**
>
> - You can now read `success` on **every** response, including `401` and `429`.
> - **Keep triggering your refresh interceptor on the HTTP `401` status**, not on
>   `success === false`. That advice has not changed and is still correct — a
>   `403` also carries `success: false` and must never trigger a refresh.
> - `401` and `429` messages are now Arabic and display-ready like everything
>   else. If you were special-casing the English `"Unauthenticated."` or
>   `"Too Many Requests"` strings, delete that branch.
> - `429` still carries `Retry-After` (seconds) and `X-RateLimit-*` headers, and
>   `405` still carries `Allow`. Those are unchanged.
> - `message` on a `404` is now a generic Arabic string. Do not parse it — a
>   missing row and an unknown route deliberately return the same text, so that a
>   `404` never reveals which rows exist.
>
> 🆕 **`X-Request-Id` on every response.** Each response carries a correlation id,
> and it is recorded on the matching audit rows. If you send your own
> `X-Request-Id` (up to 64 chars of `A-Za-z0-9._-`) it is honoured and echoed
> back; anything else is replaced. Send one per user action and log it, and a bug
> report becomes traceable to the exact server-side rows that handled it.

**Success**

```json
{
  "success": true,
  "message": "تمت العملية بنجاح.",
  "data": { }
}
```

- `data` is omitted entirely when there is nothing to return (e.g. logout, delete).
- `data` may be an object or an array depending on the endpoint.
- `message` is a human‑readable Arabic string suitable for display (toast/snackbar).

**Error**

```json
{
  "success": false,
  "message": "فشل التحقق من البيانات المُدخلة.",
  "errors": {
    "email": ["يوجد مستخدم مسجّل بهذا البريد الإلكتروني بالفعل."]
  }
}
```

- `errors` is present only on validation failures (HTTP 422) and mirrors Laravel's field‑keyed error bag.
- For non‑validation errors (401/403/404/422 business rules), only `message` is returned.

### HTTP status codes

| Code | Meaning                          | When                                                            |
| ---- | -------------------------------- | --------------------------------------------------------------- |
| 200  | OK                               | Successful read/update/delete/action                            |
| 201  | Created                          | Successful `POST` that creates a resource                       |
| 401  | Unauthorized                     | Missing/expired/invalid access token; bad login credentials; refresh-token failures |
| 403  | Forbidden                        | Authenticated but missing the required permission; **disabled account on login** |
| 404  | Not Found                        | Resource id does not exist (or is not owned, for notifications) |
| 422  | Unprocessable Entity             | Validation failed, or a business rule blocked the action        |
| 429  | Too Many Requests                | Rate limit hit (login/refresh)                                  |
| 500  | Server Error                     | Unhandled error                                                 |

### Validation messages language

Most validation/business messages are returned in **Arabic**. Display `message` directly; use `errors` for per‑field highlighting.

### Timestamps ⚠️ (applies to every endpoint, not just one)

**Every timestamp this API returns is ISO‑8601 carrying the `+03:00` offset.**

```json
"created_at": "2026-06-21T10:00:00+03:00"
```

The app timezone is **Asia/Damascus**, and every resource serialises datetimes
with Carbon's `toIso8601String()`, which preserves the instance's zone. So
`created_at`, `updated_at`, `last_login_at`, `email_verified_at` and every other
`_at` field carry `+03:00` — on Auth, Users, Roles, Notifications, Departments,
HR and the audit log alike. There is no endpoint that returns UTC.

**Parse the offset the server sent. Do not re‑zone it, and do not strip it.** A
client that treats these as UTC renders the entire system three hours early —
and it is a *plausible* three hours, which is what makes it dangerous: the dates
stay right, nothing looks broken, and an evening event reads as afternoon.

**The one exception, and it is not a serialised field.** Inside the audit log's
`old_values` / `new_values` (§9.2), a timestamp appears as the raw stored
column — `"updated_at": "2026-09-20 11:24:23"` — in DB format, Damascus local,
with **no offset and no `T`**. Those blobs are a verbatim copy of the audited
row, not a rendered resource, and putting an offset on them at read time would
mean rewriting history on an append-only table. **Read a bare timestamp as
Damascus local**; that is what the server means by one, and it is the only place
you will meet it.

> ⚠️ **Corrected 2026‑09‑20.** Every JSON example in this document below §1 used
> to show `+00:00`. That was wrong for all of them — seventeen examples across
> six sections — and nothing in the API ever behaved that way. The rule was
> stated only in §9.6, where it read as a quirk of the audit log rather than as
> what it is: a property of every response. `TimestampContractTest` now sweeps
> every `_at` key of every shipped endpoint, at any depth, so an example and the
> wire cannot drift apart again without failing the build.

### Embedded objects (an FK and the thing it points at)

Where a resource carries a foreign key you would otherwise resolve yourself, it
ships the id **and** the referenced record beside it, under the same name
without the `_id`:

```json
"manager_id": 12,
"manager": { "id": 12, "full_name": "سامر خليل", "employee_number": "E-0012" }
```

- **The `*_id` is unchanged and is the write contract.** It is what you `POST` /
  `PUT` back, and what permission fallbacks and client-side rules read. The
  embedded object is read‑only decoration beside it — never send it back.
- **The object is `null`, never missing, when there is nobody to name** — the FK
  is null, or it points at a soft‑deleted row you could not fetch either way.
- ⚠️ **A missing key means something else entirely: "you may not see this
  field."** That is how field‑level gating is expressed (`national_id`, the pay
  block — see [§8.1](#81-employee-object)). Never treat an absent key and a
  `null` as the same thing.
- **A person is always `{ id, full_name, employee_number }`**, wherever they are
  embedded. `employee_number` is in there because الاسم الثلاثي is *not* unique
  at this headcount — two people genuinely share one, and the staff number is
  what tells them apart on screen.
- **Embedded people are not permission‑gated.** Whoever may read the owning
  record may see who the referenced person is; neither `full_name` nor
  `employee_number` sits behind `viewIdentity` / `viewSalary`.

Every embed below is resolved server‑side with a single query per page — reading
a list never costs you a request per row.

| Resource | FK | Embedded object |
| --- | --- | --- |
| Department | `manager_id` | `manager` — the employee who runs the unit |
| User | `employee_id` | `employee` — the person the account belongs to |
| Employee | `department_id` · `job_title_id` · `direct_manager_id` | `department` (`{id, name, level}`) · `job_title` (`{id, name}`) · `direct_manager` |
| Employee warning | `issued_by` | `issuer` — who issued it |
| Posting (assignment) | `department_id` · `job_title_id` | `department` · `job_title` |

### Pagination ⚠️ (important for list endpoints)

**Every list endpoint in this API is paginated, and they all page identically** —
`departments.index`, `hr/employees.index`, `notifications.index`,
`auth/users.index` and `auth/roles.index`. Write one pager; it works on all five.

- Request a page with the query string: `?page=2`.
- Request a page size with `?per_page=50`. **Default 15, clamped to 1–100.**
  Out-of-range values are clamped rather than rejected, so `?per_page=1000000`
  returns 100 and `?per_page=0` returns 1 — neither is an error. A non-numeric
  value falls back to 15. `meta.per_page` always reports the size actually
  applied, so read it back rather than assuming your request was honoured.
- An out-of-range `?page=99` is a **`200`** with `"data": []` and correct `meta`,
  never a `404`.
- `data` is a **flat array of items**, and pagination metadata is returned alongside it under a sibling **`meta`** key:

```json
{
  "success": true,
  "message": "تم جلب الوحدات التنظيمية.",
  "data": [ { "...item..." } ],
  "meta": {
    "current_page": 1,
    "last_page": 4,
    "per_page": 15,
    "total": 52,
    "from": 1,
    "to": 15
  }
}
```

| `meta` field   | Meaning                                          |
| -------------- | ------------------------------------------------ |
| `current_page` | the page returned                                |
| `last_page`    | total number of pages (build pager from this)    |
| `per_page`     | items per page actually applied (after clamping) |
| `total`        | total item count across all pages                |
| `from` / `to`  | 1‑based index range of items on this page (`null` when the page is empty) |

These six keys are the **whole** contract — there is no `links` or `path`, and
`meta` is a sibling of `data`, never nested inside it. `data` is **always an
array**, never an object keyed by id and never `null`; an empty page is `[]` with
`from`/`to` set to `null`. `last_page` is `1` when there are no results, never `0`.

> ⚠️ **Changed 2026-09-08 — action needed if you consume roles or users.**
> `/api/auth/roles` and `/api/auth/users` previously returned the **full
> collection** with no `meta`. They are now paginated like everything else, so
> they return **15 items by default instead of all of them**. If you were relying
> on receiving every row in one response — populating a role dropdown, for
> instance — page through with `?per_page=100` or read `meta.last_page`. This is
> the one breaking change in this revision, and `users` is about to grow past 242
> rows in Batch 4, so the old behaviour was not survivable.
>
> Also changed: `POST /api/auth/roles/assign` and `/unassign` now return the
> updated user (with their resulting `roles`) in `data`, where they previously
> returned a message only. Purely additive — existing `message`-only clients are
> unaffected — but it saves you a follow-up request to refresh what you changed.

---

## 2. RBAC model (permissions)

Protected write/read routes are guarded by **permission middleware**. A user gets permissions through the **roles** assigned to them.

**The built‑in `super_admin` role** (seeded, `is_system: true`, label `مدير النظام`) holds **no explicit permissions** — its holders **bypass every permission check** at the gate level. New permissions added in later phases are instantly available to super admins with no re‑seed. In the login/`me` payload a super admin's `permissions` array is expanded to the **entire catalogue**, so a permission‑gated UI works unchanged for them. System roles cannot be renamed or deleted (see [5.6](#56-delete-role)).

Roles and permissions both carry an Arabic **`label`** for display; `name` is the stable machine identifier.

Permission string format: `{module}.{resource}.{action}`.

**Permission catalogue — 30 rows** (27 route-level + 3 field-level)

| Module            | Permissions                                                                                  |
| ----------------- | -------------------------------------------------------------------------------------------- |
| Departments       | `departments.view`, `departments.create`, `departments.update`, `departments.delete`         |
| HR – Employees    | `hr.employees.view`, `hr.employees.create`, `hr.employees.update`, `hr.employees.delete`     |
| HR – fields       | `hr.employees.viewSalary`, `hr.employees.viewIdentity` — **field-level**, see below           |
| HR – Job titles   | `hr.job_titles.view`, `hr.job_titles.create`, `hr.job_titles.update`, `hr.job_titles.delete` |
| HR – Assignments  | `hr.assignments.view`, `hr.assignments.create` — **no update or delete**, see [§8.3](#83-posting-history--سجل-التعيين) |
| HR – Warnings     | `hr.warnings.view`, `hr.warnings.create`, `hr.warnings.update`, `hr.warnings.delete`         |
| Auth – Users      | `auth.users.view`, `auth.users.create`, `auth.users.update`, `auth.users.delete`             |
| Auth – Roles      | `auth.roles.view`, `auth.roles.create`, `auth.roles.update`, `auth.roles.delete`             |
| Auth – Audit      | `auth.audit.view` — **no create/update/delete**, see [§9](#9-audit-log--سجل-التدقيق); `auth.audit.viewValues` — **field-level**, see below |

> `hr.assignments` has no `update` or `delete` **by design**, not by omission:
> سجل التعيين is append-only, because editing a historical posting changes what
> a payslip was computed against with no trace of the original.
>
> `auth.audit` has no write action at all, for the same reason taken further:
> `audit_logs` is append-only by design and in production the DB user should
> hold INSERT and SELECT only. There is no permission to grant, because there is
> no such power to hold.

### Field-level permissions (new, 2026-09-08)

These three guard **fields inside a payload**, not routes. No endpoint requires
them; they decide whether certain keys appear in a response:

| Permission | Unlocks |
| --- | --- |
| `hr.employees.viewSalary` | `salary_mode(_label)`, `agreed_salary`, `sham_cash_account`, and **every classification axis** — `activity_type(_label)`, `organizational_grade`, `experience_level`, `engineering_rank(_label)`, `industrial_experience_years`, `has_hazard_pay`, `has_scarcity_pay`, `has_dual_role_pay`, `distinction_grade` |
| `hr.employees.viewIdentity` | `national_id` |
| `auth.audit.viewValues` | `old_values` and `new_values` on `GET /api/auth/audit-logs/{id}` — see [§9](#9-audit-log--سجل-التدقيق) |

**Without the permission the key is ABSENT, not `null`.** Do not read
`employee.salary === null` as "this employee has no salary" — check whether the
key exists, or gate your UI on the permission array you already receive from
login and `/me`. A `null` would be a claim about the employee; an absent key is a
statement about the reader.

They are separate on purpose: payroll needs pay and not identity documents, HR
administration often needs the reverse. Previously both fields were visible to
anyone holding plain `hr.employees.view`.

Batch 2 extends `viewSalary` to `agreed_salary`, `sham_cash_account` and the
classification axes (`activity_type`, `organizational_grade`, …) — the axes are
the inputs the salary is computed from, so hiding the result while exposing them
would be theatre.

> ⚠️ **NEW 2026-09-23 — these permissions now gate WRITES too: من لا يرى لا
> يكتُب.** Sending any field in the table above on `POST /api/hr/employees` or
> `PUT/PATCH /api/hr/employees/{id}` **without the matching permission is a
> 422**, with the error on that field:
>
> ```json
> { "errors": { "agreed_salary": ["لا تملك صلاحية تعديل حقول الرواتب ومعاييرها."] } }
> ```
>
> **The field must be ABSENT, not `null`.** Sending `"agreed_salary": null` is
> refused exactly like sending a value — otherwise a caller who cannot read a
> salary could clear one. This is the server half of the rule your UI already
> follows: *don't render, don't send*. A payload that reaches the API carrying a
> gated field is a client bug, and the 422 names which field it was.

A missing permission yields:

```json
{ "success": false, "message": "ليس لديك الصلاحيات الكافية لتنفيذ هذا الإجراء." }
```
HTTP `403`.

> **Frontend tip:** both the **login** response and `GET /api/auth/me` include the current user's `roles` (names) **and a flattened `permissions` array** — the full set of permission strings granted across all their roles, de‑duplicated. Cache `permissions` and gate your UI on it directly (e.g. `can('hr.employees.create')`). This works for **every** user, not just admins — you do **not** need `auth.roles.view` to discover your own permissions. Hide/disable actions the user can't perform to avoid 403s.
>
> The admin Roles/Users endpoints (§5, §4) are for **managing** other accounts' RBAC, not for discovering your own.

---

## 3. Auth endpoints

### 3.1 Login

```
POST /api/auth/login
```
**Public.** Rate limited to **5 requests/minute per IP + identifier**.

> ⚠️ **BREAKING, 2026-09-23 — the field is `login`, not `email`.** Sending
> `email` is now a 422. The identifier is the **employee number**
> (`EMP-00042`), because most site staff have no email address and every
> employee needs an account: attendance is recorded through the app. An account
> with no employee — the technical department, `super_admin` — still signs in
> with its email, through the same field. **One field, either value; the server
> works out which.**

**Request body**

| Field      | Type   | Rules                                                     |
| ---------- | ------ | --------------------------------------------------------- |
| `login`    | string | required, max 255 — an employee number **or** an email      |
| `password` | string | required, 8–128 chars                                       |

```json
{ "login": "EMP-00042", "password": "secret123" }
```

**200 Response**

```json
{
  "success": true,
  "message": "تم تسجيل الدخول بنجاح.",
  "data": {
    "user": {
      "id": 1,
      "name": "Test User",
      "email": "admin@example.com",
      "is_active": true,
      "must_change_password": false,
      "roles": ["super_admin"],
      "permissions": [
        "auth.users.view", "auth.users.create", "auth.users.update", "auth.users.delete",
        "auth.roles.view", "auth.roles.create", "auth.roles.update", "auth.roles.delete",
        "departments.view", "departments.create", "departments.update", "departments.delete",
        "hr.employees.view", "hr.employees.create", "hr.employees.update", "hr.employees.delete"
      ],
      "last_login_at": "2026-06-21T09:59:00+03:00",
      "email_verified_at": null,
      "created_at": "2026-06-21T10:00:00+03:00"
    },
    "access_token": "1|xxxxxxxxxxxxxxxxxxxxxxxx",
    "refresh_token": "64-character-random-string",
    "token_type": "Bearer",
    "expires_in": 900
  }
}
```

- `name` is **not stored on the account** — it is the name of the **linked HR employee** (`employee_id`). An account with no employee link has `name: null`.
- A super admin's `permissions` is the full catalogue (their bypass grants everything); other users get the union of their roles' permissions.
- `last_login_at` is the previous successful login (`null` on the very first).

**Errors**

- `401` — invalid credentials: `{ "message": "بيانات الاعتماد غير صحيحة." }` (generic on purpose — does not reveal whether the email exists).
- `403` — disabled account (`is_active = false`): `{ "message": "تم تعطيل هذا الحساب." }`. Note this is **403, not 401** — do **not** route it through the token-refresh flow; show an "account disabled" state.
- `422` — validation failure (e.g. `email` / `password` missing or malformed). Per‑field Arabic messages in `errors`.
- `429` — too many attempts (5/min/IP). Read the `Retry-After` header.

---

### 3.2 Refresh tokens

```
POST /api/auth/refresh
```
**Public.** Rate limited to **10 requests/minute per IP** (`429` returns `{ "message": "Too Many Requests" }` + `Retry-After` header).

**Request body**

| Field           | Type   | Rules                  |
| --------------- | ------ | ---------------------- |
| `refresh_token` | string | required, exactly 64 chars (`422` `صيغة رمز التحديث غير صحيحة.` if malformed) |

**200 Response** (a brand‑new pair; the old refresh token is now revoked)

```json
{
  "success": true,
  "message": "تم تحديث الرموز بنجاح.",
  "data": {
    "access_token": "2|yyyyyyyyyyyyyyyyyyyy",
    "refresh_token": "new-64-character-random-string",
    "token_type": "Bearer",
    "expires_in": 900
  }
}
```

**Errors (all `401`)** — `message` varies:

- `رمز التحديث غير صالح.` — token not found.
- `انتهت صلاحية رمز التحديث.` — expired.
- `الحساب غير مُفعّل.` — account disabled.
- `تم إبطال هذا الرمز. تم إنهاء جميع الجلسات حفاظًا على الأمان.` — **token reuse detected**. The refresh token was already used once; the server treats this as theft and **revokes all of the user's sessions**. The client must force a full re‑login.

> **Single‑use rule:** never retry `/refresh` with the same refresh token. Always store the new refresh token returned by each call.

---

### 3.3 Logout

```
POST /api/auth/logout
```
**Protected** (`Authorization: Bearer`). Revokes **all** access + refresh tokens for the user (logs them out on every device).

**200 Response**

```json
{ "success": true, "message": "تم تسجيل الخروج بنجاح." }
```

---

### 3.4 Current user (me)

```
GET /api/auth/me
```
**Protected.** Returns the authenticated user's profile **including their `roles` and flattened `permissions`**. Use it to verify a session on app boot and to (re)hydrate the permission set you gate the UI on. Same shape as the `user` object in the login response.

**200 Response**

```json
{
  "success": true,
  "message": "تم جلب بيانات المستخدم الحالي.",
  "data": {
    "id": 1,
    "name": "Test User",
    "email": "admin@example.com",
    "is_active": true,
    "must_change_password": false,
    "roles": ["super_admin"],
    "permissions": ["auth.users.view", "auth.roles.view", "hr.employees.view", "..."],
    "last_login_at": "2026-06-21T09:59:00+03:00",
    "email_verified_at": null,
    "created_at": "2026-06-21T10:00:00+03:00"
  }
}
```

- `name` is the **linked employee's** name (`null` when the account has no employee link).
- `email` **may be `null`** since 2026-09-23. It is a contact field, not the identity — see §3.1.
- `permissions` is the **union** of every permission across the user's roles, de‑duplicated. A user with no roles gets `[]`. A **super admin** gets the **entire catalogue**.
- `roles` is an array of role names.
- ⚠️ `must_change_password` — when `true`, **open the change-password screen, not the dashboard.** Every other endpoint answers 403 until it is cleared. See §3.5.

---

### 3.5 Change your own password

```
POST /api/auth/password
```
**Protected. Self-scoped — no permission required**, because it acts only on the caller's own credential.

> **Why this exists.** The first password of every account is issued by HR, so it is a secret more than one person knows. The account carries `must_change_password` until it is replaced, and while that flag is set **the API refuses every route except this one, `/auth/logout` and `/auth/me`** with a **403**.
>
> **403, not 401 — do not refresh the token and do not bounce to login.** The session is valid; the *action* is refused. Treating it as an auth failure produces an infinite loop, because re-authenticating changes nothing.

**Request body**

| Field                   | Type   | Rules                                                        |
| ----------------------- | ------ | ------------------------------------------------------------ |
| `current_password`      | string | required                                                      |
| `password`              | string | required, 8–128, **confirmed**, must differ from the current   |
| `password_confirmation` | string | required, must match `password`                               |

**200 Response** — a **fresh token pair**, because a successful change revokes every session including the caller's:

```json
{
  "success": true,
  "message": "تم تغيير كلمة المرور بنجاح. تم إنهاء الجلسات الأخرى.",
  "data": {
    "access_token": "3|xxxxxxxxxxxxxxxxxxxxxxxx",
    "refresh_token": "64-character-random-string",
    "token_type": "Bearer",
    "expires_in": 900
  }
}
```

⚠️ **Replace your stored tokens with these.** Every previously issued token for this account — including the one that made this request — is dead by the time the response arrives. That is deliberate: if somebody else had signed in with the temporary password, a change that spared their session would have locked nobody out.

**Failures**

| Status | When |
| ------ | ---- |
| **401** | `current_password` is wrong (`كلمة المرور الحالية غير صحيحة.`) |
| **422** | The new password is too short, unconfirmed, or identical to the current one |

---

## 4. Users (admin)

All routes are **protected** and guarded by `auth.users.*` permissions. Base path `/api/auth/users`.

> Role grants are **not** handled here — use the dedicated [assign/unassign endpoints](#54-assign-role-to-user). This keeps a single source of truth for RBAC changes.

**User object shape**

```json
{
  "id": 5,
  "name": "Jane Doe",
  "email": "jane@example.com",
  "is_active": true,
  "employee_id": 3,
  "employee": { "id": 3, "full_name": "Jane Doe", "employee_number": "E-0003" },
  "roles": ["manager"],
  "last_login_at": "2026-06-21T09:59:00+03:00",
  "email_verified_at": null,
  "created_at": "2026-06-21T10:00:00+03:00"
}
```

- **An account stores no name of its own.** `name` is the name of the **linked HR employee** (`employee_id` → employees); it is `null` for an unlinked account. To "rename" a user you rename the employee (HR endpoints) or re‑link `employee_id`.
- 🆕 `employee` is that same person as an object — `{ id, full_name, employee_number }` — so a user list can show **who** each account belongs to without a lookup per row. It is **`null`, never absent**, for an unlinked account (or one whose employee was deleted); see [Embedded objects](#embedded-objects-an-fk-and-the-thing-it-points-at). `name` is unchanged and still the account's display name.
- `employee_id` is **required on create** and unique — at most **one account per employee**. It remains the write contract: send `employee_id`, never `employee`.
- `roles` is an array of **role names**, included only when the relation is loaded (it is loaded on all Users endpoints). Grant/revoke roles via the [assign/unassign endpoints](#54-assign-role-to-user) — not here.
- `last_login_at` is the last successful login (`null` if the account never logged in).

### 4.1 List users

```
GET /api/auth/users          (permission: auth.users.view)
```
Paginated (15/page, `?page=N`, `?per_page=N`) — `data` is an array of user objects with the six `meta` keys beside it. Message: `تم جلب المستخدمين.`

### 4.2 Create user

```
POST /api/auth/users         (permission: auth.users.create)
```

| Field         | Type    | Rules                                                                       |
| ------------- | ------- | --------------------------------------------------------------------------- |
| `employee_id` | integer | **required**; must be an existing, non‑deleted employee; must not already have an account |
| `email`       | string  | **optional, nullable** since 2026-09-23, email, max 255, unique when present |
| `password`    | string  | required, min 8                                                             |
| `is_active`   | boolean | optional (defaults to `true`)                                               |

**201 Response** → `{ data: <user object>, message: "تم إنشاء المستخدم." }`
**422** — `employee_id.required`: `يجب ربط الحساب بموظف.` · `employee_id.exists`: `الموظف المحدد غير موجود.` · `employee_id.unique`: `هذا الموظف مرتبط بحساب مستخدم بالفعل.` · `email.unique`: `يوجد مستخدم مسجّل بهذا البريد الإلكتروني بالفعل.`

### 4.3 Update user

```
PUT|PATCH /api/auth/users/{user}   (permission: auth.users.update)
```
Partial update — all fields optional.

| Field         | Type    | Rules                                                                        |
| ------------- | ------- | ---------------------------------------------------------------------------- |
| `employee_id` | integer | optional; re‑links the account to another existing, non‑deleted employee not already linked elsewhere |
| `email`       | string  | optional, **nullable**, email, max 255, unique (ignores this user)           |
| `password`    | string  | optional, nullable, min 8 (re‑hashed when sent)                              |
| `is_active`   | boolean | optional                                                                     |

There is **no `name` field** — the display name always tracks the linked employee.

**200 Response** → `{ data: <user object>, message: "تم تحديث المستخدم." }`

### 4.4 Delete user

```
DELETE /api/auth/users/{user}      (permission: auth.users.delete)
```
**200 Response** → `{ message: "تم حذف المستخدم." }`
**422** — you cannot delete your own account: `لا يمكنك حذف حسابك الخاص.`

---

## 5. Roles & Permissions (RBAC)

All routes **protected**, base path `/api/auth/roles`, guarded by `auth.roles.*`.

**Role object shape**

```json
{
  "id": 2,
  "name": "manager",
  "label": "مدير قسم",
  "description": "Department managers",
  "is_system": false,
  "permissions": ["hr.employees.view", "hr.employees.update"],
  "created_at": "2026-06-21T10:00:00+03:00"
}
```

- `permissions` is an array of **permission names**, included only when loaded.
- `label` is the Arabic display name; `name` is the stable machine identifier.
- `is_system: true` marks a built‑in role (`super_admin`) that **cannot be renamed or deleted** (`409`). It is set by the system only — the API never accepts it.

### 5.1 List roles

```
GET /api/auth/roles          (permission: auth.roles.view)
```
Full collection in `data`. Message: `تم جلب الأدوار.`

### 5.2 Create role

```
POST /api/auth/roles         (permission: auth.roles.create)
```

| Field           | Type     | Rules                                                       |
| --------------- | -------- | ----------------------------------------------------------- |
| `name`          | string   | required, max 255, **unique**                               |
| `label`         | string   | optional, nullable, max 255 (Arabic display name)           |
| `description`   | string   | optional, nullable, max 255                                 |
| `permissions`   | string[] | optional; each must be an **existing permission name**      |

```json
{
  "name": "hr_clerk",
  "label": "موظف إدخال بيانات",
  "description": "HR data entry",
  "permissions": ["hr.employees.view", "hr.employees.create"]
}
```

**201 Response** → `{ data: <role object>, message: "تم إنشاء الدور." }`
**422** — `name.unique`: `يوجد دور بهذا الاسم بالفعل.` / unknown permission: `واحدة أو أكثر من الصلاحيات المحددة غير موجودة.`

### 5.3 Update role

```
PUT|PATCH /api/auth/roles/{role}   (permission: auth.roles.update)
```
Partial update.

| Field         | Type     | Rules                                                              |
| ------------- | -------- | ------------------------------------------------------------------ |
| `name`        | string   | optional, max 255, unique (ignores this role)                      |
| `label`       | string   | optional, nullable, max 255                                        |
| `description` | string   | optional, nullable, max 255                                        |
| `permissions` | string[] | optional — **replaces** the role's entire permission set (sync)    |

> Sending `permissions` **overwrites** the existing set (sync). Omit the field to leave permissions unchanged. ⚠️ Sending `"permissions": []` (an empty array) **removes all permissions** from the role — it is not the same as omitting the field.

**200 Response** → `{ data: <role object>, message: "تم تحديث الدور." }`
**409** — system role (`is_system`, e.g. `super_admin`): `لا يمكن تعديل أو حذف دور نظام.`

### 5.4 Assign role to user

```
POST /api/auth/roles/assign        (permission: auth.roles.update)
```

| Field     | Type    | Rules                          |
| --------- | ------- | ------------------------------ |
| `user_id` | integer | required, must exist in users  |
| `role_id` | integer | required, must exist in roles  |

**200 Response** → `{ message: "تم إسناد الدور 'manager' إلى jane@example.com." }`
**422** — unknown user/role: `المستخدم المحدد غير موجود.` / `الدور المحدد غير موجود.`

### 5.5 Unassign role from user

```
POST /api/auth/roles/unassign      (permission: auth.roles.update)
```
Same body as assign. **200 Response** → `{ message: "تم سحب الدور 'manager' من jane@example.com." }`

### 5.6 Delete role

```
DELETE /api/auth/roles/{role}      (permission: auth.roles.delete)
```
**200 Response** → `{ message: "تم حذف الدور." }`
**409** — system role (`is_system`, e.g. `super_admin`) — the RBAC bootstrap is protected on **every** path, not just this endpoint: `لا يمكن تعديل أو حذف دور نظام.`

> Assigning/unassigning a system role to users **is** allowed — only mutating the role itself is blocked.

---

## 6. Notifications

Base path `/api/auth/notifications`. **Protected** (auth only — no permission needed; every operation is scoped to the **authenticated user's own** notifications). These endpoints are designed for frequent polling.

**Notification object shape**

```json
{
  "id": 12,
  "title": "New employee added",
  "body": "Jane Doe joined Engineering.",
  "type": "employee_created",
  "reference": { "type": "employee", "id": 5 },
  "is_read": false,
  "created_at": "2026-06-21T10:00:00+03:00"
}
```

- `reference` is `null` when the notification isn't linked to a record; otherwise a `{ type, id }` pair you can use to deep‑link.
- `type` / `body` may be `null`.

> ⚠️ **Changed 2026-09-08 — `reference.type` is now a short alias.** It
> previously returned the PHP class name (`App\Modules\HR\Models\Employee`), and
> this document published that as the contract. It now returns a stable
> lower-case alias, and the set is closed:
>
> | `reference.type` | Record |
> | --- | --- |
> | `employee` | an HR employee |
> | `department` | an organizational unit |
> | `user` | a login account |
> | `role` | an RBAC role |
> | `permission` | a permission |
>
> **If you were switching on the class name, switch on the alias instead.** The
> aliases are deliberately decoupled from PHP namespaces, so a model moving
> between modules — which the legacy merge will do — no longer changes what you
> receive. Any new type will be added to this table, never as a raw class name.

### 6.1 List my notifications

```
GET /api/auth/notifications
```
Paginated (15/page, newest first). `data` is an array (see [pagination note](#pagination-️-important-for-list-endpoints)). Message: `تم جلب الإشعارات.`

### 6.2 Unread count

```
GET /api/auth/notifications/unread-count
```
**200 Response**

```json
{
  "success": true,
  "message": "تم جلب عدد الإشعارات غير المقروءة.",
  "data": { "unread_count": 3 }
}
```
Ideal for a navbar badge poll.

### 6.3 Mark as read

```
POST /api/auth/notifications/{notification}/read
```
Idempotent — marking an already‑read notification is a no‑op. Returns the updated notification object. Message: `تم وضع علامة "مقروء" على الإشعار.`
**404** — id doesn't exist **or** belongs to another user (the two are indistinguishable by design).

---

## 7. Departments

Base path `/api/departments`. **Protected**, guarded by `departments.*` permissions.

A "department" is an **organizational unit at any tier** of the company tree — the API calls it a `الوحدة التنظيمية` (organizational unit), not `قسم`. The tree is:

| `level` | Tier                     | `level_label`     | Rule                                          |
| ------- | ------------------------ | ----------------- | --------------------------------------------- |
| `1`     | General administration   | `الإدارة العامة`  | The **single root** — exactly one, no parent  |
| `2`     | Division                 | `إدارة`           | Always directly under the root                |
| `3`     | Section                  | `قسم`             | Always directly under a division              |
| `4`     | Office                   | `مكتب`            | Always directly under a section (deepest tier) |

**Hierarchy rules** (enforced on every write; violations are `422` field errors):

- A unit **without** `parent_id` must be level 1, and only **one** root may exist.
- A unit **with** a parent must sit **exactly one level below it** (no skipping, nothing under level 4).
- The parent must exist and not be soft‑deleted.
- On update: a unit cannot be its own parent, or be moved under one of its own descendants.
- `sector` may only be stored on a **level‑2** unit. See [الجهة](#الجهة--sector) below.

### الجهة — `sector`

Which of the company's two groupings a unit belongs to.

| `sector` | `sector_label`      |
| -------- | ------------------- |
| `1`      | `المكاتب الإدارية`  |
| `2`      | `الأجسام الإدارية`  |

**It is stored on divisions only.** The root, the governance units
(`مجلس الإدارة`, `مكتب المدير العام`), and every section and office store `NULL`
and **inherit** their sector from the nearest ancestor that has one.

The API therefore returns a **resolved** value: a section reads back
`"sector": 1` even though its own row is `NULL`. Two consequences for clients:

- **Read it, do not re‑post it.** Sending a non‑null `sector` for a unit whose
  `level` is not 2 is rejected with `422` — including when the value is simply
  echoed back from a `GET`. Send `sector` only when you are editing a division.
  A `PATCH` that omits the field is always safe.
- **Moving a unit changes its sector automatically.** Re‑parent a section into a
  division in the other grouping and the section — and everything beneath it —
  reports the new sector on the next read. No second call is needed, and none is
  possible.

**Department object shape**

```json
{
  "id": 3,
  "name": "قسم النقدية",
  "code": "FIN-CASH",
  "description": null,
  "is_active": true,
  "parent_id": 2,
  "level": 3,
  "level_label": "قسم",
  "sector": 1,
  "sector_label": "المكاتب الإدارية",
  "manager_id": 4,
  "manager": { "id": 4, "full_name": "سامر خليل", "employee_number": "E-0012" },
  "created_at": "2026-06-21T10:00:00+03:00",
  "updated_at": "2026-06-21T10:00:00+03:00"
}
```

- 🆕 `manager` is the employee `manager_id` points at — `{ id, full_name, employee_number }` — embedded so rendering "who runs this unit" costs no extra request. It is **`null`, never absent**, when the unit has no manager (or the recorded one has been deleted); see [Embedded objects](#embedded-objects-an-fk-and-the-thing-it-points-at). `manager_id` is unchanged and is still what you send on create/update.
- `level` is the machine‑readable tier to filter on; `level_label` is the Arabic display name (resolved server‑side — do **not** hardcode a tier map in the frontend). `sector` / `sector_label` work the same way, with the addition that the value is **inherited** — see [الجهة](#الجهة--sector).
- `code` is a stable short identifier derived from the unit's path through the tree (`FIN` → `FIN-CASH` → `FIN-CASH-VAULT`). Prefer it over `name` when matching units against an external system: names repeat across the company — `الحسابات` and `أمانة الصندوق` each occur under three different divisions.

| Method & path                          | Permission             | Notes                                   |
| -------------------------------------- | ---------------------- | --------------------------------------- |
| `GET /api/departments`                 | `departments.view`     | Paginated list (15/page). `?page=N`     |
| `POST /api/departments`                | `departments.create`   | 201 on success                          |
| `GET /api/departments/{department}`    | `departments.view`     | Single resource                         |
| `PUT\|PATCH /api/departments/{department}` | `departments.update` | Partial update                          |
| `DELETE /api/departments/{department}` | `departments.delete`   | 200 on success (**soft** delete)        |

**Request body (create / update)**

| Field         | Type    | Rules                                                                   |
| ------------- | ------- | ----------------------------------------------------------------------- |
| `name`        | string  | required on create, optional on update, max 255                         |
| `code`        | string  | optional, nullable, max 50, **unique** among live units                 |
| `description` | string  | optional, nullable, max 1000                                            |
| `is_active`   | boolean | optional (defaults to `true`)                                           |
| `parent_id`   | integer | nullable; must be an existing, **non‑deleted** unit; `null` = the root  |
| `level`       | integer | required on create; must be a defined tier (1–4) obeying the rules above |
| `sector`      | integer | optional, nullable; `1` or `2`; **only accepted when `level` is 2** — every other unit inherits it |
| `manager_id`  | integer | optional, nullable; must be an existing, **non‑deleted employee**       |

**Messages:** index `تم جلب الوحدات التنظيمية.` · show `تم جلب الوحدة التنظيمية.` · create `تم إنشاء الوحدة التنظيمية.` · update `تم تحديث الوحدة التنظيمية.` · delete `تم حذف الوحدة التنظيمية.`

**Errors**

- `422` — field/hierarchy violations, e.g. missing name `اسم الوحدة التنظيمية مطلوب.` · second root `يوجد بالفعل وحدة جذر (الإدارة العامة) في هيكل الشركة، ولا يمكن إنشاء أكثر من جذر واحد.` · wrong child tier `الوحدة التابعة لـ… يجب أن تكون … (المستوى …).` · nothing under the deepest tier `لا يمكن إضافة وحدات فرعية تحت مكتب لأنها أدنى مستوى في هيكل الشركة.` · sector on a non‑division `الجهة تُحدَّد على مستوى إدارة (المستوى 2) فقط؛ الوحدات الأدنى ترث جهتها من الوحدة الأعلى.` · deleted/missing parent `الوحدة التنظيمية الأصل غير موجودة أو تم حذفها.` · cycle `لا يمكن نقل الوحدة التنظيمية لتصبح تابعة لإحدى الوحدات الفرعية التابعة لها.` · unknown manager `المدير المحدد غير موجود.`
- `409` — deleting the **root**: `لا يمكن حذف الإدارة العامة، فهي الوحدة الجذر لهيكل الشركة.`
- `409` — deleting a unit that still has live children: `لا يمكن حذف وحدة تنظيمية تحتوي على وحدات فرعية. الرجاء نقل أو حذف الوحدات الفرعية أولًا.`

> Deletes are **soft**: the unit disappears from all lists but its row (and audit history) survives. A soft‑deleted unit cannot be a parent, cannot be assigned to employees, and its `code` is freed for reuse.

---

## 8. HR

Base path `/api/hr`. **Protected.** Four resources: employees, the job-title
catalogue, posting history, and warnings.

### ⚠️ 8.0 Breaking changes — Batch 2 (2026-09-13)

**Every client that reads an employee must be updated.** The employees table was
restructured before the company's real staff are imported — the last release in
which that was a migration rather than a data migration.

| Was | Is now | Why |
| --- | --- | --- |
| `name` | `first_name`, `middle_name`, `last_name` + read-only `full_name` | The company records الاسم الثلاثي. Storing parts *and* a joined string is two columns for one fact |
| `job_title` (string) | `job_title_id` (FK) + embedded `job_title` object | Titles are a governed, company-wide catalogue |
| `salary` | **removed** | For most staff pay is *computed* from criteria, not stored. The negotiated track uses `agreed_salary` |
| `status: "active"` | `status: 1` + `status_label: "نشط"` | Matches `level` / `level_label` on departments. `inactive` is now **`موقوف` (suspended)** |

**Migration notes for the frontend**

1. Replace any `employee.name` read with `employee.full_name`; use the three
   parts in forms.
2. `POST` and `PUT` now require `first_name`, `middle_name`, `last_name` and
   `gender` on create.
3. Every enum field ships as `{field}` (int) plus `{field}_label` (Arabic).
   Render the label, filter on the int, and never keep your own copy of the
   mapping.
4. `GET /api/auth/me` and `/api/auth/users` still return `name` — that key is an
   *account's* display name and is unchanged. Only the employee resource split.

### 8.1 Employees

**Employee object** (a requester holding **both** field-level permissions)

```json
{
  "id": 1,
  "employee_number": "EMP-00001",

  "first_name": "سامر",
  "middle_name": "خالد",
  "last_name": "الحلبي",
  "mother_name": "فاطمة",
  "full_name": "سامر خالد الحلبي",
  "national_id": "1098765432",

  "gender": 1,
  "gender_label": "ذكر",
  "birth_date": "1990-04-12",
  "birth_place": "حلب",
  "origin_governorate": 3,
  "origin_governorate_label": "حلب",
  "residence_governorate": 3,
  "residence_governorate_label": "حلب",
  "nationality": "سوري",
  "second_nationality": null,

  "marital_status": 2,
  "marital_status_label": "متزوج",
  "wives_count": 1,
  "sons_count": 2,
  "daughters_count": 1,
  "children_count": 3,

  "phone": "+963900000000",
  "phone_secondary": null,
  "email": "samer@example.com",
  "address": "حلب - الفرقان",

  "did_military_service": true,
  "military_discharge_date": "2014-06-01",

  "education_level": 11,
  "education_level_label": "كلية",
  "qualification": "كلية هندسة مدنية",
  "specialization": "إنشاءات",
  "is_graduated": true,
  "graduation_date": "2013-07-01",

  "department_id": 2,
  "department": { "id": 2, "name": "الإدارة الهندسية", "level": 2 },
  "job_title_id": 7,
  "job_title": { "id": 7, "name": "مهندس مدني" },
  "direct_manager_id": 12,
  "direct_manager": { "id": 12, "full_name": "سامر خليل", "employee_number": "E-0012" },
  "employment_type": 1,
  "employment_type_label": "متعاقد",
  "workplace": "مجبل الإسفلت",
  "hire_date": "2026-01-15",
  "termination_date": null,
  "status": 1,
  "status_label": "نشط",

  "activity_type": 1,
  "activity_type_label": "هندسي",
  "organizational_grade": 3,
  "experience_level": 2,
  "engineering_rank": 6,
  "engineering_rank_label": "ممارس",
  "industrial_experience_years": null,
  "has_hazard_pay": true,
  "has_scarcity_pay": false,
  "has_dual_role_pay": false,
  "distinction_grade": null,
  "salary_mode": 1,
  "salary_mode_label": "نظام رواتب",
  "agreed_salary": null,
  "sham_cash_account": "1234567890123456",

  "created_at": "2026-09-13T10:00:00+03:00",
  "updated_at": "2026-09-13T10:00:00+03:00"
}
```

🆕 `direct_manager` is the employee `direct_manager_id` points at —
`{ id, full_name, employee_number }` — and is **`null`, never absent**, for the
vast majority who have none. Remember what that null means here: **not** "has no
manager" but "no manager *by exception* — resolve them from the org tree"
(the unit's `manager_id`, walking up). `direct_manager_id` is unchanged and is
still what you write. See
[Embedded objects](#embedded-objects-an-fk-and-the-thing-it-points-at).

**Field-level gating.** Gated keys are **absent entirely**, never `null` — an
absent key says "you may not see this", a null would say "this employee has no
national id". The embedded `department`, `job_title` and `direct_manager`
objects are the mirror image: always present, `null` when there is nothing to
name, and never gated — a name and a staff number are not sensitive fields.

| Permission | Gates |
| --- | --- |
| `hr.employees.viewIdentity` | `national_id` |
| `hr.employees.viewSalary` | `salary_mode(_label)`, `agreed_salary`, `sham_cash_account`, **and every classification axis**: `activity_type(_label)`, `organizational_grade`, `experience_level`, `engineering_rank(_label)`, `industrial_experience_years`, `has_hazard_pay`, `has_scarcity_pay`, `has_dual_role_pay`, `distinction_grade` |

> **Why the axes are gated with the amount:** they are the inputs the salary is
> computed from. Publishing them while hiding the result would let anyone with
> the rate table reconstruct the pay to within a rounding error.

Both gates behave identically on the list and single endpoints.

| Method & path | Permission | Notes |
| --- | --- | --- |
| `GET /api/hr/employees` | `hr.employees.view` | Paginated. Filters + sort below |
| `POST /api/hr/employees` | `hr.employees.create` | 201 on success |
| `GET /api/hr/employees/{employee}` | `hr.employees.view` | Single resource |
| `PUT\|PATCH /api/hr/employees/{employee}` | `hr.employees.update` | Partial update |
| `DELETE /api/hr/employees/{employee}` | `hr.employees.delete` | 200 (**soft** delete) |

**List query parameters**

| Param | Type | Notes |
| --- | --- | --- |
| `status` | int | `1` نشط · `2` موقوف · `3` منتهي الخدمة |
| `department_id` | int | must exist and not be deleted |
| `job_title_id` | int | must exist and not be deleted |
| `activity_type` | int | 1–5 |
| `search` | string | matches any name part or `employee_number` |
| `sort` | string | `employee_number`, `first_name`, `last_name`, `hire_date`, `created_at`; prefix `-` for descending |

⚠️ An unknown `sort` or an invalid filter is a **422**, not a silent fallback —
quietly ignoring one returns a correct-looking page of the wrong rows.
(`per_page` is the exception: it is clamped to 1–100 rather than rejected.)

**Request body (create / update).** Fields are `sometimes` unless marked
required; required applies on create only.

| Field | Type | Rules |
| --- | --- | --- |
| `first_name` / `middle_name` / `last_name` | string | **required on create**, max 255 |
| `gender` | int | **required on create**, `1` ذكر · `2` أنثى |
| `employee_number` | string | optional, max 50, unique — **auto-generated when omitted**, and a supplied value is kept as-is |
| `national_id` | string | optional, nullable, max 50, unique |
| `mother_name` | string | optional, nullable, max 255 |
| `birth_date` | date | optional, nullable, must be before today |
| `birth_place` | string | optional, nullable, max 255 |
| `origin_governorate` / `residence_governorate` | int | optional, nullable, 1–14 |
| `nationality` / `second_nationality` | string | optional, max 60 (`nationality` defaults to `سوري`) |
| `marital_status` | int | optional, nullable, `1` عازب · `2` متزوج · `3` مطلق · `4` أرمل |
| `wives_count` | int | optional, 0–4 — **only for a married employee** |
| `sons_count` / `daughters_count` | int | optional, 0–50. `children_count` is derived and read-only |
| `phone` / `phone_secondary` | string | optional, nullable, max 50 |
| `email` | string | optional, nullable, email, max 255 |
| `address` | string | optional, nullable, max 1000 |
| `did_military_service` | bool | optional, nullable |
| `military_discharge_date` | date | optional, nullable |
| `education_level` | int | optional, nullable, 1–13 (see §8.5) |
| `qualification` / `specialization` | string | optional, nullable, max 255 |
| `is_graduated` | bool | optional, nullable |
| `graduation_date` | date | optional, nullable |
| `activity_type` | int | optional, nullable, 1–5 |
| `organizational_grade` | int | optional, nullable, **1–5** |
| `experience_level` | int | optional, nullable, **1–5** |
| `engineering_rank` | int | optional, nullable, 1–9 — **only when `activity_type` = 1 (هندسي)** |
| `industrial_experience_years` | int | optional, nullable, 0–60 — **only when `activity_type` = 2 (صناعي)** |
| `has_hazard_pay` / `has_scarcity_pay` / `has_dual_role_pay` | bool | optional |
| `distinction_grade` | int | optional, nullable, 0–255 |
| `department_id` | int | optional, nullable, existing **non-deleted** department |
| `job_title_id` | int | optional, nullable, existing **non-deleted** title |
| `direct_manager_id` | int | optional, nullable, existing employee, **not the employee themselves** |
| `employment_type` | int | optional, nullable, `1` متعاقد · `2` يحتاج تثبيت · `3` جماعة · `4` مثبت |
| `workplace` | string | optional, nullable, max 255 |
| `hire_date` / `termination_date` | date | optional, nullable — termination may not precede hire |
| `status` | int | optional, 1–3 |
| `sham_cash_account` | string | optional, nullable, **exactly 16 digits**, unique |
| `salary_mode` | int | optional, `1` نظام رواتب (default) · `2` إتفاق |
| `agreed_salary` | numeric | **required when `salary_mode` = 2, must be absent/null when = 1** |

**Cross-field rules (422).** These are enforced for every writer, not just HTTP:

- `الرتبة الهندسية تُحدَّد للموظفين ذوي النشاط الهندسي فقط.`
- `سنوات الخبرة الصناعية تُحدَّد للموظفين ذوي النشاط الصناعي فقط.`
- `الراتب المتفق عليه مطلوب عندما يكون نظام الراتب «إتفاق».`
- `لا يجوز تحديد راتب متفق عليه ضمن «نظام الرواتب»؛ الراتب يُحتسب من المعايير.`
- `عدد الزوجات يُحدَّد للموظف المتزوج فقط.`
- `تاريخ انتهاء العمل لا يمكن أن يسبق تاريخ التعيين.`
- `رقم الشام كاش يجب أن يتكون من 16 رقماً.`

**Messages:** index `تم جلب الموظفين.` · show `تم جلب بيانات الموظف.` · create `تم إنشاء الموظف.` · update `تم تحديث بيانات الموظف.` · delete `تم حذف الموظف.`

> Deletes are **soft**: identity, history and audit trail survive. A linked user
> account keeps its `employee_id`, but the account's `name` resolves to `null`
> while the employee is deleted.

### 8.2 Job titles — دليل المسميات الوظيفية

**One shared, company-wide catalogue.** `name` is unique on its own and there is
**no `department_id`**: `محاسب` is a single row with a single job description
whichever divisions employ accountants. "Which divisions use this title?" is
answered by filtering employees on `job_title_id`.

```json
{
  "id": 7,
  "name": "مهندس مدني",
  "default_organizational_grade": 3,
  "default_activity_type": 1,
  "default_activity_type_label": "هندسي",
  "is_described": false,
  "description": null,
  "is_active": true,
  "created_at": "2026-09-13T10:00:00+03:00",
  "updated_at": "2026-09-13T10:00:00+03:00"
}
```

The two `default_*` values are **suggestions** that pre-fill the employee form,
not constraints: a section head and a division head can hold one title at
different grades, so the employee's own columns remain the fact.

| Method & path | Permission | Notes |
| --- | --- | --- |
| `GET /api/hr/job-titles` | `hr.job_titles.view` | Paginated, ordered by name. `?search=`, `?is_described=`, `?is_active=`, `?sort=name\|-name\|created_at\|-created_at` |
| `POST /api/hr/job-titles` | `hr.job_titles.create` | 201 |
| `GET /api/hr/job-titles/{jobTitle}` | `hr.job_titles.view` | |
| `PUT\|PATCH /api/hr/job-titles/{jobTitle}` | `hr.job_titles.update` | |
| `DELETE /api/hr/job-titles/{jobTitle}` | `hr.job_titles.delete` | 200, **soft** delete — **409** if held |

The boolean filters accept `1`/`0`, `true`/`false`, `yes`/`no`, `on`/`off`, in
any case. Anything else is a **422** — as is an unknown `sort` — never a silent
fallback: a filter that is quietly ignored returns a correct-looking page of the
wrong rows.

⚠️ **`409 Conflict`** — `لا يمكن حذف المسمى الوظيفي لأنه مرتبط بموظفين أو بسجلات تعيين.`
A title is undeletable while anyone holds it **or ever held it**, because a
historical posting that cannot resolve its title cannot explain the payslip
computed from it.

⚠️ **A deleted title keeps its name reserved.** The unique index counts
soft-deleted rows, so `محاسب` cannot be recreated until the old row is
force-deleted. Same trade-off as `users.email`.

`?is_described=0` is قسم التحليل والتوصيف's worklist: the titles still awaiting
a job description.

### 8.3 Posting history — سجل التعيين

One employee's placements over time. The row with `to_date: null` is the current
one; the earliest row is their initial posting.

```json
{
  "id": 12,
  "employee_id": 1,
  "department_id": 2,
  "department": { "id": 2, "name": "الإدارة الهندسية" },
  "job_title_id": 7,
  "job_title": { "id": 7, "name": "مهندس مدني" },
  "organizational_grade": 3,
  "from_date": "2026-06-01",
  "to_date": null,
  "is_current": true,
  "reason": 3,
  "reason_label": "ترقية",
  "legacy_workplace": null,
  "legacy_manager_name": null,
  "notes": "قرار رقم 41",
  "created_at": "2026-09-13T10:00:00+03:00"
}
```

| Method & path | Permission | Notes |
| --- | --- | --- |
| `GET /api/hr/employees/{employee}/assignments` | `hr.assignments.view` | Paginated, newest first |
| `POST /api/hr/employees/{employee}/assignments` | `hr.assignments.create` | 201 — records a transfer |

⚠️ **There is no update and no delete, and no permission that could reach one.**
The history is append-only: a posting recorded wrongly is corrected by recording
the correction, the way a ledger is. Editing last year's posting would change
what a payslip was computed against, with no trace of the original.

**Request body**

| Field | Type | Rules |
| --- | --- | --- |
| `from_date` | date | **required**, may be back-dated, **may not be in the future** |
| `reason` | int | **required, no default** — `1` تعيين · `2` نقل داخلي · `3` ترقية · `4` تعديل مسمى وظيفي · `5` إعادة هيكلة · `6` إنهاء عمل |
| `department_id` | int | optional, nullable, existing non-deleted — **omit to carry forward** |
| `job_title_id` | int | optional, nullable, existing non-deleted — **omit to carry forward** |
| `organizational_grade` | int | optional, nullable, 1–5 — **omit to carry forward** |
| `notes` | string | optional, nullable, max 2000 |

⚠️ **An omitted field carries forward; an explicit `null` clears it.** These are
different instructions and they behave differently. A promotion states only the
new grade and a تعديل مسمى only the new title — neither restates the department,
and neither should un-assign the person from it. Send `"department_id": null`
only when you actually mean "no unit".

**What recording a posting does**, in one transaction: closes the current posting
**the day before** the new one starts (so "which unit were they in on date D?"
has exactly one answer), opens the new one, and updates the employee's
`department_id` / `job_title_id` / `organizational_grade` to match. A posting
dated on or before the current one's start is treated as an **amendment** and
updates it in place rather than stacking a zero-length row in front of it.

> The reverse also holds: changing `department_id`, `job_title_id` or
> `organizational_grade` through `PUT /api/hr/employees/{employee}` records the
> transfer automatically, with `reason: 2 (نقل داخلي)`, closing the outgoing
> posting the day before in exactly the same way. Use this endpoint when you
> know the real reason or the real date.

**Messages:** index `تم جلب سجل التعيين.` · create `تم تسجيل التعيين.`

### 8.4 Warnings — الإنذارات

```json
{
  "id": 4,
  "employee_id": 1,
  "type": "تأخر متكرر",
  "number": "W-2026-14",
  "issued_on": "2026-04-01",
  "sequence": 2,
  "issued_by": 9,
  "issuer": { "id": 9, "full_name": "سامر خالد الحلبي", "employee_number": "E-0009" },
  "notes": "إنذار ثانٍ.",
  "created_at": "2026-09-13T10:00:00+03:00",
  "updated_at": "2026-09-13T10:00:00+03:00"
}
```

🆕 `issuer` now carries `employee_number` as well as `{ id, full_name }`, so an
embedded person has the same three keys here as everywhere else
([Embedded objects](#embedded-objects-an-fk-and-the-thing-it-points-at)).
Purely additive — nothing was removed or renamed. It is `null` when no issuer is
recorded.

`sequence` is **computed, not stored** — it is this warning's position in the
employee's sequence, counted from the live rows. Withdrawing warning #2 of 3
renumbers the third to #2; filing a back-dated one puts it in its rightful place.
Do not send it.

| Method & path | Permission | Notes |
| --- | --- | --- |
| `GET /api/hr/employees/{employee}/warnings` | `hr.warnings.view` | Paginated, newest first |
| `POST /api/hr/employees/{employee}/warnings` | `hr.warnings.create` | 201 |
| `PUT\|PATCH /api/hr/warnings/{warning}` | `hr.warnings.update` | **Not** nested — the row's employee is fixed by its FK |
| `DELETE /api/hr/warnings/{warning}` | `hr.warnings.delete` | 200, **soft** delete (withdrawn ≠ never happened) |

| Field | Type | Rules |
| --- | --- | --- |
| `type` | string | **required on create**, max 255 |
| `issued_on` | date | **required on create**, **may not be in the future** |
| `number` | string | optional, nullable, max 50 — رقم كتاب الإنذار |
| `issued_by` | int | optional, nullable — an **employee** id (a manager may have no login) |
| `notes` | string | optional, nullable, max 5000 |

**Messages:** index `تم جلب الإنذارات.` · create `تم تسجيل الإنذار.` · update `تم تحديث الإنذار.` · delete `تم حذف الإنذار.`

### 8.5 Enum reference

Every enum below ships as an int with a matching `_label`. Render the label,
filter on the int.

| Enum | Values |
| --- | --- |
| `gender` | 1 ذكر · 2 أنثى |
| `marital_status` | 1 عازب · 2 متزوج · 3 مطلق · 4 أرمل |
| `status` | 1 نشط · 2 موقوف · 3 منتهي الخدمة |
| `employment_type` | 1 متعاقد · 2 يحتاج تثبيت · 3 جماعة · 4 مثبت |
| `salary_mode` | 1 نظام رواتب · 2 إتفاق |
| `activity_type` | 1 هندسي · 2 صناعي · 3 إداري · 4 مالي · 5 أمني |
| `education_level` | 1 لا يوجد · 2 ابتدائي · 3 تاسع · 4 بكالوريا · 5 سنة 1 · 6 سنة 2 · 7 معهد · 8 سنة 3 · 9 سنة 4 · 10 سنة 5 · 11 كلية · 12 ماجستير · 13 دكتوراه |
| `engineering_rank` | 1 لا يوجد · 2 متدرب · 3 مراقب فني · 4 مساعد مهندس · 5 مشارك · 6 ممارس · 7 رأي · 8 استشاري · 9 ركن |
| `governorate` | 1 دمشق · 2 ريف دمشق · 3 حلب · 4 حمص · 5 حماة · 6 اللاذقية · 7 طرطوس · 8 إدلب · 9 درعا · 10 السويداء · 11 القنيطرة · 12 دير الزور · 13 الرقة · 14 الحسكة |
| `reason` (assignments) | 1 تعيين · 2 نقل داخلي · 3 ترقية · 4 تعديل مسمى وظيفي · 5 إعادة هيكلة · 6 إنهاء عمل |

`education_level` and `engineering_rank` are **ordered scales** — the int
ascends with attainment, so `>=` comparisons are meaningful. ⚠️ The int is an
ORDER, not an amount: سنة 4، سنة 5 and كلية are three distinct rungs carrying one
identical pay value. Never compute pay from a case number.

---

## 9. Audit log — سجل التدقيق

Read-only access to `audit_logs`: who changed what, when, and from where.

```
GET /api/auth/audit-logs        → paginated list   (auth.audit.view)
GET /api/auth/audit-logs/{id}   → one row in full  (auth.audit.view)
```

**There is no write endpoint and there will not be one.** The table is
append-only by design — no create, no update, no delete, no restore, no purge,
and no permission that could authorize one. Render zero mutation affordances: a
destructive-looking control on an audit trail advertises a power that does not
exist.

> ⚠️ **Reading it writes to it.** `AuditLogMiddleware` records every
> authenticated request, so each page of this screen appends one `api_request`
> row to the table it is showing. That is deliberate — "who read the audit
> trail" is itself worth recording. **Do not poll this endpoint**, do not
> auto-refresh it, and do not refetch it on window focus. One explicit refresh
> control, and nothing else.

### 9.1 List

| Param | Type | Notes |
| --- | --- | --- |
| `page`, `per_page` | int | Standard: default 15, clamped 1–100 (§1) |
| `user_id` | int | The actor |
| `actor_type` | `user` \| `system` | `system` selects rows with no authenticated actor — a seeder, console command or queued job. Composes with `user_id` rather than replacing it |
| `event` | CSV of event keys | `created,updated,deleted`. An unknown key is a **422**, and so is an empty list |
| `event_group` | CSV of `model` \| `auth` \| `request` | `request` is the `api_request` firehose. Same empty-list rule as `event` |
| `auditable_type` | short key | `employee` · `employee_assignment` · `employee_document` · `employee_warning` · `job_title` · `department` · `user` · `role` · `permission`. **Never a PHP class name** |
| `auditable_id` | int | Requires `auditable_type` — **422** without it |
| `request_id` | string (≤64) | The correlation drill-down; the column is indexed, so this is cheap |
| `ip_address` | string (≤45) | |
| `date_from` / `date_to` | `YYYY-MM-DD` | Inclusive **to the end of** `date_to`'s day, Asia/Damascus |
| `search` | string | Matches `description` only |
| `sort` | `created_at` \| `-created_at` | Default `-created_at`. **Nothing else is sortable** — anything else is a **422**, because only `created_at` is indexed and this is the one table that grows without bound |

`event` and `event_group` **intersect**: `?event=created&event_group=auth` asks
for a created event in the auth group, of which there are none, and correctly
returns an empty page.

> **Omit a filter you are not using; do not send it empty.** `?event=` and
> `?event=,,,` are **422**s, not "no filter". A discarded filter would return a
> correct-looking page answering a different question, and on `event_group` —
> the parameter that hides the `api_request` firehose — the page you would get
> back is the firehose. Build query strings by omitting empty values.

**List item**

```json
{
  "id": 88412,
  "event": "updated",
  "event_label": "تعديل",
  "event_group": "model",
  "description": null,
  "actor_type": "user",
  "user_id": 7,
  "user": { "id": 7, "name": "سامر خليل", "email": "samer@example.com" },
  "auditable_type": "employee",
  "auditable_label": "موظف",
  "auditable_id": 42,
  "auditable": { "id": 42, "label": "أحمد خالد الحمصي (EMP-00042)" },
  "changed_fields": ["department_id", "organizational_grade"],
  "ip_address": "10.0.0.14",
  "request_id": "8f3c…",
  "created_at": "2026-09-16T14:03:11+03:00"
}
```

The list deliberately **does not** carry `old_values` / `new_values` — a
`deleted` employee row holds around fifty keys, and a hundred of those per page
is a response nobody asked for. They are on the detail endpoint only.

### 9.2 Detail

`GET /api/auth/audit-logs/{id}` returns everything above plus `user_agent` and,
**for a caller permitted to read them**, `old_values` and `new_values`.

Two permissions are needed for the values, not one:

1. `auth.audit.viewValues` — the field-level grant, on every row.
2. **The view permission of the module the row is about**: `hr.employees.view`
   for an `employee` row, `auth.users.view` for a `user` row, `departments.view`
   for a `department` row, and so on. An audit row is a copy of someone else's
   record, and permission to read audit rows is not permission to read that
   record — without this, `viewValues` alone reconstructed every audited table
   in the system from `new_values`. Rows with **no target** (`login`,
   `api_request`) need only the first: their blobs hold request and auth
   metadata, which is nobody else's data.

⚠️ **An `employee` row needs a THIRD permission since 2026-09-23:
`hr.employees.viewSalary`.** The classification axes are stored in the trail in
cleartext — they are ordinary integers, so the redaction list never covered them
— and they are the inputs pay is computed from. Without this, a caller holding
`hr.employees.view` + `viewValues` read a grade out of history that the same
caller is refused on the employee itself. The axes are **not** redacted instead,
on purpose: redaction on an append-only table is permanent, and it would erase
*who changed this person's grade, and to what*. `changed_fields` still arrives
either way, so "someone changed this employee's grade at 14:03" stays answerable
without reading the grade.

**So the same caller can get the values on one row and not the next.** Decide
per row from what the payload actually contains; never from a remembered
"may I see values" flag.

```json
{
  "old_values": { "workplace": "دمشق" },
  "new_values": { "workplace": "حلب" },
  "user_agent": "Mozilla/5.0 …"
}
```

**Three states, three meanings — do not collapse them:**

| What you see | What it means |
| --- | --- |
| key **absent** | You may not read this. You lack `auth.audit.viewValues`, or the view permission for this row's target |
| key **`null`** | There is nothing to read. `created` has no old values; `deleted` has no new ones |
| value `"***REDACTED***"` | The field was redacted **before storage**. The real value was never written to this table and cannot be recovered |

Reading an absent key as "nothing changed" will report an edit as an empty diff.
This is the §1 embedded-objects rule applied to a payload: absent ≠ null.

### 9.3 The three kinds of actor

| `actor_type` | `user` | Means |
| --- | --- | --- |
| `system` | `null` | A seeder, console command or queued job — nobody was authenticated |
| `user` | object | That account |
| `user` | `null` | **The account has been deleted.** `user_id` still names them |

The third is the case an audit is most often opened to answer, so say it
explicitly — "مستخدم محذوف #7", not "غير معروف".

`?actor_type=system` is how you ask for the first row of that table. `user_id`
matches an id and no value of it expresses "no actor", so before this filter
existed the only way to that question was paging the whole table.

### 9.4 The target

`auditable` is `null` for action-level events (login, `role_assigned`,
`api_request`) and for a target that has been soft-deleted. In both cases
`auditable_label` and `auditable_id` are still there, so render
`{auditable_label} #{auditable_id}` unlinked rather than nothing.

`auditable_type` is a **stable alias, never a class name** — the same alias
notifications publish as `reference.type`. An unrecognised alias serialises with
`auditable_label: null`; render the raw key and do not guess a route for it.

`auditable_type` itself is **`null`** on one further case: a row written before
the morph map was enforced (2026-09-08) whose class has since been renamed or
removed. There is no honest public name left for it and a class name must never
reach you (C12), so the row arrives with the type `null` and `auditable_id`
intact. Render it as an unresolvable target; do not treat `auditable_type: null`
with a non-null `auditable_id` as "no target".

> ⚠️ **Amended from the frontend work order.** That document proposed
> `assignment` and `warning` as the short keys. The real values are
> **`employee_assignment`** and **`employee_warning`**, because those are the
> aliases the enforced morph map stores in the column and already publishes on
> notifications. One type cannot have two public names depending on which
> endpoint you ask.

`changed_fields` names **which** fields moved and is **not** gated — it ships to
anyone holding `auth.audit.view`. What they moved from and to needs
`auth.audit.viewValues`. Non-model events (`api_request`, the auth vocabulary)
have `changed_fields: []`: the metadata in their `new_values` is not a record
edit and is not presented as one.

### 9.5 Event vocabulary

`event_label` is the Arabic name to display. An event key the server does not
recognise returns `event_label: null` **and** `event_group: null` — render the
raw key rather than inventing a label, so a new backend event looks unfamiliar
instead of looking like something else.

| Group | Events |
| --- | --- |
| `model` | `created`, `updated`, `deleted` |
| `auth` | `user_logged_in`, `user_logged_out`, `tokens_refreshed`, `all_tokens_revoked`, `refresh_token_replay_detected`, `user_created`, `user_updated`, `user_deleted`, `role_created`, `role_updated`, `role_deleted`, `role_assigned`, `role_unassigned` |
| `request` | `api_request` |

`refresh_token_replay_detected` is a **security signal**, not routine traffic: a
rotated refresh token was presented twice, which is either a bug or a stolen
token. Treat it louder than an ordinary destructive event.

**`api_request` is roughly 99% of this table** — every authenticated request
writes one. Defaulting the list to `event_group=model,auth` is the sane
behaviour, but say so on screen: a filter that hides data invisibly is not
acceptable on an audit tool.

### 9.6 Timestamps

`created_at` is ISO-8601 **carrying the `+03:00` offset**, like every other
timestamp in this API — see §1 Timestamps, which is where the rule lives. It is
repeated here only because on this screen the consequence is sharpest: an audit
row read three hours early puts an event in the wrong part of someone's working
day, which is the question the screen exists to answer.

Note that timestamps *inside* `old_values` / `new_values` are the raw stored
columns and carry no offset at all (§1). Read those as Damascus local.

`date_from` / `date_to` are inclusive of the whole end day, so
`date_from=date_to=2026-09-16` returns that day including its last hour.

---

## 10. Endpoint quick reference

| # | Method      | Path                                         | Auth     | Permission             |
| - | ----------- | -------------------------------------------- | -------- | ---------------------- |
| 1 | POST        | `/api/auth/login`                            | public   | — (5/min)              |
| 2 | POST        | `/api/auth/refresh`                          | public   | — (10/min)             |
| 3 | POST        | `/api/auth/logout`                           | bearer   | —                      |
| 4 | GET         | `/api/auth/me`                               | bearer   | —                      |
| 5 | POST        | `/api/auth/password`                         | bearer   | — (self-scoped)        |
| 6 | GET         | `/api/auth/users`                            | bearer   | `auth.users.view`      |
| 7 | POST        | `/api/auth/users`                            | bearer   | `auth.users.create`    |
| 8 | PUT/PATCH   | `/api/auth/users/{user}`                     | bearer   | `auth.users.update`    |
| 9 | DELETE      | `/api/auth/users/{user}`                     | bearer   | `auth.users.delete`    |
| 10| GET         | `/api/auth/roles`                            | bearer   | `auth.roles.view`      |
| 11| POST        | `/api/auth/roles`                            | bearer   | `auth.roles.create`    |
| 12| PUT/PATCH   | `/api/auth/roles/{role}`                     | bearer   | `auth.roles.update`    |
| 13| DELETE      | `/api/auth/roles/{role}`                     | bearer   | `auth.roles.delete`    |
| 14| POST        | `/api/auth/roles/assign`                     | bearer   | `auth.roles.update`    |
| 15| POST        | `/api/auth/roles/unassign`                   | bearer   | `auth.roles.update`    |
| 16| GET         | `/api/auth/notifications`                    | bearer   | — (owner‑scoped)       |
| 17| GET         | `/api/auth/notifications/unread-count`       | bearer   | — (owner‑scoped)       |
| 18| POST        | `/api/auth/notifications/{notification}/read`| bearer   | — (owner‑scoped)       |
| 19| GET         | `/api/auth/audit-logs`                       | bearer   | `auth.audit.view`      |
| 20| GET         | `/api/auth/audit-logs/{auditLog}`            | bearer   | `auth.audit.view`      |
| 21| GET         | `/api/departments`                           | bearer   | `departments.view`     |
| 22| POST        | `/api/departments`                           | bearer   | `departments.create`   |
| 23| GET         | `/api/departments/{department}`              | bearer   | `departments.view`     |
| 24| PUT/PATCH   | `/api/departments/{department}`              | bearer   | `departments.update`   |
| 25| DELETE      | `/api/departments/{department}`              | bearer   | `departments.delete`   |
| 26| GET         | `/api/hr/employees`                          | bearer   | `hr.employees.view`    |
| 27| POST        | `/api/hr/employees`                          | bearer   | `hr.employees.create`  |
| 28| GET         | `/api/hr/employees/{employee}`               | bearer   | `hr.employees.view`    |
| 29| PUT/PATCH   | `/api/hr/employees/{employee}`               | bearer   | `hr.employees.update`  |
| 30| DELETE      | `/api/hr/employees/{employee}`               | bearer   | `hr.employees.delete`  |
| 31| GET         | `/api/hr/job-titles`                         | bearer   | `hr.job_titles.view`   |
| 32| POST        | `/api/hr/job-titles`                         | bearer   | `hr.job_titles.create` |
| 33| GET         | `/api/hr/job-titles/{jobTitle}`              | bearer   | `hr.job_titles.view`   |
| 34| PUT/PATCH   | `/api/hr/job-titles/{jobTitle}`              | bearer   | `hr.job_titles.update` |
| 35| DELETE      | `/api/hr/job-titles/{jobTitle}`              | bearer   | `hr.job_titles.delete` |
| 36| GET         | `/api/hr/employees/{employee}/assignments`   | bearer   | `hr.assignments.view`  |
| 37| POST        | `/api/hr/employees/{employee}/assignments`   | bearer   | `hr.assignments.create`|
| 38| GET         | `/api/hr/employees/{employee}/warnings`      | bearer   | `hr.warnings.view`     |
| 39| POST        | `/api/hr/employees/{employee}/warnings`      | bearer   | `hr.warnings.create`   |
| 40| PUT/PATCH   | `/api/hr/warnings/{warning}`                 | bearer   | `hr.warnings.update`   |
| 41| DELETE      | `/api/hr/warnings/{warning}`                 | bearer   | `hr.warnings.delete`   |

**40 endpoints.** Batch 2 added eleven — the job-title catalogue (5), posting
history (2, read and append only), and warnings (4). سجل التدقيق added the two
above, both reads: there is no third, because the table is append-only.

> There are **no employee-document endpoints yet**, on purpose. The
> `employee_documents` table exists so the data import can attach the intake
> form's four images per hire, but upload, storage and signed downloads belong to
> the Documents module being ported from the legacy system. Building a second
> file pipeline here and unifying it later is the duplication the merge plan's
> stage ordering exists to prevent.

---

## 11. Frontend integration checklist

1. **HTTP client:** set base URL `{APP_URL}/api`, default headers `Accept: application/json` + `Content-Type: application/json`.
2. **Auth interceptor:** attach `Authorization: Bearer {access_token}` on every request.
3. **Refresh flow:** trigger on the HTTP **`401` status** — still the status, not the body, even though `401` now carries the envelope like everything else. Call `/api/auth/refresh` once with the stored refresh token, swap in the new pair, and retry the failed request. If refresh fails (any `401`), clear tokens and route to login. Do **not** refresh on `403` (that's a permission denial or a disabled account, not an expired token) — and note that `403` also has `success: false`, which is exactly why the trigger is the status code.
4. **Single‑use refresh:** persist the new `refresh_token` returned by **both** login and refresh; never reuse an old one. Store it where your JS can read it — it is sent in the request body, not a cookie.
5. **Session bootstrap:** on app start, if a token exists, call `/api/auth/me` to validate it and (re)load the user **and their `permissions`**.
6. **Error handling:** read `success` on **every** response — there are no longer any exceptions. On `false`, show `message` (always Arabic and display-ready) and, if `errors` exists, map field errors to inputs. Still route `401` (→ refresh) and `429` (→ back off via `Retry-After`) by **status code**, because what you do about them depends on the status, not on `success`.
7. **Permission‑aware UI:** gate actions on the `permissions` array from login/`me` (e.g. `permissions.includes('hr.employees.create')`); hide/disable what the user can't do to avoid `403`s. No extra request needed — it works for non‑admins too.
8. **Notification badge:** poll `/api/auth/notifications/unread-count` on an interval. It is one of exactly **two** endpoints meant to be polled — the other is the notification list itself, and both are excluded from the audit trail for that reason. **Never poll anything else**, and least of all `/api/auth/audit-logs`, which records every read of itself (§9).
9. **Lists:** **all nine** list endpoints are paginated and page identically — items in `data`, a sibling `meta` block of exactly six keys. Use `?page=N` and `?per_page=N` (default 15, clamped 1–100), and build the pager from `meta.last_page`. Read `meta.per_page` back rather than assuming your requested size was applied. Roles and users were unpaginated before 2026-09-08 and are not any more.
10. **Correlation:** send an `X-Request-Id` per user action and log the one that comes back on every response — it is recorded server-side on the matching audit rows, which turns a vague bug report into an exact lookup.
