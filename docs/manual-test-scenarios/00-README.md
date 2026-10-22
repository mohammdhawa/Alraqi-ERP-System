# Manual Test Scenarios — Al-Raqi ERP API (Phase 1)

A complete, hand-executable test suite for every endpoint, rule, permission,
scope branch, error message and side effect of this API. Every expected result
was derived from the **code** (routes, form requests, services, model guards,
resources, exception renderer, `lang/ar`) and cross-checked against
`docs/API_DOCUMENTATION.md`. Where the two disagree, the scenario states what the
code does, and the disagreement is listed at the bottom of that file under
**Discrepancies & suspected bugs**.

> Scope: the backend API in this repository (`/api/auth`, `/api/departments`,
> `/api/hr`). The dashboard (`../erp-dashboard`) is a separate repository and is
> not covered here. Its UI checks should follow the same scenario IDs.

## Files and execution order

Run them in this order. File 01 creates the personas every other file uses.

| # | File | Prefix | Area |
|---|------|--------|------|
| 00 | `00-README.md` | — | This index, conventions, endpoint map |
| 01 | `01-setup-and-personas.md` | SETUP | Environment, seed verification, QA persona creation |
| 02 | `02-auth-session.md` | AUTH | Login, refresh, logout, me, password change, forced change gate, token lifetime, rate limits, prune command |
| 03 | `03-users.md` | USR | User administration CRUD |
| 04 | `04-roles-and-rbac.md` | RBAC | Roles CRUD, assign/unassign, grant scopes, subset rule (GAP-16), PermissionReach |
| 05 | `05-notifications.md` | NOTIF | Own notifications, unread count, mark read |
| 06 | `06-departments.md` | DEPT | Org tree CRUD, hierarchy guard, sector, seed integrity |
| 07 | `07-hr-employees-core.md` | EMP | Employee CRUD, validation, classification guard, hire date |
| 08 | `08-hr-employees-access.md` | EMPACC | Field-level gating (salary/identity), row scope, manager resolution |
| 09 | `09-hr-job-titles.md` | JT | Job-title catalogue |
| 10 | `10-hr-assignments.md` | ASG | Posting history (append-only), opening posting, GAP-19 |
| 11 | `11-hr-warnings.md` | WRN | Warnings under an employee, amend/delete |
| 12 | `12-audit-log.md` | AUD | Audit trail list/detail, values gating, request id, event vocabulary |
| 13 | `13-cross-cutting.md` | XC | Envelope, error codes, 401 without Accept, pagination, timestamps, request id, throttling, input edge cases |

## Test accounts at a glance

Full creation steps are in `01-setup-and-personas.md`.

| Persona | Login | Password | Access |
|---------|-------|----------|--------|
| SA | `test@example.com` | `password` | `super_admin`: bypasses every permission check |
| VIEWER | `viewer@example.com` | `password` | role `viewer`: every `*.view` permission, company-wide |
| NOROLE | `noroles@example.com` | `password` | no roles, no employee link |
| HR_CO | Layla's employee number | `QaPass#2026` | `qa_hr_full`, company scope |
| HR_BASIC | Nour's employee number | `QaPass#2026` | `qa_hr_basic` (no salary or identity), company scope |
| HR_UNIT | Yusuf's employee number | `QaPass#2026` | `qa_hr_full`, unit scope = ENG |
| MGR | Omar's employee number | `QaPass#2026` | `qa_hr_full`, managed scope (FIN) |
| RBAC_ADMIN | Mona's employee number | `QaPass#2026` | `qa_rbac_admin`, company scope |
| AUDITOR | Tariq's employee number | `QaPass#2026` | `qa_auditor` (`auth.audit.view`), company scope |

A scoped grant confers only the permissions the server enforces a scope for, so
HR_UNIT and MGR can see fewer permissions in `/api/auth/me` than their role holds.
File 01 lists exactly which ones.

## Conventions

- **Scenario IDs** are `<PREFIX>-<NNN>` and are stable. Quote them in bug reports.
- **Type:** Positive, Negative, Boundary, Security, Permission, Side-effect, Regression.
- **Priority:** P1 = blocks release (security, data integrity, money, core flow);
  P2 = important behaviour/contract; P3 = cosmetic or low-risk edge.
- **Headers on every request** unless a scenario says otherwise:
  `Accept: application/json`, `Content-Type: application/json`,
  `Authorization: Bearer {{accessToken}}`. Optionally send `X-Request-Id: qa-<scenario-id>`,
  so the audit rows for a scenario can be found by filtering on that id.
- **Arabic messages must match exactly**, character for character.
- **Absent is not null.** Where a scenario says a key is *ABSENT*, a key present
  with value `null` is a **fail**.
- **Throwaway data** created by scenarios is prefixed `QA-` (names/codes) or
  `qa_` (role names), so it is easy to find and clean up.
- **Side effects:** SQL checks target MySQL database `raqi_erp`. Audit checks use
  `GET /api/auth/audit-logs` as SA.
- **Time-dependent checks** give a practical technique, e.g. setting
  `SANCTUM_TOKEN_EXPIRATION=1` and restarting `php artisan serve`, or updating a
  timestamp column by SQL. Revert the change afterwards.
- **Rate limits** persist in the cache (`CACHE_STORE=database`). Run
  `php artisan cache:clear` after throttle scenarios, or later scenarios will
  get unexpected `429`s.

## Recording results

Each scenario ends with `Result: ☐ Pass ☐ Fail — Notes`. For a run log, copy
this table (also provided in file 01):

| Scenario ID | Date | Tester | Build / commit | Result | Bug link / notes |
|-------------|------|--------|----------------|--------|------------------|
| | | | | | |

A failure report should include the scenario ID, the request (method, path, body),
the response status and body, and the `X-Request-Id` response header.

## Endpoint → file map

Every route in the system, and the file that covers it in depth.

| Method | Path | Guard | File |
|--------|------|-------|------|
| POST | `/api/auth/login` | public, `throttle:auth-login` | 02 |
| POST | `/api/auth/refresh` | public, `throttle:auth-refresh` | 02 |
| POST | `/api/auth/logout` | auth (self) | 02 |
| GET | `/api/auth/me` | auth (self) | 02 |
| POST | `/api/auth/password` | auth (self) | 02 |
| GET | `/api/auth/roles` | `auth.roles.view` | 04 |
| POST | `/api/auth/roles` | `auth.roles.create` | 04 |
| POST | `/api/auth/roles/assign` | `auth.roles.assign` | 04 |
| POST | `/api/auth/roles/unassign` | `auth.roles.assign` | 04 |
| PUT/PATCH | `/api/auth/roles/{role}` | `auth.roles.update` | 04 |
| DELETE | `/api/auth/roles/{role}` | `auth.roles.delete` | 04 |
| GET | `/api/auth/users` | `auth.users.view` | 03 |
| POST | `/api/auth/users` | `auth.users.create` | 03 |
| PUT/PATCH | `/api/auth/users/{user}` | `auth.users.update` | 03 |
| DELETE | `/api/auth/users/{user}` | `auth.users.delete` | 03 |
| GET | `/api/auth/audit-logs` | `auth.audit.view` | 12 |
| GET | `/api/auth/audit-logs/{id}` | `auth.audit.view` (+ `auth.audit.viewValues` for values) | 12 |
| GET | `/api/auth/notifications` | auth (owner), not audited | 05 |
| GET | `/api/auth/notifications/unread-count` | auth (owner), not audited | 05 |
| POST | `/api/auth/notifications/{id}/read` | auth (owner), audited | 05 |
| GET | `/api/departments` | `departments.view` | 06 |
| POST | `/api/departments` | `departments.create` | 06 |
| GET | `/api/departments/{id}` | `departments.view` | 06 |
| PUT/PATCH | `/api/departments/{id}` | `departments.update` | 06 |
| DELETE | `/api/departments/{id}` | `departments.delete` | 06 |
| GET | `/api/hr/employees` | `hr.employees.view`, filtered by scope | 07, 08 |
| POST | `/api/hr/employees` | `hr.employees.create` (+ target unit in scope) | 07, 08 |
| GET | `/api/hr/employees/{id}` | `hr.employees.view` + `can:view` | 07, 08 |
| PUT/PATCH | `/api/hr/employees/{id}` | `hr.employees.update` + `can:update` | 07, 08 |
| DELETE | `/api/hr/employees/{id}` | `hr.employees.delete` + `can:delete` | 07, 08 |
| GET | `/api/hr/job-titles` | `hr.job_titles.view` | 09 |
| POST | `/api/hr/job-titles` | `hr.job_titles.create` | 09 |
| GET | `/api/hr/job-titles/{id}` | `hr.job_titles.view` | 09 |
| PUT/PATCH | `/api/hr/job-titles/{id}` | `hr.job_titles.update` | 09 |
| DELETE | `/api/hr/job-titles/{id}` | `hr.job_titles.delete` | 09 |
| GET | `/api/hr/employees/{id}/assignments` | `hr.assignments.view` + `can:viewAssignments` | 10 |
| POST | `/api/hr/employees/{id}/assignments` | `hr.assignments.create` + `can:recordAssignment` | 10 |
| GET | `/api/hr/employees/{id}/warnings` | `hr.warnings.view` + `can:viewWarnings` | 11 |
| POST | `/api/hr/employees/{id}/warnings` | `hr.warnings.create` + `can:createWarning` | 11 |
| PUT/PATCH | `/api/hr/warnings/{id}` | `hr.warnings.update` + `can:update` | 11 |
| DELETE | `/api/hr/warnings/{id}` | `hr.warnings.delete` + `can:delete` | 11 |
| — | console: prune expired refresh tokens | scheduler | 02 |

Every authenticated route other than `/auth/logout`, `/auth/me` and
`/auth/password` is also behind the forced password change gate
(`must_change_password`). Each file has at least one scenario for it, and file 13
sweeps it across all modules.

## Suite summary

<!-- SUMMARY -->
