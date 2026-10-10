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
| الطلبات — as approver | My pending approvals; approve / reject with a note |
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

Each has a recommended default. **The "blocks" column is the latest date the
answer can arrive without moving the release.** A default not overruled by that
date is built as written.

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
`auto-close` ported, `cleanup` **not** ported. `public_holidays` if D5 holds.

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

### 4.7 Leave — `app/Modules/Leave`, on the engine

Mapping §5. Types (+ `requires_attachment`), balances, requests, HR excuses,
cancel, return-to-work; attachments through a shared polymorphic `attachments`
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
