# Legacy → ERP mapping: attendance, leave, approvals, devices, notifications

> **Status: first pass, written 2026-10-05.** Built from the legacy code itself
> (`../Raqi-App`: migrations, controllers, services, `routes/api.php`,
> `config/attendance.php`, `config/leave.php`, `app/Console/Kernel.php`) and the
> Flutter client (`../doc_approval/lib`), mapped onto this repository as it stands
> on branch `phase_1`. Nothing here is built yet.
>
> **Read with** [`EMPLOYEE_APP_V1_WORK_ORDER.md`](EMPLOYEE_APP_V1_WORK_ORDER.md),
> which says in what order this gets built and what v1 ships. This file says
> **what each legacy thing becomes**. Where a row needs a decision, it says
> **⚠️ DECIDE** and points at the work order's §3, where the decision is listed
> with a recommended default.
>
> **Out of scope here:** documents, templates, numbering, stamps and the document
> workflow. They move in November, onto the approvals engine this file defines,
> and get their own mapping then.

---

## Table of contents

1. [Five findings that change the plan](#1-five-findings-that-change-the-plan)
2. [Identity: legacy users → ERP employees and accounts](#2-identity-legacy-users--erp-employees-and-accounts)
3. [The org: departments and sections](#3-the-org-departments-and-sections)
4. [Attendance](#4-attendance)
5. [Leave](#5-leave)
6. [Approvals — the shared engine](#6-approvals--the-shared-engine)
7. [Sessions, devices and push tokens](#7-sessions-devices-and-push-tokens)
8. [Notifications](#8-notifications)
9. [Permissions](#9-permissions)
10. [Endpoints, legacy → ERP](#10-endpoints-legacy--erp)
11. [The Flutter client](#11-the-flutter-client)
12. [What data actually moves](#12-what-data-actually-moves)

---

## 1. Five findings that change the plan

Found while mapping, and each one is load-bearing.

1. **Legacy deletes attendance after one month.** `attendance:cleanup` runs on
   the 1st of every month at 02:00 and deletes every `attendance_records` row
   older than a month, selfie files included
   (`app/Console/Commands/CleanupAttendanceRecords.php`). So:
   - there is **no attendance history to migrate** — at most the last month;
   - the Stage 3 exit criterion ("reconcile a month against legacy") must
     export legacy's figures for the reconciled month before the 1st of the
     month *after next* deletes them;
   - **the ERP must not port this command.** Stage 4 payroll reads attendance;
     a system that deletes its own payroll input monthly cannot run payroll.
     Selfie *files* may still need a retention limit — that is a separate,
     smaller decision (work order §3, D7).

2. **In legacy, the employee chooses their own approvers.** `StoreLeaveRequest`
   accepts `approver_ids[]` (or a single `manager_id`) from the client, and the
   Flutter app ships an approver picker
   (`core/widgets/approver_picker_sheet.dart`, `reorderable_approver_list.dart`)
   fed by `GET /attendance/leave-managers`. The ERP **resolves** the chain
   instead — `ManagementChain` for step 1 (decision 15) and HR for step 2
   (`EMPLOYEE_APP_REQUIREMENTS.md` §4.2). The picker is removed from the app,
   not ported. Manual reassignment survives as an HR action.

3. **The ERP's 15-minute access token will log every app user out.** The Flutter
   client holds **one** token, and on any `401` it clears storage and returns to
   login (`core/network/api_client.dart`). Legacy tokens last 30 days, so that
   was fine. Against the ERP — 15-minute access tokens, 30-day rotating refresh
   tokens with **replay detection** — it means a logout every quarter hour. The
   client needs a refresh interceptor, and it must be **single-flight**: two
   requests refreshing at once present the same refresh token twice, the second
   is treated as theft, and the whole session is revoked. That is the most
   likely way v1 fails in the field, so it gets its own test (work order §4.11).

4. **Legacy has no "late" and no "holiday".** The app spec's day states are
   حاضر / متأخر / إجازة / غياب / عطلة. Legacy's check-in window is empty (open 24
   hours), so nobody is ever late, and its calendar is a `working_days` list that
   currently holds **all seven days** — no weekend, no holidays. `متأخر` needs a
   shift start time; `عطلة` needs a holiday table. Neither exists anywhere (work
   order §3, D4 and D5).

5. **Selfies are on the public disk.** Legacy stores them with
   `Storage::disk('public')`, so a selfie is reachable by anyone who has or
   guesses its URL. A dated face photo at a GPS position is personal data. The
   ERP stores them on a **private** disk and serves them only through an
   authorized endpoint — the same rule `employee_documents` already follows.

---

## 2. Identity: legacy users → ERP employees and accounts

Legacy has one entity, `users`, that is both the person and the login. The ERP
splits them (`START_HERE.md` §5.4): `employees` is the person, `users` is an
optional account, linked by the unique `users.employee_id`.

**The rule for every ported table:** a row that is *about a person* — an
attendance event, a leave, a balance, an approval step — points at
`employees.id`. A column that records *who pressed a button* — `corrected_by`,
`created_by`, `rejected_by`, `cancelled_by` — points at `users.id`, because the
actor may be an account with no employee (the technical department,
`super_admin`). Legacy uses `user_id` for both; every one of them is sorted into
one bucket or the other below.

### `users` (legacy) — column by column

| Legacy column | ERP | Notes |
|---|---|---|
| `id` | — | Not carried. Used only as the key of the link table below |
| `name` | match hint only | Matched against `employees.full_name` to build the link table. Never written |
| `email` | `users.email` — **not carried** | The ERP account is created by the importer from the employee row. Legacy emails could seed the contact field later; not in v1 |
| `password` | **not carried** | Fresh accounts with forced first-login change — `EMPLOYEE_APP_REQUIREMENTS.md` §7.1, and its "recommendation on the legacy accounts" (⚠️ ratify — work order §3, D1) |
| `department_id`, `section_id` | match hint only | The ERP tree is the authority (§3) |
| `role` (`admin`/`manager`/`chief`/`employee`) | ERP role grants | See §9. `manager` and `chief` do not become roles: what they meant is now the tree (`departments.manager_id`) plus a scoped grant |
| `fcm_token` | **not carried** | The app re-registers on first ERP login, into `device_tokens` (§7) |
| `attendance_check` | ⚠️ DECIDE — D3 | "This person checks in." Recommended: every active employee with an account may record their own attendance, no flag. An exemption, if HR needs one, is a fact about the employee, not the account |
| `can_view_attendance` | `attendance.records.view`, **scoped** | Becomes a GAP-3 grant: company, a unit, or «الوحدات التي أديرها». Strictly better than the legacy boolean, which is all-or-nothing |
| `deleted_at` | — | Soft-deleted legacy users are not linked |

### The link table — `legacy_user_links`

Leave balances and leave history must move (§12), and they are keyed on legacy
`users.id`. Legacy `users` has **no employee number** — only a name — so the
link is a name match, and the workbook's own `مقارنة الأسماء` column proves name
matching between this company's systems fails often enough to need review.

So the link is **a staging table owned by the migration**, the same pattern as
the importer key (decision 11): `legacy_user_id` → `employee_id`, with a
`match_method` (exact / normalised / manual) and a `confirmed_by`. The migration
emits the unmatched and the ambiguous as a report for HR, and moves nothing for
a legacy user until their link is confirmed. It never becomes a column on
`employees` — decision 10, kept.

Arabic name normalisation for the automatic pass: strip tatweel and diacritics,
fold `أ إ آ → ا`, `ة → ه`, `ى → ي`, collapse whitespace, and compare first + last
name before trying the full name.

---

## 3. The org: departments and sections

| Legacy | ERP | Notes |
|---|---|---|
| `departments` (flat, `code`, `stamp`) | `departments` tree, levels 1–5 | **No data moves.** The ERP tree is seeded from the org chart (Batch 1) and is the authority |
| `sections` (`department_id`, `stamp`) | `departments` rows at the section tier | Same |
| `stamp` (moved from departments to sections in legacy) | Documents, November | A stamp is a document-rendering concern |
| `leave_requests.department_id/section_id`, `attendance_records.department_id/section_id` (ownership snapshots, `2026_08_31_000003`) | **dropped** — ⚠️ DECIDE D6 | Legacy stamps the requester's unit on each row so authorization survives a transfer. The ERP's GAP-3 scope reads the employee's **current** unit. Recommended: follow the current unit, as GAP-3 already does for employees — a new manager inherits the whole history, the old one loses it. If a snapshot is ever needed, `employee_assignments` already answers "where was this person on date X" without a copy on every row |

The legacy two-tier picture (department → section) is used **only** as a hint
when building `legacy_user_links`.

---

## 4. Attendance

New module: `app/Modules/Attendance`.

### `attendance_records`

| Legacy column | ERP column | Notes |
|---|---|---|
| `id` | `id` | |
| `user_id` | `employee_id` → `employees` | About the person. `restrictOnDelete`, not cascade: an attendance row is a payroll input |
| `type` enum(`check_in`,`check_out`) | `type` tinyint, `AttendanceEventType` | House style: int-backed enum + `type_label` in the resource |
| `status` string | `status` tinyint, `AttendanceStatus` | Legacy values: `checked_in`, `checked_out`, `present`, `on_leave`, `missing_checkout`, `excused`, `absent`, `off`, `rejected`. ⚠️ Only some are **stored** row states; `absent`, `off`, `on_leave` are **derived** day states the reports compute. The port keeps that split — store event states, derive day states — and the enum says which is which |
| `work_hours` decimal(5,2) | `work_hours` decimal(5,2) | Derived from the pair, written on the check-out row. Kept because every report sums it; the service that writes it is the one place it is computed, and a test pins it to the pair |
| `latitude`, `longitude` decimal(10,7) | same | |
| `selfie_path` (public disk, nullable) | `selfie_path` (**private** disk, nullable) | Finding 5. Null only on an HR-corrected check-out |
| `recorded_at` (client time) | `recorded_at` | The moment the employee tapped, as the phone saw it. Parsed in the attendance timezone |
| `created_at` | `created_at` | Server receipt time. The gap between the two is how an offline submission is recognised |
| — | `client_uuid` uuid, nullable, `unique(employee_id, client_uuid)` | **New.** Legacy dedups by business rule only ("already checked in today"), so a sync retried after a network timeout comes back as an error for a record that did save. With a client id the retry answers success with the existing row. Nullable so legacy-format submissions still land (work order §3, D9) |
| — | `source` tinyint (`online` / `offline_sync` / `hr_correction`) | **New.** Legacy infers it from which endpoint was hit; the reports and the parallel-run reconciliation want it stored |
| `corrected_by`, `corrected_at`, `correction_note` | same; `corrected_by` → `users` | Actor |
| `rejected_at`, `rejected_by`, `rejection_reason`, `rejection_note` (text), `rejection_batch` uuid | same; `rejected_by` → `users` | The batch is what lets an un-refusal restore exactly the rows one decision took down — keep its reasoning (the migration docblock) |
| indexes `(user_id, recorded_at)`, `(recorded_at)`, `(user_id, status)`, `(user_id, rejected_at)`, `(rejection_batch)` | same on `employee_id` | Plus `unique(employee_id, client_uuid)` |

### Configuration — `config/attendance.php`, ported as is

| Key | Legacy value | Note |
|---|---|---|
| `timezone` | app timezone | Single timezone, as legacy |
| `working_days` | all seven | No weekend today. Kept as config; holidays are a table (D5) |
| `check_in.start` / `.end` | empty (open 24h) | Also where "late" would hang — D4 |
| `min_checkout_gap_minutes` | 60 | Fat-finger guard; HR corrections exempt |
| `reminder_at` | 17:00 | |
| `auto_close_at` | 23:59 | ⚠️ `Kernel.php` falls back to `22:00` when the key is missing and the config file says `23:59`. Pick one default in the port |
| `allow_attendance_during_leave` | false | |

### Scheduled commands

| Legacy | ERP | |
|---|---|---|
| `attendance:checkout-reminder` | ported | Pushes to everyone still checked in at `reminder_at` |
| `attendance:auto-close` | ported | Flags open check-ins `missing_checkout`; writes no fake check-out and no hours |
| `attendance:cleanup` | **not ported** | Finding 1 |
| `attendance:seed-demo` | not ported | Dev tool |

### New, not in legacy

- **`public_holidays`** (`date` unique, `name`, `created_by`) — if D5 says yes.
  `isChargeableDay()` already names itself as the hook; port it with the table
  behind it, and both attendance expectations and leave deduction inherit it.
- **Shift start** for `متأخر` — only if D4 gives a time. Otherwise the app's
  day states for v1 are حاضر / غياب / إجازة / (عطلة).

---

## 5. Leave

New module: `app/Modules/Leave`.

### `leave_types` — ported as is

`code` (unique), `name_ar` (unique), `name_en` (nullable unique),
`deducts_balance`, `for_requests`, `for_excuses`, `requires_reason`,
`is_active`, timestamps. Seeded with the same six rows legacy seeds:
`annual`, `emergency`, `sick`, `official_mission`, `bereavement`, `unpaid`.

**New:** `requires_attachment` boolean — `true` for `sick`
(`EMPLOYEE_APP_REQUIREMENTS.md` §5.6: a sick leave without the medical report
is not a sick leave).

### `leave_balances`

| Legacy | ERP | Notes |
|---|---|---|
| `user_id` | `employee_id` | `unique(employee_id, year)` |
| `year`, `allocated_days`, `adjustment_days`, `note` | same | Adjustment stays separate from allocation, as legacy argues |
| `updated_by` | `updated_by` → `users` | Actor |
| `config('leave.annual_days')` = 15 | same config key | The default allocation when no row exists for the year. ⚠️ Confirm 15 with HR — the workbook may say otherwise |

Remaining balance = allocation + adjustment − approved, balance-deducting,
non-cancelled working days in the year. Computed, never stored (legacy does the
same — `balancePayload()`).

### `leave_requests`

| Legacy column | ERP column | Notes |
|---|---|---|
| `user_id` | `employee_id` | About the person |
| `manager_id` | **dropped** | Legacy mirrors "the current approver" here so old clients keep working. In the ERP the approval steps hold it — one fact, one place (P1) |
| `created_by` | `created_by` → `users` | Set when HR files on someone's behalf |
| `leave_type` (free text) | **dropped** | Kept in legacy for rows from before `leave_types` existed. The migration resolves those through `leave_types.code`/`name_ar`; a row that cannot resolve is reported, not guessed |
| `leave_type_id` | `leave_type_id`, **required** | |
| `deducts_balance` | `deducts_balance` | A deliberate snapshot: the policy at filing time. Legacy's migration docblock explains why back-filling it would hand people days back. Keep the reasoning |
| `is_excuse` | `origin` tinyint (`request` / `hr_excuse`) | An HR-filed excuse has no approval |
| `reason` | `reason` (text, ≤ 2000) | |
| `start_date`, `end_date` | same | |
| `requested_days` | `requested_days` | Snapshot of working days at filing; the calendar can change later |
| `status` enum(`pending`,`approved`,`rejected`,`cancelled`) | `status` tinyint, `LeaveStatus` | Mirrors the approval's outcome. Written only by the approvals completion hook, so the two cannot disagree |
| `reviewed_at` | `decided_at` | When the last step decided |
| `cancelled_at`, `cancelled_by`, `cancellation_reason` | same; `cancelled_by` → `users` | |
| `returned_to_work_on`, `original_end_date`, `shortened_at`, `shortened_by`, `shortening_reason` | same; `shortened_by` → `users` | Return-to-work, shipped in legacy 2026-09-21. Keep the docblock reasoning about why shortening does not touch the cancellation columns |
| `department_id`, `section_id` (snapshot) | dropped | §3, D6 |
| — | attachments | **New** (§5.6). Recommended shape: a polymorphic `attachments` table in `app/Shared` on the private disk, because documents need exactly the same thing in November. Do not build a leave-only table to replace a month later |

### `leave_request_approvals`, `leave_approval_reassignments`

They become the generic `approval_steps` and `approval_reassignments` (§6). The
leave module keeps no approval table of its own — that is the whole reason for
building the engine first.

### Rules that move with the code

From `LeaveRequestController::store()` and its tests — each one is a test to port:

- Requested days are **working** days in the span; a span with none is refused.
- A balance-deducting request may not exceed the remaining balance; a
  non-deducting one (sick, unpaid, mission, bereavement) is never blocked by it.
- A request may not overlap a pending or approved one.
- `requires_reason` types need a reason; `requires_attachment` types need a file.
- `for_requests = false` types (official mission) can only be filed by HR as an
  excuse.
- Rejection at any step closes the remaining steps as `skipped`.
- Cancel, excuse-cancel and return-to-work behave as their legacy tests say
  (`LeaveExcuseCancellationTest`, `LeaveReturnToWorkTest`).

---

## 6. Approvals — the shared engine

New module: `app/Modules/Approvals`. Legacy has **two** implementations —
`document_workflows` for documents, `leave_request_approvals` for leave. The ERP
has one, and leave is its first consumer.

### Tables

**`approvals`** — one per subject.

| Column | Notes |
|---|---|
| `approvable_type`, `approvable_id` | Polymorphic subject: a leave request now, a document in November, purchase and payroll later. Morph aliases, as the audit trail already uses |
| `requested_by` → `employees` | Whose request this is. The chain resolves from here |
| `status` tinyint | pending / approved / rejected / cancelled |
| `decided_at` | |
| timestamps | |

**`approval_steps`**

| Column | Notes |
|---|---|
| `approval_id` | |
| `step_order` smallint | Steps sharing an order are **parallel** (all must approve); ascending orders are **sequential**. That is the whole of "sequential and parallel" — documents need parallel in November, leave needs sequential now, one column serves both |
| `resolver` tinyint | `management_chain` / `unit_manager` (a named post) / `named_employee` — how the approver was chosen, kept so a re-resolution after a vacancy is explainable |
| `approver_id` → `employees` | The person who must act. A person, not an account: `departments.manager_id` points at employees |
| `status` tinyint | pending / approved / rejected / skipped |
| `acted_by` → `users`, `acted_at`, `note` | Who actually pressed the button (could be a reassignee's account) |
| `unique(approval_id, step_order, approver_id)` | Legacy's `unique(leave_request_id, user_id)` is too strict for parallel steps across orders; this is the ERP shape |

**`approval_reassignments`** — legacy's log, generalised: `approval_step_id`,
`step_order` (denormalised, as legacy argues), `from_employee_id`,
`to_employee_id`, `performed_by` → `users`, `reason` (text).

### The leave chain

Decided (`EMPLOYEE_APP_REQUIREMENTS.md` §4.2): **the direct manager, then مدير
الموارد, then done.**

- **Step 1** — `ManagementChain` for the requester (decision 15: climb past a
  vacant post or past the requester themselves; end at المدير العام).
- **Step 2** — the manager of the unit coded `HR` (إدارة الموارد البشرية), as a
  named post. Configured by department code, not by employee id, so a change of
  HR manager needs no deploy.
- ⚠️ **DECIDE — D2: when the two steps resolve to the same person.** An HR
  employee's direct manager may well *be* مدير الموارد, and the HR manager's own
  leave climbs to someone above HR. Recommended: consecutive steps that resolve
  to the same person collapse into one, recorded as `skipped` with a reason, so
  nobody approves their own request and nobody approves twice.

### The surface

- `submit(subject, requester, steps)` — opens the approval and its steps.
- `act(step, user, approve|reject, note)` — the acting user's `employee_id` must
  equal the step's `approver_id`; advances the sequence; on the last approval or
  the first rejection, fires the subject's completion hook.
- `reassign(step, toEmployee, reason, performedBy)` — needs
  `approvals.reassign`, scoped (§9).
- `pendingFor(employee)` — the approver's inbox, for the app and the dashboard.
- Notifications on submit, on each step becoming current, and on the outcome
  (§8).

---

## 7. Sessions, devices and push tokens

| Legacy | ERP | Notes |
|---|---|---|
| Sanctum personal access token, 30 days, `platform`, `device_name` | access token 15 min + `refresh_tokens` 30 days, rotating, replay detection | The ERP model wins (merge plan, Stage 3) |
| `GET /devices`, `DELETE /devices/{tokenId}`, `POST /logout-all-devices` | `GET /api/auth/sessions`, `DELETE /api/auth/sessions/{id}`, `POST /api/auth/logout-all` | The session is the refresh-token family. Add `device_name`, `platform`, `last_used_at` to `refresh_tokens` so the list says something a person recognises |
| `users.fcm_token` (one per user) | **`device_tokens`**: `user_id`, `token` (unique), `platform`, `refresh_token_family` (nullable), `last_seen_at` | `EMPLOYEE_APP_REQUIREMENTS.md` §6.4: one column loses the second device silently. Logging a session out deletes that session's push token, so a revoked phone stops receiving notifications |
| `POST/DELETE /device-tokens` | `POST/DELETE /api/me/device-tokens` | Self-scoped |
| `config/firebase.php`, `PushNotificationService` | ported into `app/Shared` as the notification transport | ⚠️ Reuse the legacy Firebase project — merge plan open decision 2 recommends it, and v1 needs an answer (work order §3, D8) |

---

## 8. Notifications

| Legacy | ERP (exists) | Port |
|---|---|---|
| `title`, `body` | `title`, `body` | — |
| `type` string (`general`, `document`, `approval`) | `type` | Names come from a catalogue (below) |
| `data` json (e.g. `document_id`) | `reference_type` / `reference_id` (morph) | The app deep-links from the reference. Legacy's free-form `data` is not carried |
| `read_at` timestamp | `is_read` bool | The ERP chose a boolean; keep it |
| `broadcast_id` uuid + `unique(broadcast_id, user_id)` | snapshot fan-out (`7d75f10`) | Already ERP-side |
| `POST notifications/read-all` | **missing** | Add `POST /api/auth/notifications/read-all` |
| FCM push on create | **missing** | A listener on notification creation pushes to the recipient's `device_tokens` |

**The event catalogue** (`EMPLOYEE_APP_REQUIREMENTS.md` §5.7) — named once, in
the pattern of `AuditEventCatalogue`. v1 needs:

| Event | To |
|---|---|
| `leave.submitted` | the requester (receipt) |
| `approval.step_pending` | the approver whose step became current |
| `leave.approved` / `leave.rejected` | the requester |
| `leave.cancelled`, `leave.excuse_filed`, `leave.excuse_cancelled` | the employee |
| `attendance.checkout_reminder` | everyone still checked in at `reminder_at` |
| `attendance.missing_checkout` | the employee, at auto-close |
| `attendance.record_rejected` / `.unrejected` | the employee |

The spec's other triggers — salary deposited, salary updated, circular, contract
ending — have no data source until payroll, documents and contracts exist.

---

## 9. Permissions

Every new permission must be classified in `PermissionReach` — **row-scoped**,
**catalogue read**, or neither — because a scoped grant confers only what
something enforces a scope for (GAP-15, decisions 21–23). A permission left
unclassified is dropped from every scoped grant.

| Legacy | ERP | Reach |
|---|---|---|
| `attendance.record_self` + `attendance-recording-enabled` | **no permission** — self-scoped `/api/me/attendance` | n/a (D3) |
| `attendance.report.view` / `can_view_attendance` | `attendance.records.view` | row-scoped (through the employee's unit) |
| `can-correct-attendance-checkout` middleware | `attendance.records.correct` | row-scoped |
| `attendance.reject_record` | `attendance.records.reject` | row-scoped |
| `leave.register.view` | `leave.requests.view` | row-scoped |
| `leave_types.manage` | `leave.types.create` / `.update` / `.delete` (+ `leave.types.view`) | catalogue — company-wide only |
| (inside `attendance.report.view`) `PUT users/{user}/leave-balance` | `leave.balances.update` (+ `leave.balances.view`) | row-scoped |
| (inside `attendance.report.view`) excuse, cancel excuse, return-to-work | `leave.excuses.create`, `leave.excuses.delete`, `leave.requests.returnToWork` | row-scoped |
| `leave.reassign_approver` | `approvals.reassign` | row-scoped (through the requester) |
| `notifications.broadcast` | `notifications.broadcast` — **new**, with a route | `NotificationService::sendToDepartment/sendToRole` exist but no endpoint calls them. Not v1: the app's broadcast screen is an admin tool and stays on legacy until the dashboard has it |
| role `employee` | nothing | Self-service needs no permission (`EMPLOYEE_APP_REQUIREMENTS.md` §6.1) |
| role `manager` / `chief` | a scoped grant of an HR/attendance role, scope «الوحدات التي أديرها» | The tree replaces the label |
| role `admin` | the ERP `admin` role | `super_admin` stays with the technical department |

Approving is **not** a permission: you may act on a step because you are its
`approver_id`. That is what makes a vacancy climb safe — the chain names a
person, and nobody else can act.

---

## 10. Endpoints, legacy → ERP

All legacy routes are under `/api`; the ERP keeps its own envelope
(`{success, data, message}`) and `X-Request-Id` on every one.

### Self-service — what the app calls (`/api/me`, no permission middleware)

| Legacy | ERP |
|---|---|
| `GET /me` | `GET /api/auth/me` (account) + **`GET /api/me/profile`** (the person, with the full pay block for the owner — decision 17, D10) |
| — | **`GET /api/me/summary`** — the home screen; each card `null` until its module exists (§6.2 of the requirements) |
| `POST /attendance/record` | `POST /api/me/attendance` |
| `POST /attendance/sync` | `POST /api/me/attendance/sync` — accepts the legacy payload shape (`records[]` + `selfies[]`) plus the optional `client_uuid` |
| `GET /attendance/my-records` | `GET /api/me/attendance` |
| `GET /attendance/leave-balance` | `GET /api/me/leave-balance` |
| `GET /attendance/leave-types` | `GET /api/leave/types` (catalogue read, any authenticated caller) |
| `GET /attendance/leave-requests` | `GET /api/me/leave-requests` |
| `POST /attendance/leave-requests` | `POST /api/me/leave-requests` — **no `approver_ids`**; the chain is resolved |
| `GET /attendance/leave-managers` | **removed** (finding 2) |
| `GET /attendance/leave-requests/approvals` | `GET /api/me/approvals` — my pending steps |
| `PATCH /attendance/leave-requests/{id}/review` | `POST /api/approvals/steps/{step}/approve` and `/reject` |
| `GET/DELETE /devices…`, `POST /logout-all-devices` | §7 |
| `POST/DELETE /device-tokens` | `POST/DELETE /api/me/device-tokens` |
| `GET notifications`, `unread-count`, `{id}/read`, `read-all` | `/api/auth/notifications/…` (exists, plus `read-all`) |

### HR and managers — dashboard first, app later

| Legacy | ERP | Permission |
|---|---|---|
| `GET /attendance/records`, `/report`, `/report/monthly`, `/report/employee`, `/absent-today` | `GET /api/attendance/records`, `/reports/daily`, `/reports/monthly`, `/reports/employee/{employee}`, `/absent-today` | `attendance.records.view` |
| `PATCH /attendance/records/{id}/checkout` | `PATCH /api/attendance/records/{record}/checkout` | `attendance.records.correct` |
| `PATCH` / `DELETE /attendance/records/{id}/reject` | `POST` / `DELETE /api/attendance/records/{record}/rejection` | `attendance.records.reject` |
| `GET /attendance/leave-balances`, `PUT users/{user}/leave-balance` | `GET /api/leave/balances`, `PUT /api/leave/balances/{employee}/{year}` | `leave.balances.*` |
| `POST /attendance/leave-requests/excuse`, `DELETE …/{id}/excuse` | `POST /api/leave/excuses`, `DELETE /api/leave/excuses/{leaveRequest}` | `leave.excuses.*` |
| `POST …/{id}/return-to-work` | `POST /api/leave/requests/{leaveRequest}/return-to-work` | `leave.requests.returnToWork` |
| `GET /attendance/leave-register`, `/approved-leave-users` | `GET /api/leave/requests` (filters) | `leave.requests.view` |
| `PATCH …/{id}/reassign` | `POST /api/approvals/steps/{step}/reassign` | `approvals.reassign` |
| `POST/PATCH/DELETE admin/leave-types` | `/api/leave/types` CRUD | `leave.types.*` |
| `GET /attendance/departments` (lite) | `GET /api/departments` (exists) | `departments.view` |

---

## 11. The Flutter client

What changes in `../doc_approval` to talk to the ERP. Ordered by how badly v1
fails without it.

| # | Change | Where |
|---|---|---|
| 1 | **Single-flight refresh interceptor** — on `401`, one refresh, queued retries; never refresh on `403` | `core/network/api_client.dart` (finding 3) |
| 2 | Store access + refresh tokens; logout calls the ERP logout | `core/storage/token_storage.dart`, `features/auth` |
| 3 | Login field `email` → `login` (employee number); the forced-password-change screen, which every first login hits | `features/auth` |
| 4 | Parse the ERP envelope and its Arabic error messages | `core/network`, `core/errors/api_failure.dart` |
| 5 | `applicationId` `com.example.doc_approval` → a real id. ⚠️ A **different** id from the legacy build, so the pilot app and the legacy app install side by side (D11) | `android/app/build.gradle*`, iOS bundle id |
| 6 | Remove the approver picker; show the resolved chain read-only | `core/widgets/approver_*`, `core/validation/approver_validation.dart`, leave form |
| 7 | Leave attachment (camera / file) | leave form |
| 8 | Send `client_uuid` with every queued attendance record | `attendance_local_db.dart`, `pending_attendance_record.dart`, sync service |
| 9 | Employee shape: `full_name`, `job_title` object, `{field}` + `{field}_label` enums | `features/auth/domain/user.dart` and readers |
| 10 | Home screen from `/api/me/summary` with cards that may be `null`; profile screen from `/api/me/profile` | new |
| 11 | Push token registration against `/api/me/device-tokens` | `core/services/push_notification_service.dart` |
| 12 | Hide the documents tab in the v1 build | router — documents stay on the legacy app until November |

---

## 12. What data actually moves

| Data | Moves? | Why |
|---|---|---|
| Employees, tree, job titles | **Yes — from the HR workbook and org chart**, not from legacy | Batch 3/4 (`START_HERE.md` §10) |
| Accounts | **New**, created by the importer | §7.1 of the requirements |
| `legacy_user_links` | Built, HR-reviewed | Needed only to carry the two rows below |
| Leave balances for the current year | **Yes**, via the links | Otherwise every balance resets at cutover |
| Leave requests for the current year, and any pending or future approved ones | **Yes**, via the links | The balance is computed from them; a future approved leave must still count |
| Pending leave approvals at cutover | **Drain, then move** | Announce a window; approve or reject in legacy; carry decided rows only. Re-resolving a half-finished legacy chain onto the ERP chain is not worth its edge cases |
| Attendance records | **No** | At most one month exists (finding 1). The ERP starts from day one of the pilot; legacy's last month is captured for the reconciliation, not imported |
| Selfies | **No** | Same |
| Notifications, device tokens, sessions | **No** | Re-created by use |
| Legacy RBAC assignments | **No** | Re-granted on ERP roles with scopes (§9) |

**During the pilot nothing moves at all.** The pilot starts empty on the ERP
side and legacy stays the record of pay. The moves above happen once, at the
company-wide cutover.
