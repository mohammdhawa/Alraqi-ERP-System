# Employee App v1 on the ERP — the work order

> **Status: planned 2026-10-05. Nothing below is built.** Target: a **pilot
> release by Sunday 2026-11-01**, legacy still running and still the record of
> pay; company-wide cutover around **2026-12-01** after a reconciled month.
>
> **Read this first, then** [`LEGACY_TO_ERP_MAPPING.md`](LEGACY_TO_ERP_MAPPING.md)
> (what every legacy table, route and permission becomes),
> [`EMPLOYEE_APP_REQUIREMENTS.md`](EMPLOYEE_APP_REQUIREMENTS.md) (the app spec,
> analysed 2026-09-21) and [`START_HERE.md` §10](START_HERE.md) (Batch 3, the
> importer). Written as the handoff into a fresh session, so nothing here depends
> on remembering a conversation.
>
> **State of the tree when this was written:** branch `phase_1` at `74a4ab7`,
> 485 tests passing (2026-09-28), no open gaps. Uncommitted: today's edits to
> `START_HERE.md` (the payslip-snapshot rule and §11 question 6) and an
> unfinished `docs/manual-test-scenarios/` (only its README exists).

---

## 1. What v1 is

**Decision 25 (ratified by the project owner, 2026-10-05).** The Employee App
moves onto the ERP, and the order of the merge changes to get it there:

- The Flutter app `../doc_approval` is **kept and re-pointed** at the ERP — not
  rewritten. It is ~24,600 lines and already does attendance, leave, documents,
  notifications and login.
- `../Raqi-App` (the legacy backend) is retired module by module, as the merge
  plan always said.
- **The approvals engine is built generic, with leave as its first consumer**
  and documents as its second in November. This reverses Stages 2 and 3 of
  `legacy-system-merge-plan.md` §3 for leave and attendance, and keeps the rule
  that made that order: **one approval implementation, not two.**
- **v1 is a pilot, not a cutover.** It ships to a pilot group at the end of
  October while legacy keeps running for everyone, including the pilot group's
  pay. Legacy goes dark only after the Stage 3 exit criterion — a full month on
  the ERP, reconciled against legacy — which this work order keeps exactly as
  written.

### In v1

| Area (spec) | v1 |
|---|---|
| حساب الموظف — home | Name, photo (if the import has one), job title, unit, employee number, **direct manager**, today's attendance state, leave balance, unread notifications and pending requests. Salary card `null` |
| الدوام والحضور | Check in/out with GPS + selfie, offline queue, history by day |
| الإجازات | Request with type, reason and **attachment**; balance; the two-step chain (direct manager → مدير الموارد); cancel |
| الطلبات — as approver | My pending approvals; approve / reject with a note — **in the app and in the dashboard** (decided 2026-10-14, §8) |
| الملف الوظيفي | Read-only profile from `/api/me/profile` |
| الإشعارات | In-app list + FCM push for the v1 event catalogue |
| Account | Login by employee number, forced first-login password change, my sessions, log out a device |

### Not in v1, and why

| Area | When | Why |
|---|---|---|
| الراتب | Stage 4 | No payroll exists to read from |
| تقييم الأداء | unowned | No stage builds it (`EMPLOYEE_APP_REQUIREMENTS.md` §5.1) |
| العقود والمستندات، "قرأت ووافقت" | November | Documents move onto the approvals engine after leave |
| Documents tab | November | Hidden in the v1 build; the pilot keeps the legacy app for documents |
| السلف، العهد | out of scope | Ratified as إدارة المالية's, not HR's (2026-09-22) |
| Contracts, certificates, experience | later | No record exists (§5.8–§5.9) |
| متأخر، عطلة | depends on D4, D5 | No shift time and no holiday calendar exist in either system |

---

## 2. Already decided — do not re-open

| # | Decision | Where |
|---|---|---|
| 10, 11 | No legacy identifier in the domain schema; the importer keys on its own staging table | `START_HERE.md` §9 |
| 13 | من لا يرى لا يكتُب — pay-field writes need the read permission | §9 |
| 14, 18, 19 | Manager scope = whole subtree; scope lives on the grant; out-of-scope = 403 | §9, GAP-3 work order |
| 15 | `ManagementChain`: climb past a vacancy or past the requester, end at المدير العام, manual reassignment stays | §9 |
| 17 | An employee sees their own classification axes in full | §9 |
| 21–24 | A scoped grant confers only what enforces its scope; the subset rule | §9 |
| — | Every employee gets an account; login is the employee number; first password is forced to change | `EMPLOYEE_APP_REQUIREMENTS.md` §7.1, built 2026-09-23 |
| — | The leave chain is the direct manager, then مدير الموارد, then done | `EMPLOYEE_APP_REQUIREMENTS.md` §4.2 |
| 25 | This work order's shape (§1) | here, 2026-10-05 |

---

## 3. Decisions needed

Each has a recommended default. ~~**The "blocks" column is the latest date the
answer can arrive without moving the release.** A default not overruled by that
date is built as written.~~

⚙️ **Withdrawn 2026-10-16** (project owner, §8): a default is **not** built
because its date passed. Each decision is put to the project owner and
answered before the piece that rests on it is built. The "blocks" dates are
kept for the record only.

✅ **Answered 2026-10-16** — D2, D3, D4, D5, D6, D7, D9, D12, D13 through
[`DECISION_ROUND_ATTENDANCE_LEAVE.md`](DECISION_ROUND_ATTENDANCE_LEAVE.md); the
answers and what each changes are §8's entry of that date. In short: **D2**
collapse / skip with a reason · **D3** no flag, every active employee with an
account · **D4** no "late" · **D5** no holiday calendar, and no weekly day off ·
**D6** current unit · **D7** selfie files three months, records forever, legacy's
cleanup left alone · **D9** yes · **D12** as planned · **D13** 15 days, per-person
allocations by HR. **Still open:** D8, D10, D11 — before §4.10.

| # | Question | Recommended | Blocks |
|---|---|---|---|
| **D1** | Fresh ERP accounts for everyone, legacy passwords not carried? | **Yes** — legacy has no employee number to match on, and the identifier is new anyway (`EMPLOYEE_APP_REQUIREMENTS.md` §7.1) | Oct 14 (importer) |
| **D2** | When step 1 and step 2 of the leave chain are the same person? | Collapse into one step; record the other `skipped` with a reason | Oct 21 (approvals) |
| **D3** | Who records attendance? | Every active employee with an account, no flag. If HR names exemptions, they become a fact on `employees`, not on the account | Oct 14 (attendance) |
| **D4** | Is there a shift start time, so "late" means something? | If HR gives one time for everyone: store it in config and show متأخر. If not: v1 ships without متأخر | Oct 21 |
| **D5** | A public holiday calendar in v1? | **Yes**, small: a `public_holidays` table HR fills, behind the `isChargeableDay()` hook legacy already has. Otherwise عيد counts as absence and as a leave day | Oct 14 |
| **D6** | Scope on attendance/leave rows: the employee's current unit, or the unit at the time? | **Current unit**, as GAP-3 does for employees. Drop legacy's ownership snapshots | Oct 14 |
| **D7** | How long are selfie files kept? | Keep records forever (payroll input). Selfie files: a retention limit HR chooses (e.g. 6 months), deleted by a command that keeps the row. **And stop legacy's monthly `attendance:cleanup` now** — it deletes the month the reconciliation needs | Oct 26 |
| **D8** | Reuse the legacy Firebase project? | **Yes** (merge plan, open decision 2) | Oct 21 |
| **D9** | `client_uuid` on attendance submissions? | **Yes**; nullable, so legacy-format submissions still land | Oct 14 |
| **D10** | Does v1 show the employee their pay criteria? | **Hide the pay block in v1.** Decision 17 stands, but every axis must be HR-checked per person before 242 people read it, and that review is not done by Nov 1 | Oct 26 |
| **D11** | Separate app id for the ERP build? | **Yes** — a new `applicationId` (and iOS bundle id), so pilot users run both apps side by side. Also retires `com.example.doc_approval`, which no store accepts | Oct 21 |
| **D12** | The pilot group, and how legacy stays complete for pay | Technical department + HR, about 15–25 people. They **check in on both apps** for the pilot month. Leave is filed **only in the ERP**, and HR mirrors each approved one into legacy as an HR excuse (legacy already has the endpoint) | Oct 21 |
| **D13** | Default annual leave allocation | 15 days, as legacy's `config/leave.php` says — confirm against the workbook | Oct 21 |

---

## 4. The build, in order

Each piece leaves the suite green and Pint clean on its own. Tests port with the
code: every legacy feature test named below gets an ERP twin against ERP
employees, departments and RBAC — **a port is not a copy** (merge plan §2).

### 4.1 Batch 3 groundwork — the importer's prerequisites

From [`START_HERE.md` §10](START_HERE.md), unchanged:

- **Termination disables the account** — an `employees.status` change to
  terminated sets `users.is_active = false` in a model hook, on every path. Not
  built today (`Employee.php` never touches it).
- **The importer's staging table** — source row → employee id (decision 11).
- **The workbook column map**, which also answers the three source questions in
  `EMPLOYEE_APP_REQUIREMENTS.md` §7.2 (photo, contract dates, certificates).

### 4.2 The office-tier artefact → HR

The filtered list of the intake form's 114 office rows (~70–80 survive), as a
file HR reviews. **Sent in week 1.** HR's turnaround is the critical path of the
whole month; nothing else in it waits on a person.

### 4.3 Sessions, devices, push — `app/Modules/Auth`, `app/Shared`

Mapping §7 and §8.

- `refresh_tokens` gains `device_name`, `platform`, `last_used_at`.
- `GET /api/auth/sessions`, `DELETE /api/auth/sessions/{id}`,
  `POST /api/auth/logout-all`.
- `device_tokens` + `POST/DELETE /api/me/device-tokens`; revoking a session
  deletes its push token.
- The FCM transport, fed by notification creation. `POST
  /api/auth/notifications/read-all`.
- A `NotificationEventCatalogue` in the `AuditEventCatalogue` pattern, with the
  v1 events from mapping §8.

### 4.4 The employee importer — Batch 3 proper

`START_HERE.md` §10 and `EMPLOYEE_APP_REQUIREMENTS.md` §7.1 C, unchanged:
idempotent and re-runnable on the staging table; reports instead of absorbing
(`#N/A`, blank units, ShamCash not 16 digits, managers the tree disagrees with);
accounts for active employees only; temporary passwords as an artefact HR
distributes and written nowhere else; restores soft-deleted accounts instead of
inserting beside them; a dry-run mode.

### 4.5 Attendance — `app/Modules/Attendance`

Mapping §4. The table on `employee_id`, selfies on a private disk, `client_uuid`
and `source`, `config/attendance.php` ported, `checkout-reminder` and
`auto-close` ported, `cleanup` **not** ported. ⚙️ **2026-10-16:** no
`public_holidays` and no `attendance_check` flag (D5, D3); a new selfie-file
retention command (three months, the record stays — D7); `auto_close_at` 23:59
everywhere.

Self-service: `POST /api/me/attendance`, `POST /api/me/attendance/sync` (legacy
payload shape accepted), `GET /api/me/attendance`. HR/manager: records, daily,
monthly and per-employee reports, absent-today, correct a check-out, reject and
un-reject — all row-scoped per GAP-3.

Tests to port: `AttendanceCheckInOutTest`, `AttendanceCheckoutAuthorizationTest`,
`AttendanceEmployeeBreakdownTest`, `AttendanceFilterAndReportTest`,
`AttendanceMonthlyReportTest`, `AttendanceQueryControlsTest`,
`AttendanceRejectionTest`, `AttendanceScheduleCommandsTest`,
`AttendanceSyncErrorLeakageTest`, `WorkingCalendarTest`. **Not**
`AttendanceExportTest` — XLSX export is a dashboard feature and waits.

### 4.6 Approvals — `app/Modules/Approvals`

Mapping §6. `approvals`, `approval_steps` (sequential and parallel through
`step_order`), `approval_reassignments`; `submit`, `act`, `reassign`,
`pendingFor`; completion hook on the subject; notifications on each transition.
Acting is by being the step's `approver_id`, not by permission. D2 decides the
collapse rule.

Designed against its four known consumers — leave now; documents, purchase
requests and payroll approval later — not against leave alone (merge plan §5).

⚙️ **Two clients act on it** (2026-10-14, §8): a manager approves leave in the
Flutter app **or the dashboard**. Nothing in the engine changes for that —
acting is by being the step's approver, whichever client sends it — but the
API must serve a dashboard approvals inbox, and the dashboard needs that inbox
and a request screen to act from.

### 4.7 Leave — `app/Modules/Leave`, on the engine

Mapping §5. Types (+ `requires_attachment`), balances, requests, HR excuses,
cancel, return-to-work; ⚙️ **2026-10-16:** the nine live types, sick leave
**not** deducting and requiring a medical report, the chain resolved (direct
manager → مدير الموارد) — §8; attachments through a shared polymorphic `attachments`
table on the private disk, which documents reuse in November.

Tests to port: `LeaveTypeTest`, `LeaveBalanceManagementTest`,
`LeaveRequestSubmissionTest`, `LeaveRequestSequentialApprovalTest`,
`LeaveApprovalReassignmentTest`, `LeaveExcuseTest`,
`LeaveExcuseCancellationTest`, `LeaveReturnToWorkTest`, `LeaveRegisterTest`,
`LeaveRequestQueryControlsTest`.

### 4.8 The `/api/me` surface

`EMPLOYEE_APP_REQUIREMENTS.md` §6: `profile`, `summary` (cards degrade to
`null`), `attendance`, `leave-balance`, `leave-requests`, `approvals`,
`device-tokens`. No `permission:` middleware; every query resolves through the
caller's own `employee_id`. The pay block follows D10.

⚠️ **The gate conflict in §6.1 of the requirements is the trap here.**
`EmployeeResource` decides the pay block on `hr.employees.viewSalary` alone. The
self path must show it (when D10 allows) **without** granting that permission —
which would show everyone's. Same class, two callers, decided by *who the row
is about*, with a test that a self-viewer still cannot read a colleague's pay.

### 4.9 Batch 4 — the real import

HR review rounds (correct the source, re-run, the staging table prevents
duplicates), then the import: 242 active + 223 terminated, accounts for the
active, credential list to HR. Stage 1's exit criterion, unchanged.

### 4.10 The Flutter app — `../doc_approval`

Mapping §11, in its order: the single-flight refresh interceptor first, because
without it the app logs everyone out every 15 minutes; then tokens, login and the
forced password change; the envelope; the new app id (D11); remove the approver
picker; leave attachments; `client_uuid`; the employee shape; home and profile;
push registration; hide documents.

⚠️ **Test the refresh race on a device, not only in unit tests:** open the app
after more than 15 minutes idle with an offline queue waiting, so the sync and
the screen load both hit `401` at once. One refresh must happen, and the session
must survive.

### 4.11 The pilot release

Build, distribute to the D12 group, hand HR the credential list, and run the
first-login flow with the pilot in one sitting so nobody is stuck at the
password change.

---

## 5. The calendar

⚙️ **Not binding since 2026-10-16** (project owner: "it was just a try"). The
target is a professional, complete v1, not a date: work goes in dependency
order, and the fallback below — shipping without attendance — is withdrawn.
The table is kept as the original estimate.

| Week | Dates | Backend | App / people |
|---|---|---|---|
| **1** | Oct 5–11 | 4.1 termination hook, staging table, workbook map · 4.3 sessions and devices | **4.2 tree artefact to HR** · D1, D3, D5, D6, D9 answered |
| **2** | Oct 12–18 | 4.4 importer · 4.5 attendance | HR review round 1 · app: refresh interceptor, login, envelope |
| **3** | Oct 19–25 | 4.6 approvals · 4.7 leave · 4.8 `/api/me` | HR review round 2 · app: leave, attachments, app id · D2, D4, D8, D11–D13 answered |
| **4** | Oct 26–Nov 1 | 4.9 real import · fixes | App on devices · **4.11 pilot release** |
| — | November | Documents onto the engine · whole-company parallel run | Export legacy's November figures before Jan 1, when its cleanup deletes them (unless D7 stops the cleanup first) |
| — | ~Dec 1 | Cutover; legacy read-only | Every phone on the ERP build |

**If week 2 slips:** ship v1 with profile, leave and approvals, and add
attendance one week later. Attendance is the riskiest piece and the pilot can
keep using legacy for it.

**Tight, and said plainly:** this is two module ports, a new engine, the importer
and an app migration in four weeks for one developer. The slack is the
fallback above, not overtime.

---

## 6. Exit criteria

**v1 pilot (Nov 1):**

- Every pilot user has logged in on the ERP build and changed their password.
- A pilot user can check in and out online and offline, and the offline queue
  survives a token expiry.
- A leave request goes direct manager → مدير الموارد → approved, with push
  notifications at each step, and the balance moves.
- No pilot user can read anyone else's attendance, leave or pay through `/api/me`.
- The suite is green and Pint is clean.

**Cutover (~Dec 1)** — the Stage 3 criterion, unchanged: a full month of
attendance on the ERP closes cleanly and reconciles against legacy's figure for
the same month. Plus: every active employee has an account they have logged into
(`EMPLOYEE_APP_REQUIREMENTS.md` §7.1 C — a 95 % rollout is a payroll defect for
the other 5 %).

---

## 7. Risks

| Risk | Mitigation |
|---|---|
| HR review takes longer than two rounds | Tree artefact goes out in week 1; the importer reports instead of blocking, so a partial review still moves |
| Concurrent refresh kills sessions (replay detection) | Single-flight interceptor + an on-device test (§4.10) |
| Legacy deletes the month the reconciliation needs | D7: stop `attendance:cleanup` in legacy now — a one-line schedule change, not a schema change, so the Stage 0 freeze allows it |
| Pilot users' pay goes wrong because legacy missed a leave | D12: HR mirrors approved ERP leaves into legacy as excuses for the pilot month |
| The pay block reaches 242 people before HR has checked it | D10: hidden in v1 |
| Selfies leak | Private disk, authorized endpoint (mapping finding 5) |
| Two apps confuse the pilot | Different app name and icon on the ERP build; a one-page note for the pilot group |

---

## 8. What was decided while building

Record here what building forces, as the GAP-3 work order did in its §8.

### 2026-10-10 — the import reads the October files, and §4.2 was already done

**The source is the October workbook, not February's** (project owner,
2026-10-10). Every count in `START_HERE.md` and `phase-1.5-foundation-truth-up.md`
— 242 active, 223 terminated, the 114 office rows — comes from
`Program_HR_2_2026.xlsx`. The import reads the two newest files instead:

| File | Date | Rows |
|---|---|---|
| `Program_HR_10_2026.xlsx`, sheets `قاعدة البيانات` and `إنهاء عمل` | 2026-10-05 | 185 active, 216 leavers |
| `فصل الانتاجية/Program_HR_9_2026-انتاجية.xlsx`, sheet `قاعدة البيانات` | 2026-09-13 | 52 active (47 production, 5 security) |

Both live under `Downloads/Shared/شركة الراقي/ملف ابو جهاد محدث/برنامج الموارد/`
on the developer's machine. **Production was split into its own workbook in
September**, which is why the main file has none of it; 237 active, not 242.

**§4.2 as written was already done.** The office filter ran on 2026-08-05 when
`DepartmentSeeder` was written (50 offices, the same drop rules). What HR got
instead, on 2026-10-10, is
[`hr-review/org-tree-review-2026-10-10.xlsx`](hr-review/org-tree-review-2026-10-10.xlsx):
the seeded tree with active headcount per unit, every unit name in the October
files the tree does not know, the rows the drop rules absorb, and five questions.
Names only as counts — no personal data. Matched against the seeded tree, all 237
active rows land somewhere: 161 on a unit by name, 70 through the drop rules
(equipment classes, workshops), **6 on units the tree lacks**.

What the October files changed, waiting on HR's answers in that file:

- **A fourteenth division**, `إدارة الجودة والسلامة المهنية` (written
  `…والمهنية` in the source), with `قسم الجودة`, `قسم السلامة المهنية` and
  `مكتب مراقبة الجودة`. Not in the seeder.
- **`إدارة الإمداد والتوريد` changed**: `قسم المحاسبة` is new, and the seeder's
  `قسم المشتريات` is `قسم المشتريات والمبيعات` in the source.
- **`مكتب العقود والمعاملات`** under `قسم العلاقات الخارجية` is new.
- **Leaver statuses are not one value.** `إنهاء عمل` 148, `نقل لصلد` 52,
  `نقل لاصل` 15, `إجازة بدون راتب` 1 — the last is likely `Suspended`, not
  `Terminated`. Active statuses (`متعاقد`, `جماعة`, `يحتاج تثبيت`) are already
  `EmploymentType` cases.
- **76 leavers carry no unit at all**, and the schema requires one.

Any tree change HR confirms goes into `DepartmentSeeder` (and its exact-count
test) **before** the importer runs, not into the importer.

### 2026-10-10 — §4.1 built

- **Termination disables the account**, in `Employee`'s `updated` hook through
  `User::disable()`, which also deletes the account's access and refresh
  tokens — `is_active = false` alone leaves the current access token working
  until it expires. It fires on the *change* to terminated only: a rehire does
  not re-enable the account, and a later edit does not overrule an
  administrator who re-enabled it. `Suspended` leaves the account alone.
  `TerminationDisablesAccountTest`, 8 tests.
  ⚙️ An administrator setting `is_active = false` through `UserService` still
  does **not** end open sessions — pre-existing, outside this item.
  ✅ **Closed 2026-10-16 as GAP-20** (`API_BUILD_STANDARD.md` §12), with the
  same gap on an administrator's password reset.
- **The staging table** is `employee_import_sources`: a namespaced
  `source_key` string, unique, → `employee_id`, plus where it was last read.
  `EmployeeImportSource::employeeFor()` finds soft-deleted employees too, so the
  importer restores instead of duplicating; `remember()` refuses to re-point a
  key at a different person. Not unique on `employee_id` — a rehire has two
  keys. `EmployeeImportSourceTest`, 7 tests.
- **The column map** is [`WORKBOOK_COLUMN_MAP.md`](WORKBOOK_COLUMN_MAP.md). It
  answers the three §7.2 source questions (no photo, no contract dates, one
  certificate) and lists seven decisions. **Two block parts of the importer:**
  what `ID_Sarah` is and how leavers are keyed (it is absent from
  `إنهاء عمل`), and the engineering-rank sub-levels `مشارك2`–`4`, which the
  `EngineeringRank` enum does not have.

### 2026-10-10 — §4.3 built, except the FCM class itself

- **Sessions.** `refresh_tokens` gained `session_id`, `device_name`,
  `platform` and `signed_in_at` — not `last_used_at`: Sanctum already stamps
  `last_used_at` on the access token every request, and the access token is now
  one per session (named `session:<id>`). `signed_in_at` is carried on every
  row because the prune command deletes the first one.
- ⚠️ **Two behaviour changes the clients must know** (API_DOCUMENTATION §3):
  `POST /auth/logout` ends **only the caller's session** (everything is
  `POST /auth/logout-all` now), and signing in on a second device no longer
  deletes the first device's access token — before this, a phone and the
  dashboard signed in as one person evicted each other on every refresh.
- **A pre-existing defect, fixed on the way.** A deliberately ended token was
  marked `revoked`, which replay detection reads as theft. So after a password
  change, the other device's next routine refresh revoked **every** session —
  including the new one the changer had just been given. Ended tokens are now
  deleted; `revoked` means only "rotated". `SessionTest` covers both paths.
- **Device tokens** (`device_tokens`, `POST/DELETE /api/me/device-tokens`) —
  the first routes of a new `Me` module, self-scoped. A token belongs to the
  session that registered it and is deleted when that session ends, by any
  path: logout, logout-all, `DELETE /auth/sessions/{id}`, a password change,
  `User::disable()`, `UserService::delete()`, theft detection.
- **`POST /auth/notifications/read-all`**, and `type_label` on notifications
  from **`NotificationEventCatalogue`** (the 11 v1 events of mapping §8).
- **Push.** `NotificationService::deliver()` dispatches `SendPushNotification`
  after commit; the job resolves the recipients' tokens at send time, sends
  through the `PushTransport` interface and deletes tokens the provider reports
  dead.
- **FCM** (2026-10-11): `FcmPushTransport` on `kreait/laravel-firebase` 6.2
  (legacy runs 5.10 of the same library), bound when `FIREBASE_CREDENTIALS`
  is set, `LogPushTransport` otherwise. Retried only when a send reached no
  device and found no dead token — a partial failure is logged, not retried,
  so nobody gets a push twice. Keeps legacy's `click_action:
  FLUTTER_NOTIFICATION_CLICK` so the re-pointed app routes taps unchanged.
  `FcmPushTransportTest`, 7 tests, Firebase mocked at the `Messaging`
  contract — **a real send has not been tested**; that needs the legacy
  project's service-account JSON on the server (D8, still open) and a
  `queue:work` process, which the ERP has never run before.
- ⚙️ Installing the package also moved `laravel/framework` 12.58 → 12.69 and
  ~45 Symfony/Guzzle/Carbon packages to their locked versions; the suite was
  green before writing any code against it.

### 2026-10-14 — managers approve in the dashboard too; a notification opens where you act

**Decided by the project owner:** a manager can approve leave in the dashboard
as well as in the app. §1's v1 table said the app; it now says both.

**What it adds to the plan** — frontend work, not engine work (§4.6):

- A dashboard **approvals inbox** (my pending steps, from `pendingFor`) and a
  **request screen** showing the request, its chain and its current state, with
  approve / reject / note. Built with §4.6–§4.7, in week 3.
- The request screen shows the **live state**, never a cached one: by the time
  a manager opens it the step may be decided, cancelled or reassigned, and the
  screen says so instead of offering a button that fails.

⚠️ **The calendar does not absorb this for free.** Week 3 already holds the
engine, leave and `/api/me`. If it slips, the §5 fallback applies to the
dashboard inbox first: managers approve in the app for the pilot, and the
dashboard inbox follows.

**A notification opens where its recipient acts** (project owner, same day).
The destination is chosen by the notification's **type** first and its
`reference` second — the same leave request opens the approval screen for the
approver (`approval.step_pending`) and the requester's own request for them
(`leave.approved`). The server sends `type` + `reference` and never a URL;
each client maps them to its own screen, per the "tap opens" contract in
API_DOCUMENTATION §6. A type with no destination opens the notification's
detail window. Approve / reject is never a button on the notification itself.

### 2026-10-14 — HR's answers: the import key, leavers, the صرح file, the ranks

Asked and answered the same day. They unblock §4.4.

| # | Question | HR's answer | What it means |
|---|---|---|---|
| 1 | Is `ID_Sarah` the KoboToolbox `_id` of the employee's «استمارة بيانات الموظفين» submission — the dataset exchanged with شركة صرح? | Yes | **The import key for every active employee is `kobo:<_id>`** in `employee_import_sources` |
| 2 | Does editing an employee keep the Kobo record, or create a new one? | The number is never changed | The key survives HR's corrections between runs |
| 3 | How are leavers keyed? | **Do not import leavers at all, for now** | §4.9 imports the 237 active only. Stage 1's "223 terminated" is deferred; `إنهاء عمل` is not read |
| 4 | May the latest صرح file (`مستقبل صرح 9-2026.xlsx`, 296 records) be a source? | Yes | It supplies what the workbook lacks: national ID, personal photo, mother's name, sons and daughters separately, the full birth date — matched on `_id` (232 of 236 active found) |
| 5 | Are مشارك1–4 approved ranks, at 165 / 180 / 195 / 210? | Yes | Added to `EngineeringRank` as 10–13 |
| 6 | Is plain مشارك a rank of its own? | «لا» — then **corrected by the project owner: مشارك is a rank** | Kept (5). The question was either/or, so «لا» never settled it |
| 7 | Is استشاري still a rank? | «لا» — then **corrected by the project owner: استشاري is a rank** | Kept (8) |
| 8 | Is ركن a rank? | No | Retired; any row holding it moves to استشاري. Nobody holds it |

The engineering ladder is therefore **لا يوجد، متدرب، مراقب فني، مساعد مهندس،
مشارك، مشارك1–4، ممارس، رأي، استشاري** — exactly the list «نظام الرواتب»
prices.

⚠️ **Corrected the same day.** The first reading of answers 6 and 7 removed
مشارك and استشاري; the project owner corrected it. A draft migration that
moved مشارك → مشارك1 and استشاري → رأي had already run on the development
database (never committed, never anywhere else); its two seeded engineers
were put back by hand, and the committed migration retires ركن only.

⚠️ **The صرح file also carries religion, sect, criminal record and ID-card
scans.** The importer reads an explicit allow-list of its columns and nothing
else; none of those four is on it.

**Still to report, not to resolve:** 4 active employees whose `ID_Sarah` is
not in the صرح file, and 1 with no `ID_Sarah` at all. The importer lists them
for HR rather than keying them some other way.

### 2026-10-14 — the production division is not imported; the import is 185 people

**Decided by the project owner**, on the evidence of HR's September monthly
report (`تقارير شهرية/تقرير_الموارد_البشرية_9-2026.pptx`, slide 4), which
lists «إدارة المشاريع الإنتاجية» as **«نقل لصلد»** — transferred out to صلد —
and counts **184** current employees.

- **The import reads `Program_HR_10_2026.xlsx` → `قاعدة البيانات` only: 185
  people.** The production workbook (`فصل الانتاجية/…انتاجية.xlsx`, 52 people)
  is not read. The 237 in the §8 entries above is superseded.
- **184 vs 185 is one hire on 2026-10-03**, after the report — the one row with
  no `ID_Sarah` yet.
- The report's per-division counts match the workbook (77 الآليات الثقيلة,
  21 الأمن والحماية, 9 الإمداد والتوريد, …), and it names the quality division
  «منشأ حديثاً» — confirming the fourteenth division the HR review file raised.
- **The rows the صرح file lacks are new hires:** two September hires came after
  the September صرح export, and one is in the new quality division. The
  importer takes the workbook's fields for them and fills the صرح-only ones
  (national ID, mother's name, photo) on a later run — reported, not refused.
- **Reconciliation target for Stage 4:** September's net payroll, $114,208
  (base $113,699), per the same report.
- The folder `Downloads/HR_changed_Oct2026` (HR's latest edits, located by the
  project owner) holds byte-identical copies of every spreadsheet already used
  — so `Program_HR_10_2026` is confirmed as the latest as of 2026-10-05.

`إدارة المشاريع الإنتاجية` stays in `DepartmentSeeder` for now: a tree change
is HR's to confirm (the review file), and an empty division costs nothing.

### 2026-10-14 — four import decisions by the project owner

| # | Question | Answer | Built / where |
|---|---|---|---|
| 1 | 207 national IDs have 10 digits — restore the leading `0` Excel dropped? | Yes | The importer pads them to 11; the shorter ones and the 2 duplicates are reported |
| 2 | A KoboToolbox API token to download the photos in the صرح file? | No | **No photo is imported.** ⚠️ **The app needs a photo upload from day one** — §4.8 gains `POST /api/me/photo` into `employee_documents` (personal photo), and §4.10 a picker on the profile screen; home shows initials until one is uploaded |
| 3 | The opening posting's title: the one they started with, or today's? | The one they started with | ⚙️ **Superseded 2026-10-15 by option B** (the entry below the handoff): one posting with today's title, the starting one kept as text in `legacy_job_title` |
| 4 | `اللقب` and `مكان الراتب` — needed in v1? | Yes | **Built:** `employees.nickname` (identity, ungated) and `employees.payroll_location` (pay-gated, read and write). `EmployeeNicknameAndPayrollLocationTest` |

### 2026-10-15 — HANDOFF: §4.4 importer, core built; reader, command and one decision left

⚙️ **Done the same day** — the reader, the command, option B and the dry run
are in the next entry. This one is kept for the record.

**Read this first in a new session.** State of the tree: branch `phase_1`,
**618 tests passing, Pint clean**, everything below committed —
`3506ea8` adds **`openspout/openspout` ^4.28** (for reading the workbooks and
writing HR's files), `f8e7e14` the importer core. The only untracked path is
`docs/manual-test-scenarios/`, the project owner's own draft — leave it alone.

**Built, all in `app/Modules/HR/Import/`:**

| Piece | What it does | Tests |
|---|---|---|
| `ArabicText` | `key()` folds spelling variants for comparison; `clean()` trims for storage | via the others |
| `UnitResolver` | division/section/office → department by **path**, August drop rules, one-level fallback with a warning, unknown division → no unit + `error` | `Import/UnitResolverTest` (12) |
| `EmployeeRowMapper` → `MappedRow` | one workbook row + its صرح record → `employees` attributes + issues. صرح **allow-list** `SARH_COLUMNS`. Applies the guard's cross-field rules itself (rank on non-engineer, wives when not married, `اتفاق` without amount) so one value never refuses a row. Omits unknown counts (NOT NULL DEFAULT 0) | `Import/EmployeeRowMapperTest` (28) |
| `EmployeeImporter` → `ImportReport` | `run(rows, sarhById, dryRun, sourceFile, sourceSheet)`: key `kobo:<ID_Sarah>`; create / `correct()` / restore; per-row transaction; drops a national ID or Sham Cash someone else holds; job titles deduped by key; opening posting's `legacy_workplace` / `legacy_manager_name`; accounts via `UserService` with a 10-char readable temporary password returned **only** in `credentials()`; dry run = whole run rolled back | `Import/EmployeeImporterTest` (14) |
| `Employee::correct()` | a change to unit/title/grade saved as a **correction** — amends the open posting, no نقل داخلي invented. Used by re-runs | `EmployeeCorrectionTest` (4) |

**Left to build, in order:**

1. **The reader** — openspout, behind a small interface: rows of one sheet as
   *sheet row number → header → value*, header row given (row 3 for
   `قاعدة البيانات`, row 1 for the صرح file), headers trimmed, empty column A
   ignored, dates as `DateTimeImmutable`. Plus `sarhById` keyed by the digits of
   `_id`.
2. **The command** `hr:import-employees {workbook} {--sarh=} {--dry-run}
   {--report=} {--credentials=}`: reads, runs, writes the report (xlsx, one row
   per source row: row, name, outcome, employee number, issues — Arabic, RTL)
   and, only on a real run, the credentials file. **The passwords go to that
   file and nowhere else** — not the report, not stdout, not the log.
3. **A dry run against the real October files**, report reviewed with the
   project owner before any real run. Sources: `Downloads/Shared/شركة الراقي/
   ملف ابو جهاد محدث/برنامج الموارد/…Program_HR_10_2026.xlsx` (sheet
   `قاعدة البيانات`, 185 rows) and `…/استقبال وتعديل شركة صرح/مستقبل صرح
   9-2026.xlsx`.

**One decision open — the starting title (question 3 above).** The workbook
gives the title someone *started* with and the one they hold *now*, but not
when it changed. Options put to the project owner on 2026-10-14, not yet
answered:

- **A.** Two postings: starting title from `hire_date` until the import date,
  current title from the import date (reason تعديل مسمى وظيفي). The boundary
  date is invented and flagged in the report.
- **B. (recommended)** One posting with the current title; the starting title
  kept as text on it, as `legacy_workplace` / `legacy_manager_name` already are
  (a `legacy_job_title` column on `employee_assignments`). No invented date.

Until answered, the importer writes the current title and ignores
`المسمى الوظيفي بداية التعيين`.

**Still owed by HR, not blocking:** what `التمييز بحسب التقييم` holds (not
imported until answered); confirmation of the tree changes in the review file
(quality division, Supply's sections).

### 2026-10-15 — §4.4 reader and command built; option B; the first dry run

**The starting title: option B** (project owner, 2026-10-15). One opening
posting with today's title; `المسمى الوظيفي بداية التعيين` is kept beside it as
text in a new `employee_assignments.legacy_job_title` — import-only, like
`legacy_workplace` and `legacy_manager_name`, returned by the posting resource,
written by no endpoint, not deduped into `job_titles`. No boundary date is
invented.

**Built** (all under `app/Modules/HR/Import/` unless named):

| Piece | What it does | Tests |
|---|---|---|
| `WorkbookReader` → `OpenSpoutWorkbookReader` | One sheet as *sheet row number → header → value*; header row given; trimmed headers; headerless columns skipped; **a formula reads as the value Excel saved** (see below); an Excel error is a `CellError`, not a blank; with a column list, only those columns are read and a missing one fails the read | `Import/OpenSpoutWorkbookReaderTest` (9), on a real xlsx |
| `EmployeeRowMapper::WORKBOOK_COLUMNS` | The 37 workbook headers the mapper reads and nothing else — not the pay values, not the warnings. The reader refuses a sheet missing one, so a column HR renames fails the run rather than importing blank | via the mapper tests |
| `EmployeeRowMapper::sarhById()` | The صرح records keyed by the digits of `_id`, cut to `SARH_COLUMNS` on the way in | `Import/EmployeeRowMapperTest` (+5) |
| `ImportReportWriter` | The report (sheet الملخص + one row per source row in السطور: row, name, outcome, employee number, Kobo id, issues — RTL, filterable) and the credentials file. On a dry run a new employee's number is left blank: the rollback takes it back | via the command test |
| `hr:import-employees` (`app/Console/Commands/ImportEmployees.php`) | `{workbook} --sarh= --dry-run --report= --credentials= --force` (+ `--sheet`, `--sarh-sheet`). Report defaults to `storage/app/private/hr-import/` (git-ignored) | `Import/ImportEmployeesCommandTest` (8) |

**The passwords.** A real run **refuses to start** without `--credentials`, and
refuses a path that already exists (an earlier run's file may hold passwords HR
has not handed out). It asks before writing unless `--force`. The passwords go
to that file only: the command test reads them back and checks they are absent
from the report and from the console output.

⚠️ **Found while building — the workbook's key columns are formulas.**
`السوية التنظيمية`, `نوع النشاط`, `الخبرة` and `الخطورة` are `XLOOKUP`/`IFS`
formulas into another table. openspout returns the formula *text* from
`getValue()`; the reader takes the saved result instead. Read naively, every
row would have imported with no grade, no activity and no hazard pay — and
reported it only as "unknown value". Both October files hold **no** error cells
today.

**The dry run** — `Program_HR_10_2026.xlsx` → `قاعدة البيانات` with `مستقبل صرح
9-2026.xlsx`, against the development database, which it left unchanged.
Counts only; the report itself has names and ID numbers and stays out of git.

| Outcome | Rows |
|---|---|
| would be created | 184 |
| skipped | 1 — the 2026-10-03 hire with no `ID_Sarah` |

| Issue | Rows | Whose to fix |
|---|---|---|
| **error** — division `إدارة الجودة والسلامة والمهنية` not in the tree; imported **with no unit** | 1 | HR's tree confirmation (the review file) → `DepartmentSeeder`, then a re-run corrects it |
| section not in the tree → placed on the division: `قسم المشتريات والمبيعات` ×2, `قسم المحاسبة` ×1 (Supply), `القسم التقني` ×1 | 4 | same |
| office not in the tree → placed on the section: `مكتب العقود والمعاملات`, `قسم الصيانة` under `رحبة الصيانة` | 2 | same |
| governorate not recognised (a town or a foreign address) — the address is kept as written | 16 | HR, or leave |
| national ID not 11 digits after the leading-0 rule — not recorded | 12 | HR |
| a wives count on someone not married — dropped | 7 | HR |
| birth date (صرح) disagrees with the workbook's birth year | 6 | HR |
| **the same national ID on two rows** / **the same Sham Cash on two rows** — the second row's is dropped; the message names the first row | 2 / 2 | HR |
| not in the صرح file (new hires) — no national ID, mother's name or children yet | 3 | the next صرح export |
| name not three words — split, please check | 4 | HR |
| contact column holds something that is not a phone number | 2 | HR |
| *for the record:* an equipment class in `المكتب` → `قسم السائقين` (the August drop rule) | 51 | nobody |

**Before the real run:** the project owner reviews the report
(`storage/app/private/hr-import/employee-import-20261015-dry-run.xlsx` on the
developer's machine); HR confirms the tree changes, which go into
`DepartmentSeeder` and its exact-count test first; HR fixes what it chooses to
in the workbook; then a fresh dry run, then the real one with `--credentials`
pointing outside the repository.

⚙️ The development database has `legacy_job_title` (migrated 2026-10-15).

### 2026-10-16 — quality over the date; legacy is the foundation

**Decided by the project owner:**

1. **The calendar is not binding** (§5), and **no decision is defaulted by
   date** (§3). Each open decision is asked and answered before the piece
   resting on it is built. Order is by dependency and risk.
2. **Attendance, leave and approvals are built on the legacy system's logic
   and workflows** — the same core behaviour, ported onto ERP employees,
   departments and RBAC, then improved and extended. This is what §4.5–§4.7
   already said ("a port is not a copy"); it is now the explicit rule: **read
   the legacy implementation first**, keep its behaviour unless a change is
   agreed, and record every deliberate difference here.

**The order from here:**

1. ✅ **GAP-20** — an administrator's disable or password reset now ends every
   session (`API_BUILD_STANDARD.md` §12). Closed 2026-10-16.
2. **A decision round** on what attendance, leave and approvals rest on —
   D2–D7, D9, D12, D13 — asked with the legacy behaviour in front of the
   project owner: [`DECISION_ROUND_ATTENDANCE_LEAVE.md`](DECISION_ROUND_ATTENDANCE_LEAVE.md),
   14 questions, opened 2026-10-16. D7 (stop legacy's monthly `attendance:cleanup`) first, because
   every month it runs deletes history the cutover reconciliation needs.
3. **Working calendar and attendance (§4.5)** — the holiday calendar first,
   because attendance and leave both count working days through it.
4. **The approvals engine (§4.6)** — a written design reviewed before code: it
   serves leave now and documents, purchases and payroll later.
5. **Leave (§4.7)**, then **`/api/me` (§4.8)**.
6. **The real import (§4.9)** whenever HR's review rounds finish; nothing above
   waits on it.

### 2026-10-16 — no data moves from legacy

**Decided by the project owner.** No legacy record is imported — not leave
history, balances, attendance, approvals, notifications or the audit trail.
Legacy is the foundation for how the modules **work** (the entry above), not a
source of data. The ERP's people come from HR's workbook (§4.4); everything else
starts empty.

What it changes:

- `legacy_user_links` (mapping §2) is not built, and mapping §12's table of
  what moves is superseded.
- Leave balances at the switch, and leave already approved in legacy for later
  dates, become questions for the project owner — Q6 of
  [`DECISION_ROUND_ATTENDANCE_LEAVE.md`](DECISION_ROUND_ATTENDANCE_LEAVE.md).
  Q4 (allocations) and Q11 (legacy's cleanup) were reworded to match; the
  question about legacy requests with no leave type is gone.
- The merge plan's open question on the legacy audit trail is answered: not
  migrated.
- The parallel run's comparison of one month in both systems stays a check, not
  an import.

### 2026-10-16 — the decision round, answered

All 14 questions of
[`DECISION_ROUND_ATTENDANCE_LEAVE.md`](DECISION_ROUND_ATTENDANCE_LEAVE.md) were
answered by the project owner; that file is closed. **Legacy stays the reference
for every rule not named below.** No workflow change or default beyond these is
introduced without agreement.

**The answers, and what each means for the build:**

| Q | Answer | Built as |
|---|---|---|
| 1 | **B** | Approvers are **resolved**, never chosen: step 1 the direct manager (`ManagementChain`), step 2 the manager of the unit coded `HR`. The app's approver picker goes; `approver_ids` / `manager_id` are not accepted |
| 2 | **A / A** (D2) | A step resolving to the same person as the step before it is recorded `skipped` with its reason; a step resolving to the **requester** is `skipped` with its reason. So مدير الموارد's own leave is approved by المدير العام alone. Checked at submission, in the engine, for every consumer |
| 3 | nine types; sick **not** deducting; medical report required | Seed the live nine (`attendent_execused` → `attendance_excused`, Arabic name kept), each with its live flags **except** `sick.deducts_balance = false`. `requires_attachment` true for `sick` only |
| 4 | **A** (D13) | `config('leave.annual_days') = 15`; a `leave_balances` row per person and year carries HR's own allocation and adjustment |
| 5 | keep | HR excuses as legacy: approved at once, no approval, cancellable when filed in error, only for `for_excuses` types |
| 6 | **B / B** | No import. HR enters an **opening adjustment** per person; future leave is **re-filed** and approved again — see the procedure below |
| 7 | **B** (D3) | No eligibility flag. Every active employee with an active account may record attendance and is expected to |
| 8 | no weekly day off; **no** holiday calendar (D5) | `working_days` all seven, as legacy. No `public_holidays` table |
| 9 | **A** (D4) | No "late". The check-in window stays open, as legacy |
| 10 | keep all four | Reminder 17:00; auto-close **23:59** as both the config value **and** the code fallback (legacy's code fell back to 22:00); check-out refused within 60 minutes; no check-in during approved leave |
| 11 | files 3 months; legacy untouched (D7) | Records are never deleted. Selfie **files** on a private disk, served only through an authorised endpoint, deleted after three months by a scheduled command that keeps the row and records that the file was purged. Legacy's `attendance:cleanup` is not touched |
| 12 | approved (D9) | `client_uuid`, nullable, `unique(employee_id, client_uuid)`; a repeat returns the saved record with success; a submission without one behaves as legacy |
| 13 | **A** (D6) | Attendance and leave rows carry no unit snapshot; visibility follows the employee's current unit through GAP-3 scope |
| 14 | as planned (D12) | Pilot: attendance in both apps, leave only in the ERP, HR mirrors approved ERP leave into legacy as excuses |

**Where the ERP now deliberately differs from legacy** — each one agreed, each
one a test that names it:

1. Approvers resolved, not chosen (Q1), with the collapse and self-skip rules (Q2).
2. Sick leave does not deduct, and needs a medical report (Q3).
3. `attendent_execused` is `attendance_excused` (Q3).
4. No `attendance_check` flag (Q7).
5. Records kept forever; selfie files private and kept three months (Q11).
6. A retried offline submission succeeds instead of erroring (Q12).
7. No unit snapshot on rows; scope follows the current unit (Q13).
8. One closing time, 23:59, with no 22:00 fallback (Q10).
9. No data imported (the entry above).

**Consequences accepted with these answers** — written down so they are not
rediscovered as bugs:

- **An Eid, or any holiday, is an ordinary working day.** No check-in that day
  counts as absent, and a leave spanning it consumes a day of balance — exactly
  as legacy does today. `EMPLOYEE_APP_REQUIREMENTS.md` §5.5 notes payroll will
  read absences, so the holiday calendar is **deferred, not dropped**: it comes
  back as a change request before Stage 4 payroll, behind the
  `isChargeableDay()` hook legacy already provides.
- **The app's day states in v1** are حاضر / غياب / إجازة, plus missing
  check-out. متأخر and عطلة from the spec (requirements §1) are not shown.
- **Every leave request reaches مدير الموارد** — about 60 a month at legacy's
  rate.

**The transition procedure (Q6, Q14 — implementation clarifications E2, E6).**
The risk is one leave counted twice: once in the opening balance HR enters, and
again when the same leave is re-filed. The rules:

1. **Each employee has a switch date**: the day they start filing leave in the
   ERP — the pilot's start for pilot users, the company cutover for everyone
   else. Nobody has two.
2. **The opening adjustment is taken from leave already TAKEN**, not from
   legacy's remaining balance: *allocation − days of approved leave dated before
   the switch date*. Legacy-approved leave dated **on or after** the switch date
   is **not** subtracted — it is re-filed in the ERP and deducted there. (Read
   from legacy: remaining balance **plus** the days of its approved leave from
   the switch date on.)
3. **Pending legacy requests** are decided or withdrawn in legacy before the
   switch date, then re-filed in the ERP if still wanted.
4. **For pilot users only, while legacy is still the record of pay:** when a
   re-filed leave is approved in the ERP and HR mirrors it into legacy as an
   excuse (Q14), HR first **cancels the legacy original** — otherwise legacy
   counts it twice.
5. **The cutover date itself** is an operational decision still owed by the
   project owner; nothing in the build waits on it.

**Next, in dependency order** (the 2026-10-16 entry above, adjusted): the
attendance module (§4.5) — there is no working calendar to build first, since
D5 is no — then the approvals engine (§4.6, written design reviewed before
code), then leave (§4.7), then `/api/me` (§4.8).

### 2026-10-16 — §4.5 attendance, slice 1 built: recording

The design is [`ATTENDANCE_BUILD.md`](ATTENDANCE_BUILD.md): three slices, each
green on its own. **Slice 1 — recording — is built**; slices 2 (oversight) and
3 (schedules) are next.

**Built**, in `app/Modules/Attendance/` with the routes in `Me/routes.php`:

| Piece | What | Tests |
|---|---|---|
| `attendance_records` | The event log, on `employee_id` (restrict), no unit snapshot (D6), `client_uuid` unique per employee (D9), `source`, `selfie_purged_at` (D7), soft deletes, legacy's indexes | — |
| `config/attendance.php` + `Support\AttendanceCalendar` | Legacy's keys and values; the fallbacks equal the shipped values, so 23:59 everywhere (Q10); `isChargeableDay()` kept as the hook a holiday calendar would use | `Attendance/AttendanceCalendarTest` (10) |
| `Services\AttendanceService` | Legacy's check-in / check-out rules and messages under a lock on the **employee** row; the retried `client_uuid` returns the saved row (Q12); the selfie stored privately only after the rules pass (Q11) | `Attendance/AttendanceCheckInOutTest` (28) |
| `Services\AttendanceSyncService` | The offline queue, entry by entry; only an `AttendanceException`'s message is echoed, everything else is logged and reported generically | `Attendance/AttendanceSyncTest` (12) |
| `Services\AttendanceRecordQuery` | The employee's own list with legacy's filters and its "today by default" | `Attendance/MyAttendanceRecordsTest` (8) |
| `Contracts\ApprovedLeave` → `NoApprovedLeave` | Attendance asks about leave through a contract it owns; the Leave module will bind its own, so the dependency runs Leave → Attendance only | via the check-in tests, with a fake |
| `attendance.records.view` | Seeded now because the audit trail needs a gate for the new type; **company-only** until slice 2 enforces its row scope (GAP-15) | `PermissionSeederTest`, `PermissionReachTest` |

**703 tests passing, Pint clean.** The idempotent retry and the leave guard
were each verified to fail with their code disabled.

**Found while porting:**

- **Carbon 3's time difference is signed**; legacy (Carbon 2) relied on it being
  absolute. Ported naively, every check-out would have carried negative hours.
  The order is now explicit, and the hours tests pin it.
- **Legacy's code fallbacks disagreed with its config** (22:00 vs 23:59 for the
  close, 06:00–17:00 vs open for the window). The ERP's fallbacks are the
  shipped values.
- **`START_HERE.md` §7 and `API_DOCUMENTATION.md` §10 had stale endpoint counts**
  since §4.3; both now say 50 routes, checked against `route:list`.

**For the project owner — read from the decisions, not decided by them:**

1. **A suspended employee cannot record attendance** (422) but can read their
   history. Q7 says "all active employees"; a suspension is not active.
2. **A `recorded_at` in the future is accepted**, as in legacy — a phone with a
   wrong clock can check in for tomorrow. Kept unchanged, because no change was
   agreed. A one-line guard if you want it.

Both are listed in [`ATTENDANCE_BUILD.md`](ATTENDANCE_BUILD.md) §4.

### 2026-10-16 — §4.5 attendance, slice 2a built: oversight of records

Committed before it: the decision round (`39b323d`) and slice 1 (`eb8ac35`).

**Built** ([`ATTENDANCE_BUILD.md`](ATTENDANCE_BUILD.md) §5):

| Piece | What | Tests |
|---|---|---|
| `GET /api/attendance/records` | Legacy's list and filters, plus who (employees, units **with their subtree**, a name or staff number), the refusal reason and the receipt window; row-scoped by the employee's current unit (D6); the person and the acting accounts embedded (C31), no per-row queries | `Attendance/AttendanceRecordsOversightTest` (15) |
| `PATCH …/{record}/checkout` | The forgotten check-out: the check-in untouched, a corrected check-out with hours, the missing flag cleared, no gap guard | `Attendance/AttendanceCheckoutCorrectionTest` (10) |
| `PATCH` / `DELETE …/{record}/reject` | Legacy's refusal and undo: one batch per decision, the day's check-out refused with its check-in, the undo restoring exactly that decision and refusing to collide with a re-recorded day; the employee notified of a refusal in the same transaction | `Attendance/AttendanceRejectionTest` (17) |
| the selfie endpoints | `/api/me/attendance/{record}/selfie` (own only, a colleague's id is 404) and `/api/attendance/records/{record}/selfie` (in reach) — streamed, `private, no-store` | `Attendance/AttendanceSelfieTest` (6) |
| `AttendanceRecordPolicy` + permissions | `attendance.records.view` / `.correct` / `.reject`, each its own grant, all three now row-scoped | `PermissionSeederTest`, `PermissionReachTest` |

**751 tests passing, Pint clean, 56 routes.** The list's scope filter and the
policy were each verified to fail their tests when disabled.

**Kept from legacy, deliberately:** the list defaults to today; refusing a
check-in takes the day's check-out with it; undoing a refusal sends nothing.
**Different from legacy, by the house standard:** 15 rows a page, not 25; the
`working_day` flag beside the list is gone (every day is a working day, Q8);
`type` / `reason` are ints. **Hardened, behaviour otherwise unchanged:**
correcting a check-out now takes the employee lock, as recording does, and a
refused check-in "needs no correction".

**One question for the project owner:** undoing a refusal notifies nobody, as
legacy — though the 2026-10-05 mapping planned a notification for it
([`ATTENDANCE_BUILD.md`](ATTENDANCE_BUILD.md) §4).

**Next: slice 2b** — the daily, monthly and per-employee reports and
absent-today.

### 2026-10-16 — §4.5 attendance, slice 2b built: the reports

Committed before it: slice 2a (`ae54d76`).

**Built** ([`ATTENDANCE_BUILD.md`](ATTENDANCE_BUILD.md) §5;
[`API_DOCUMENTATION.md`](API_DOCUMENTATION.md) §14):

| Piece | What | Tests |
|---|---|---|
| `GET /api/attendance/report` | Legacy's daily board: seven disjoint sections, a refused day listed only when not put right and not covered by leave, the check-out of the same refusal paired by batch | `Attendance/AttendanceDailyReportTest` (12) |
| `GET /api/attendance/report/monthly` | One row per expected employee, legacy's counts and totals; leave clipped to the window and counted in working days, a worked day never also a leave day; the row filters recompute the totals | `Attendance/AttendanceMonthlyReportTest` (13) |
| `GET /api/attendance/report/employee` | One state per day by legacy's precedence, with the check-in id, the correction, the refusal and the excuse; out of reach is 403 | `Attendance/AttendanceEmployeeGridTest` (7) |
| `GET /api/attendance/absent-today` | Expected, not checked in, not on leave; paginated | `Attendance/AttendanceAbsentTodayTest` (7) |
| `AttendancePopulation` | Who a report is about: everyone in reach, or those expected to attend (Q7 — active, with an active account), with the person filters the records list now shares | via all of the above |
| `ApprovedLeave::spans()` + `LeaveSpan` | The leave the reports read, through attendance's own contract; the slice-1 check-in guard's fake now uses the same shared fake (`FakesApprovedLeave`) | via the reports |

**790 tests passing, Pint clean, 60 routes.** The report scope, absent-today's
leave exclusion, and the "a refused day already explained is not a refusal"
rule were each verified to fail their tests when disabled.

**Who has a monthly row (Q7, as built):** active employees with an active
account. Legacy used its `attendance_check` flag; with the flag gone, the
account is what makes someone expected. A new employee hired mid-range is
expected for the whole range, as in legacy.

**Different from legacy, by the house standard:** day states are ints with
labels, and `checked_out` / `present` (one state, two legacy names) are one;
times are ISO 8601 with the offset; no selfie URLs — `*_has_selfie` and the
record ids for §13.2. **The three reports are not paginated** — they are
computed objects whose totals cover every row, as legacy's — which C27 would
otherwise require; recorded in API_DOCUMENTATION §14.

**Not ported:** the XLSX exports (a dashboard feature, later, as §4.5 said);
legacy's `/approved-leave-users`, which belongs to the Leave module.

**Next: slice 3** — the scheduled commands.

### 2026-10-16 — §4.5 attendance, slice 3 built: the schedules — §4.5 complete

Committed before it: slice 2b (`f813459`).

| Piece | What | Tests |
|---|---|---|
| `attendance:checkout-reminder` | Legacy's: 17:00 on working days, everyone still checked in (a refused check-in does not count), active accounts only; `--date` | `Attendance/AttendanceScheduleCommandsTest` |
| `attendance:auto-close` | Legacy's: 23:59 (Q10) on working days, open check-ins flagged missing, nothing invented, refused days untouched, idempotent; `--date` | same |
| `attendance:purge-selfies` | New (Q11): files older than three months deleted, `selfie_purged_at` set, the record kept | same |
| `NotificationService::sendToUsers()` | A fourth fan-out entry point: one bulk insert and one push job for an audience a module resolved | via the reminder |

11 tests; **801 passing, Pint clean.** The day close's refused-day exclusion
and the purge's start-of-day cutoff were each verified to fail their tests
when disabled. Legacy's `attendance:cleanup` is not ported, and nothing in the
ERP's schedule deletes an attendance record.

⚠️ **For the server, before the pilot:** the ERP now needs the scheduler —
`php artisan schedule:run` every minute (cron) — as well as the `queue:work`
process §4.3 already needed for push. Neither has run on the ERP server
before. Without the scheduler there is no reminder, no day close and no
purge, and nothing reports that they are missing.

**One question for the project owner** (with slice 2a's): closing the day
notifies nobody, as legacy, though the mapping planned it
([`ATTENDANCE_BUILD.md`](ATTENDANCE_BUILD.md) §4).

**§4.5 attendance is complete.** Next in dependency order: **§4.6, the
approvals engine** — a written design, reviewed before any code.

### 2026-10-16 — §4.6 approvals engine: the design, for review

Committed before it: attendance slice 3 (`8dec138`) — §4.5 is complete.

[`APPROVALS_ENGINE_DESIGN.md`](APPROVALS_ENGINE_DESIGN.md) is written and
waits for review; **no engine code until it is agreed.** It sets legacy's two
approval implementations side by side (leave: sequential, a rejection ends it;
documents: sequential or parallel, a manager's rejection advisory, the chief
decisive), and designs one engine that runs both — a `rejection_policy` per
approval, `step_order` for sequence and parallel, approvers resolved at
submission with Q2's skip rules, a final-approval guard and a completion hook
on the subject, legacy's reassignment, and the inbox and live request screen
both clients use.

**Seven questions for the project owner** are in its §9 — withdrawing a pending
request (legacy has none), a step that resolves to nobody, a chart change while
pending, who reassigns, who reads a chain, a note on a decision, and who a step
may be reassigned to.

### 2026-10-16 — §4.6 approvals engine: the seven questions, answered

The project owner answered all seven questions of
[`APPROVALS_ENGINE_DESIGN.md` §9](APPROVALS_ENGINE_DESIGN.md), each with the
recommended option; the design is **agreed** and amended where an answer
settled it.

| Q | Answer |
|---|---|
| 1 | **B** — the requester may **withdraw** a pending request while no step has been decided; it becomes cancelled |
| 2 | **A** — a step that resolves to nobody **refuses the submission**, naming the missing post |
| 3 | **A** — approvers are **frozen at submission**; a chart change is handled by reassignment |
| 4 | **A** — reassigning is the row-scoped **`approvals.reassign`**, granted to HR company-wide at first |
| 5 | **A** — besides the requester and the chain, holders of row-scoped **`approvals.view`** read a chain |
| 6 | **A** — a **note is optional** on approve and on reject, for every consumer |
| 7 | **A** — a step may be reassigned to **any active employee with an active account**, not the requester, not already on the chain |
| 8 | **A** — *asked while building:* a step resolving to a person **with no active account** refuses the submission, as Q2 |

**Where the engine deliberately differs from legacy** — added to the list in
"the decision round, answered":

10. A pending request can be withdrawn before any decision (Q1).
11. Reassignment is a scoped grant, not admin-only (Q4), and its target is any
    active account, not a manager or chief role (Q7).
12. Leave decisions may carry a note (Q6).

**Next:** engine step 1 of the design's §8 — tables, enums, models, `submit` /
`act` / `withdraw` / `reassign`, `pendingFor`, the resolvers and the skip rules,
tested against a fake subject.

### 2026-10-16 — §4.6 approvals engine, step 1 built: the engine

Not committed; on top of `8dec138`.

**Built** in `app/Modules/Approvals/` ([`APPROVALS_ENGINE_DESIGN.md`](APPROVALS_ENGINE_DESIGN.md)
§10), against a fake subject, no routes yet: the three tables, the enums and
models, `ApprovalService` (`submit`, `act`, `withdraw`, `reassign`),
`ApproverResolver`, `ApprovalInbox`. Legacy's leave rules (sequential, a
rejection ends it, the final-approval guard, reassignment and its log) and
document rules (parallel managers, an advisory rejection, a decisive chief)
are each a test, as are the eight answers above. `approvals.view` is seeded,
company-only until step 2; `approval.step_assigned` joins the notification
catalogue.

**52 engine tests; 853 passing, Pint clean, 60 routes (none added).** Seven
rules — the same-approver skip, the final-approval guard, Q8's account check,
withdrawal after a decision, the `ends` policy, Q7's eligible assignee and the
inbox's current-order filter — were each verified to fail their tests when
disabled.

**Settled while building** (design §10, none a workflow change): the
completion hook runs inside the engine's transaction, so the subject's status
and the approval commit together; the approver's notification text comes from
the subject per moment; "the step before it" looks past a stage skipped
entirely; an advisory route ends with exactly one decisive step.

**Open for November, with Documents:** what happens when an advisory route's
decisive step is skipped (the chief filing a document themselves).

**Next: step 2** — the endpoints of the design's §7, then leave (§4.7).

### 2026-10-18 — §4.6 approvals engine, step 2 built: the endpoints

Committed before it: engine step 1 (`dddf970`). This step is not committed.

**Built** ([`APPROVALS_ENGINE_DESIGN.md`](APPROVALS_ENGINE_DESIGN.md) §10, step 2;
[`API_DOCUMENTATION.md`](API_DOCUMENTATION.md) §15): the approver's inbox
`GET /api/me/approvals` (`?status=decided` for what they decided), the live
request screen `GET /api/approvals/{approval}`, approve and reject with an
optional note, reassign, and withdraw. `ApprovalPolicy` scopes them: the
requester, anyone holding a step, or `approvals.view` within reach of the
requester's current unit; reassigning is `approvals.reassign`, seeded now. Both
are row-scoped in `PermissionReach`.

**Decided by the project owner:** an inbox row carries the request's own
summary («إجازة سنوية» · «3 أيام · …»), as legacy's queue did — `Approvable`
gains `approvalSummary()`, eager-loaded per subject type, so a page of the
inbox costs the same queries at any length.

**41 endpoint tests; 894 passing, Pint clean, 66 routes (six added).** Each
route guard and rule — the scoped step binding, `can:view` on every verb,
`permission:` and reach on reassign, who is a party, the summaries' eager
load, the four `can_*` flags, the departed-target and status validation —
was verified to fail its tests when disabled.

**For the dashboard's reassign screen:** its employee picker reads
`GET /api/hr/employees`, so HR needs `hr.employees.view` beside
`approvals.reassign`.

**Next:** §4.7, leave on the engine — the design's step 3.
