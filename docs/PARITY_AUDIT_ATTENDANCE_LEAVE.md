# Parity audit — attendance and leave, legacy (Raqi-App) vs the ERP

> **Written 2026-10-20** by a functional-parity audit, at the project owner's
> request. **Not committed**: the owner decides. Read-only: neither codebase
> was changed.
>
> | | Revision audited |
> |---|---|
> | Legacy backend `../Raqi-App` | `b4f2c10` (feat: publish the company-wide leave register) |
> | Legacy dashboard `../dashboard` | working tree, reference only |
> | ERP backend (this repo) | `phase_1` @ `5e302f5` (§4.5–§4.8 committed) |
>
> **Raqi-App is the LEGACY system.** "Same" below means the same rule, run on
> the same records, gives the same number or outcome — checked in the code on
> both sides, not inferred from a feature's name.

---

## 0. The owner's answers (2026-10-20) — read this first

The project owner answered every item below the same day; the work order §8
entry "2026-10-20 — the parity audit, and the owner's answers" records them
in full. What changed, built and tested but **not committed**:

| Item | Answer | Status |
|---|---|---|
| C-1 المدير العام cannot file leave | Skip step 1; مدير الموارد decides | **Built** — `SkipReason::TopOfTree` |
| C-2 no seeded grants | The owner solves it; a section manager sees only their unit | Open (the owner's) |
| C-3 inactive employees | No action by or on a suspended/terminated employee; a suspended one cannot sign in | **Built** |
| C-4 inbox filters, HR's list | Implement both | **Built** — filters on `/api/me/approvals`; new `GET /api/approvals` |
| C-5 withdrawal is silent | Keep; the approver sees it was withdrawn | **Built** — `cancelled_at` / `cancelled_by` stamped |
| C-6 `type` as an int | Explained; kept | Docs corrected |
| C-7 the register's invalid month | Keep the ERP's 422 | — |
| C-8 departed employees | Not counted; grid not reachable | **Built** |
| C-9, C-10 | Agreed / noted | — (check legacy's `ANNUAL_LEAVE_DAYS` before cutover) |
| X2 on approved leave on a date | Provide it | **Built** — `GET /api/leave/on-leave` |
| S-1 … S-6 | As recommended in §6 | **Built** — see the work order entry |

The tables below are the audit **as it was found**, before these answers.

---

## 1. Executive summary

**Verdict: CONDITIONAL sign-off.** The ERP carries over legacy's attendance and
leave **calculations** faithfully. On the same records it gives the same
day states, the same daily, monthly and per-employee counts, the same work
hours, the same leave balances, the same register sums, and the same
notification wording. Every difference in the numbers is one the owner already
agreed (sick leave does not deduct; who counts as "expected" under Q7; one
closing time of 23:59). **Two undocumented differences change what people can
do**, and they should be decided before sign-off:

1. **المدير العام cannot file leave at all in the ERP** (C-1). The resolved
   chain finds no direct manager above the root, so the filing is refused.
   Legacy let the chief file and pick an approver. The decision round settled
   مدير الموارد's own leave (Q2) but not the general manager's.
2. **No seeded role reproduces legacy's default powers** (C-2). In legacy every
   manager and chief could, for their own department, read the attendance
   reports, correct a forgotten check-out, file and undo excuses, record a
   return to work and read balances. HR could do all of that company-wide,
   plus refuse records, read the register and manage balances. In the ERP each
   of these is a separate row-scoped grant, and **no role holds any of them by
   default**. So on day one a manager is refused (403) for things they do in
   legacy today, unless HR hands out the grants first. A section manager's
   reach also changes: legacy gave them the whole department, the ERP gives
   their own subtree.

Everything else is either the same, an agreed decision, a legacy bug the ERP
fixed, or a low-risk difference listed in §5.

**The three observations from the dashboard catch-up, re-checked against
legacy** (§3): all three are **legacy behaviour, carried over unchanged**. They
are not regressions:

- the monthly report and the employee grid count **future working days as
  absent** (legacy does exactly the same; legacy's dashboard hid it by opening
  on *last* month);
- **undoing a refusal and closing the day notify nobody** (legacy does the same;
  still an open owner question since 2026-10-16);
- **a refused day counts as absent and can be excused** (legacy does the same).

**Counts** (the numbered rows of §4): **103 rules compared**. **67 are the
same**; three of those (A11, B8, C6) are open owner questions **(a?)**. **19
are deliberate (a)**, **3 are legacy bugs the ERP fixed (b)**, **12 are
undocumented (c)** (ten distinct differences, C-1…C-10 in §5) and **2 are not
ported (d)**. On top of these, §6 lists **six legacy defects the ERP
inherited unchanged**. They are the same in both systems, but each gives a wrong or
confusing number, so the owner should know about them.

**Tests.** ERP: `php artisan test tests/Feature/Attendance tests/Feature/Leave
tests/Feature/Approvals tests/Feature/Me` gives **434 passed (1,873
assertions)**, run 2026-10-20. Legacy: **not run**. Its suite needs a MySQL
`raqi_app_test` database (`phpunit.xml:27-28`) that is not available here, so
every legacy claim below rests on reading the code, with line references.

---

## 2. How to read this

**Classes**

| Class | Meaning |
|---|---|
| **=** | Same rule, same outcome |
| **(a)** | Deliberate, recorded: the work order §8 decision log, or a design the owner agreed there (`LEAVE_BUILD.md`, `APPROVALS_ENGINE_DESIGN.md`) — cited |
| **(a?)** | Recorded and raised to the owner, **answer still outstanding** |
| **(b)** | A legacy bug the ERP fixed |
| **(c)** | **Undocumented difference** — matters for sign-off (§5) |
| **(d)** | Not ported, or out of scope |
| **⚠** | Same input → a **different number or outcome** |

**Abbreviations** — legacy paths are under `../Raqi-App/`, ERP paths are this repo.

| Legacy | File | ERP | File |
|---|---|---|---|
| L-AS | `app/Services/AttendanceService.php` | E-AS | `app/Modules/Attendance/Services/AttendanceService.php` |
| L-AC | `app/Http/Controllers/AttendanceController.php` | E-AOS | `app/Modules/Attendance/Services/AttendanceOversightService.php` |
| L-LRC | `app/Http/Controllers/LeaveRequestController.php` | E-ARS | `app/Modules/Attendance/Services/AttendanceReportService.php` |
| L-LBC | `app/Http/Controllers/LeaveBalanceController.php` | E-ASS | `app/Modules/Attendance/Services/AttendanceScheduleService.php` |
| L-U | `app/Models/User.php` | E-CAL | `app/Modules/Attendance/Support/AttendanceCalendar.php` |
| L-K | `app/Console/Kernel.php` | E-POP | `app/Modules/Attendance/Services/AttendancePopulation.php` |
| L-RC | `app/Authorization/RoleCatalog.php` | E-APS | `app/Modules/Approvals/Services/ApprovalService.php` |
| L-SLR | `app/Http/Requests/StoreLeaveRequest.php` | E-RES | `app/Modules/Approvals/Services/ApproverResolver.php` |
| | | E-LRS | `app/Modules/Leave/Services/LeaveRequestService.php` |
| | | E-LB | `app/Modules/Leave/Services/LeaveBalances.php` |
| | | E-LES | `app/Modules/Leave/Services/LeaveExcuseService.php` |
| | | E-RTW | `app/Modules/Leave/Services/ReturnToWorkService.php` |
| | | E-LR | `app/Modules/Leave/Models/LeaveRequest.php` |
| | | WO | `docs/EMPLOYEE_APP_V1_WORK_ORDER.md` |

---

## 3. The dashboard catch-up's observations, re-checked against legacy

| Observation (dashboard catch-up, 2026-10-19) | Legacy | ERP | Finding |
|---|---|---|---|
| The grid and the monthly report count **future working days as absent** | Grid: no date check before `absent` (L-AS:1011-1013); monthly: `expected = workingDaysBetween(from, to)` (L-AS:770), `absent = expected − present − leave − excused` (L-AS:818); neither the form request nor the controller clips `to` at today (`MonthlyAttendanceReportRequest`, L-AC:333-384) | Same: E-ARS:406-408 (grid), E-ARS:188 and 219 (monthly). Only `/api/me/summary` leaves *today* out (`app/Modules/Me/Services/MySummary.php:96-123`) | **= legacy.** Legacy's dashboard opened the monthly report on **last month** (`../dashboard/src/pages/AttendanceMonthlyReportPage.jsx:28-33`), so nobody saw it. The ERP dashboard opens on the current month and shows it. A display default, not an API regression → open question Q3 |
| **Undoing a refusal notifies nobody** | Deliberately silent (L-AC:553-573, comment 557-559) | Silent (E-AOS:154-166) | **= legacy**, (a?) — raised in WO §8 2026-10-16 slice 2a, unanswered |
| **The day close notifies nobody** | `CloseAttendanceDay` sends nothing | E-ASS:68-80 sends nothing | **= legacy**, (a?) — raised in WO §8 slice 3, unanswered |
| **A refused day counts as absent and can be excused** | Grid: refused → `absent` + `rejected` (L-AS:1002-1010). Excuse: only *accepted* check-ins block it (L-LRC:397-410) | E-ARS:400-405; E-LES:163-180 (`accepted()`) | **= legacy** |
| (catch-up API gap #4) An on-leave grid day has no `leave_request_id` | Grid day carries `leave_type` only; the `excuse` block is set only on excused days (L-AS:1030, 1050) | E-ARS:425, 430 | **= legacy** |

---

## 4. Per-rule parity tables

### 4.1 Attendance — recording

| # | Rule | Legacy evidence | ERP evidence | Same? | Class | Impact |
|---|---|---|---|---|---|---|
| A1 | Who may record | `attendance.record_self` + the `attendance_check` flag → 403 «تسجيل الحضور غير مفعّل لهذا المستخدم.» (`routes/api.php:295-301`, `EnsureAttendanceRecordingEnabled.php`) | Account linked to an employee (else 403), employee `active` (else 422 «لا يمكن تسجيل الحضور لموظف ليس على رأس عمله.») — `AttendeeResolver.php:41-49` | Different | (a) Q7; suspended = (a?) WO §8 slice 1, item 1 | All 236 live users have the flag, so the population is the same; a suspended employee is refused |
| A2 | No check-in on a non-working day | «لا يمكن تسجيل الحضور في يوم عطلة.» L-AS:243-247 | E-AS:135-139 | = | — | All 7 days are working days (Q8) |
| A3 | Check-in window | Config open; **code fallback 06:00–17:00** (L-AS:63-71) | Config open; fallback open (E-CAL:103-111) | = with the shipped config | (a) Q9; WO §8 slice 1 "found while porting" | Differs only if the key is missing |
| A4 | One check-in a day, even after a completed day; a refused check-in frees the slot | L-AS:266-268, 305-308 | E-AS:153-157 | = | — | — |
| A5 | No check-in on approved leave (requests **and** excuses) | L-AS:270-272, 332-338 | E-AS:159-161; `ApprovedLeaveFromRequests.php:29-36` | = | — | — |
| A6 | A check-out needs a standing check-in and no standing check-out that day | Count of check-ins > check-outs (L-AS:280-284, 324-330) | Check-in exists and no accepted check-out (E-AS:171-177) | = (equivalent under one shift a day) | — | — |
| A7 | The check-out must be after the check-in | L-AS:286-288 | E-AS:179-181 | = | — | — |
| A8 | 60-minute guard, judged on the two events' times, boundary allowed; HR corrections exempt | L-AS:293-297; correction has none (L-AC:452-509) | E-AS:185-188; E-AOS:53-93 | = | (a) Q10 | — |
| A9 | Work hours: real hours, 2 dp, stored on the check-out row | `floatDiffInRealHours` (Carbon 2, absolute) L-AS:508-515 | Explicit order for Carbon 3 (E-AS:96) | = | — | Same value |
| A10 | `recorded_at` normalised to the attendance timezone; the day judged in it | L-AS:169-172, 194, 222 | E-CAL:98-101; E-AS:87, 108 | = | — | — |
| A11 | A future `recorded_at` is accepted | Only `date` validated | Same (`RecordAttendanceRequest`) | = | (a?) WO §8 slice 1, item 2 | Wrong phone clock → check-in for tomorrow, in both |
| A12 | Writes serialised by a row lock | `users` row (L-AS:192) | `employees` row (E-AS:79) | = in behaviour | (a) `ATTENDANCE_BUILD.md` §2 | — |
| A13 | Offline sync: entry by entry, a selfie required per entry, only rule messages echoed, other errors logged and generic | L-AC:81-145 | `AttendanceSyncService.php:41-78` | = | — | — |
| A14 | A retried offline entry | «already checked in» error | Returns the saved record (`client_uuid`, E-AS:81-84, 116-122) | ⚠ Different | (a) Q12 | Intended |
| A15 | Wire encoding of `type` (record and sync) | `check_in` / `check_out` strings | `1` / `2` ints (`RecordAttendanceRequest`, `SyncAttendanceRequest`) | Different | **(c) C-6** | An unmodified legacy app build cannot record against the ERP. WO §4.5 and mapping §10 say "legacy payload shape accepted" |
| A16 | Selfies | Public disk, URL in reports (L-AC:860-869) | Private disk, streamed, files purged after 3 months | Different | (a) Q11 / D7 | — |
| A17 | Records list: today by default; `date` > `from/to` > `month` > `year` | L-AC:709-744 | `AttendanceRecordQuery.php:157-166` | = | — | Page size 25 → 15, ints for `type`/`reason`: (a) WO §8 slice 2a |

### 4.2 Attendance — oversight of records

| # | Rule | Legacy evidence | ERP evidence | Same? | Class | Impact |
|---|---|---|---|---|---|---|
| B1 | Correcting a forgotten check-out: a check-in only («يجب اختيار سجل دخول…»); «لا يحتاج إلى تصحيح» when the day already has a check-out; after the check-in | L-AC:454-476 | E-AOS:55-72 | = | — | — |
| B2 | …a **refused** check-in | Allowed when another, accepted check-in stands open that day: a check-out is written with hours measured from the **refused** check-in's time (L-AC:462 checks the day, not the row) | Refused: «لا يحتاج إلى تصحيح» (E-AOS:65) | ⚠ Different | **(b)**; WO §8 slice 2a "a refused check-in needs no correction" | Legacy could write wrong hours |
| B3 | …taken under the employee lock | No lock | Lock (E-AOS:60, 232-235) | Different | (b) hardening, WO §8 slice 2a | — |
| B4 | The correction row: check-in's location, no selfie, corrector, time, note, hours; the missing flag cleared | L-AC:482-502 | E-AOS:74-89 | = | — | — |
| B5 | Refusing a record: location / selfie / other, a note required for *other* (≤ 500); «تم رفض هذا السجل مسبقاً.»; a check-in takes that day's check-outs with it; one batch per decision; under lock | L-AS:362-406; `RejectAttendanceRecordRequest` | E-AOS:114-152; ERP `RejectAttendanceRecordRequest` | = | `reason` as int: (a) slice 2a | — |
| B6 | The refusal notification: text, and written atomically with the refusal | L-AC:536-542, 580-598 | E-AOS:148, 242-262 | = | — | ERP skips an employee with no account (impossible in legacy) |
| B7 | Undoing a refusal: restored from the batch's check-in; refused when a re-recorded check-in or check-out stands | L-AS:419-501 | E-AOS:166-230 | = | — | Same messages |
| B8 | Undoing a refusal notifies nobody | L-AC:557-559 | E-AOS:158-160 | = | (a?) | — |

### 4.3 Attendance — the schedules

| # | Rule | Legacy evidence | ERP evidence | Same? | Class | Impact |
|---|---|---|---|---|---|---|
| C1 | Check-out reminder 17:00, working days, everyone with a standing check-in and no check-out; same text | L-K:26-29; `SendCheckoutReminders.php` | `routes/console.php:24-27`; E-ASS:44-58 | = | (a) Q10 | — |
| C2 | …sent to inactive accounts | Sent to every non-deleted user | Skipped (`NotificationService.php:163`) | Different | (c) C-9 | Negligible: an inactive account cannot sign in |
| C3 | Day close: accepted orphan check-ins with no status become `missing_checkout`; refused days untouched; idempotent; no hours invented | `CloseAttendanceDay.php` | E-ASS:68-80 | = | — | — |
| C4 | Close time | Config 23:59, **code and scheduler fallback 22:00** (L-AS:84-87, L-K:34) | 23:59 both (E-CAL:131-134) | = with the shipped config | (a) Q10 | — |
| C5 | Monthly cleanup | Deletes every record older than a month (L-K:19) | None; selfie files purged after 3 months, rows kept (E-ASS:89-110) | Different | (a) Q11 / D7 | — |
| C6 | The day close notifies nobody | — | — | = | (a?) WO §8 slice 3 | — |

### 4.4 Attendance — reports and their maths

| # | Rule | Legacy evidence | ERP evidence | Same? | Class | Impact |
|---|---|---|---|---|---|---|
| D1 | Daily board: seven sections, first check-in / last check-out, `present` = checked in + checked out (missing check-out excluded) | L-AS:546-632 | E-ARS:83-146 | = | — | — |
| D2 | Daily `rejected`: only refused days not covered by an accepted check-in or leave, on a working day; the refused check-out paired by batch | L-AS:599-607, 650-689 | E-ARS:120-125, 156-169 | = | — | Legacy's unbatched-row fallback is not needed (no data imported) |
| D3 | Daily board population | Every user in reach, soft-deleted excluded (L-AS:1249-1253) | Every employee in reach, **trashed included** (E-POP:37-40) | Different | (c) C-8 | Edge case: a departed employee's leave shows on the board |
| D4 | Monthly report rows | `attendance_check = true` users, not deleted (L-AS:762-767) | Active employees with an active account (E-POP:48-54) | Different | (a) Q7; WO §8 slice 2b | Suspended employees and employees without accounts have no row |
| D5 | Expected days = working days in [from, to], **not clipped at today** | L-AS:770 | E-ARS:188 | = | — | Future days absent (§3, §6 S-1) |
| D6 | Present days = distinct accepted check-in dates (a missing check-out day counts) | L-AS:776-782 | E-ARS:190-192, 204 | = | — | — |
| D7 | Total hours = sum of accepted check-outs' `work_hours`; the missing count = accepted check-ins flagged | L-AS:784-799 | E-ARS:193-196 | = | — | See §6 S-2 |
| D8 | Leave days clipped to the window, counted in working days, a worked day not counted; excuses split deducted / not | L-AS:1185-1242 | E-ARS:277-296; E-CAL:78-89 | = | — | — |
| D9 | Rejected days = refused check-in days with no accepted check-in, on a working day, no leave; an annotation inside `absent_days` | L-AS:696-740 | E-ARS:307-324 | = | — | — |
| D10 | `absent = max(0, expected − present − on_leave − excused)`; rate = present / expected, 1 dp | L-AS:818, 843-845 | E-ARS:219, 225 | = | — | — |
| D11 | Row filters (has absences / missing check-outs) recompute the totals; 92-day cap; the `month` shorthand | L-AC:342-374; `MonthlyAttendanceReportRequest` | E-ARS:229-243; `Requests/Concerns/ReportsOnPeople.php:35-48, 82-88` | = | — | — |
| D12 | Grid precedence: check-in > day off > excuse > leave > refusal > absent | L-AS:965-1014 | E-ARS:385-409 | = | — | — |
| D13 | Grid hours when a check-out lacks stored hours | Computed from the pair (L-AS:961-963) | Stored only (E-ARS:383) | = in practice | — | Every ERP check-out stores hours |
| D14 | Grid of a departed employee | 404 (soft-deleted excluded, L-AS:903-911) | Readable (`withTrashed`, E-ARS:341) | Different | (c) C-8 | Improvement |
| D15 | Absent today: expected, no accepted check-in, not on approved leave (unless allowed), empty on a day off | L-AS:1332-1356; L-AC:609-659 | E-ARS:480-504 | = | Population: (a) Q7 | — |
| D16 | `formatHours` "8h 30m" | L-AS:524-534 | E-ARS:644-653 | = | — | — |

### 4.5 Leave — types and balances

| # | Rule | Legacy evidence | ERP evidence | Same? | Class | Impact |
|---|---|---|---|---|---|---|
| E1 | The catalogue | Code seeds 6 (`database/migrations/2026_08_18_000001_create_leave_types_table.php:51-56`); the live DB holds 9 | The live 9, `attendent_execused` → `attendance_excused` (`database/migrations/Leave/2026_10_18_100000_create_leave_types_table.php:64-74`) | Matches the live table | (a) Q3 | — |
| E2 | Sick leave | Live: deducts | Does **not** deduct; requires an attachment | ⚠ Different | (a) Q3 | A sick day no longer costs balance |
| E3 | Flags: `deducts_balance`, `for_requests`, `for_excuses`, `requires_reason`, `is_active` | `LeaveType.php` | `LeaveType.php` + `requires_attachment` | = + one flag | (a) Q3 | — |
| E4 | Deleting a type in use deactivates it | `LeaveTypeController.php:84-98` | `LeaveTypeController.php:68-71` | = | — | ERP soft-deletes an unused type; legacy hard-deleted it |
| E5 | Free-text `leave_type`, falling back to a deducting type | `leave_type_id` optional (L-SLR rules); a null type deducts (L-LRC:258-259) | `leave_type_id` required (`FileLeaveRequest`) | Different | (a) `LEAVE_BUILD.md` §1, §8.5 | — |
| E6 | Allocation = row (allocated + adjustment, floored at 0), else the default | L-U:168-179, `LeaveBalance::totalDays()` | E-LB:55-62; `LeaveBalance::totalDays()` | = | (a) Q4/D13 | — |
| E7 | The default | Config 15 from `ANNUAL_LEAVE_DAYS`; **code fallback 14** (L-U:178) | 15 from `LEAVE_ANNUAL_DAYS`, fallback 15 | = with the shipped config | (c) C-10 | Renamed env key |
| E8 | Used = approved, deducting `requested_days` of leave **starting** in the year | L-U:187-196 | E-LB:88-100; E-LR:313-316 | = | — | 30 Dec → 3 Jan is charged to the first year in both |
| E9 | Remaining, over-balance, non-deducting days | L-LRC:1456-1479; L-U:209-225 | E-LB:62-73 | = | — | — |
| E10 | Pending leave reserves nothing (re-check at the final approval) | L-LRC:807-819 | E-LR:172-188 | = | — | — |
| E11 | Updating a balance: allocated 0–365, adjustment −365…365 (omitted → 0), note ≤ 255, refused below the days used | L-LBC:103-149; `UpdateLeaveBalanceRequest` | `LeaveBalanceController.php:90-123` | = | — | The ERP also locks the employee |
| E12 | Who updates balances | Admin, HR (L-RC:96-122, `leave.balance.manage`) | `leave.balances.update`, row-scoped, granted to no role | Different | (a) `LEAVE_BUILD.md` §7 → C-2 | Provisioning |

### 4.6 Leave — filing a request

| # | Rule | Legacy evidence | ERP evidence | Same? | Class | Impact |
|---|---|---|---|---|---|---|
| F1 | The type is active and for requests; a reason where the type requires one | L-SLR `withValidator` | `FileLeaveRequest` `withValidator` | = | — | Same Arabic messages |
| F2 | Attachments: ≤ 5, pdf/jpg/png, 10 MB; required for sick leave | none | `AttachmentRules`, `FileLeaveRequest` | New | (a) `LEAVE_BUILD.md` owner answers | — |
| F3 | ≥ 1 working day | «The selected period contains no working days.» (L-LRC:261-265) | «الفترة المحددة لا تتضمن أيام عمل.» (E-LRS:60-62) | = rule | (a) `LEAVE_BUILD.md` §8.6 (Arabic) | — |
| F4 | A deducting type within the remaining balance | L-LRC:269-274 | E-LRS:70-76 (remaining named in the message) | = | (a) `LEAVE_BUILD.md` §10 settled 4 | — |
| F5 | No overlap with a pending or approved request or excuse | L-LRC:276-285 | E-LRS:113-124 | = | — | — |
| F6 | The order: days → balance → overlap | L-LRC:261-285 | E-LRS:60-78 | = | — | — |
| F7 | A lock around the checks | **None**: checks run outside the transaction (L-LRC:261-287) | Employee lock (E-LRS:68) | Different | **(b)** race fixed | `LEAVE_BUILD.md` §4 says legacy locked — it did not (§7) |
| F8 | `requested_days` = working days of the span | L-LRC:255 | E-LRS:58 | = | — | — |
| F9 | Approvers | Chosen by the employee: 1–10 managers/chiefs, never oneself (L-SLR rules) | Resolved: direct manager → manager of unit `HR` | ⚠ Different | (a) Q1 | — |
| F10 | Who may file | Employee, manager, chief roles; not admin (L-SLR `authorize`) | Any account linked to an employee, any status | Different | (c) C-3 (status) | A suspended employee can file |
| F11 | The first approver told «طلب إجازة جديد» / «{الاسم} قدّم طلب إجازة.»; no receipt to the requester | L-LRC:323-328 | E-APS:98; E-LR:223-233 | = | (a) `LEAVE_BUILD.md` owner answers | — |

### 4.7 Leave — the approval chain

| # | Rule | Legacy evidence | ERP evidence | Same? | Class | Impact |
|---|---|---|---|---|---|---|
| G1 | Sequential; only the current step's approver acts; others get «ليس دورك» | L-LRC:781-797 | E-APS:189-198 | = | — | — |
| G2 | A rejection ends it; the steps behind are closed as skipped | L-LRC:826-839 | E-APS:203-204, 227-228, 391-400 | = | — | — |
| G3 | The final approval re-checks the balance under the employee lock | L-LRC:799-819 | E-APS:210-218; E-LR:172-188 | = | — | Same Arabic message |
| G4 | Earlier approvals do not check the balance | L-LRC:807-808 | E-APS:212 | = | — | — |
| G5 | Self-approval | Cannot name oneself; a self-assigned request can only be rejected (`ReviewLeaveRequest`) | Requester step and same-approver step **skipped** with a reason (E-APS:140-144) | Different | (a) Q2 | — |
| G6 | A chain that cannot resolve (vacant HR post, an approver with no active account) | n/a (chosen) | The filing is refused, nothing saved (E-RES:52-61, 81-91) | New | (a) engine §9 Q2, Q8 | — |
| G7 | **المدير العام's own leave** | Allowed: picks any other manager/chief | **Refused**: «لا يوجد مدير مباشر…» — `ManagementChain` excludes the requester and the root has nothing above it (`app/Shared/Services/ManagementChain.php` `candidateIds`, 123-128; E-RES:70-73) | ⚠ Different | **(c) C-1** | **A real person cannot file leave** |
| G8 | An employee in no unit | Allowed | Refused (same path) | ⚠ Different | (c) C-1 | Only if the import leaves someone unit-less |
| G9 | Reassignment | Admin only; target a manager/chief; reason 3–500; not the requester; not already on the chain; pending step only (L-LRC:915-1026) | `approvals.reassign` row-scoped; target any active employee with an active account; same other rules (E-APS:284-349) | Different | (a) engine §9 Q4, Q7 | — |
| G10 | The reassignment notice: «تم إسنادك لاعتماد طلب إجازة», now vs ahead | L-LRC:1008-1020 | E-APS:337-345; E-LR:223-233 | = | — | — |
| G11 | Withdrawing a pending request before any decision | None | `POST /api/me/leave-requests/{id}/cancel` (E-APS:248-269) | New | (a) engine §9 Q1 | — |
| G12 | …the approver whose turn it was | n/a | Not told (E-APS:265, 391-400; E-LR:196-221 sends nothing on cancel) | New | (c) C-5 | Their `step_pending` notification opens a cancelled request |
| G13 | A note on a decision | None | Optional (E-APS:224) | New | (a) engine §9 Q6 | — |
| G14 | The approver's inbox | Every request where they hold **any** step, current or not, with filters (search, unit, type, dates, reviewed); admins see the whole queue (L-LRC:110-209) | Only steps whose turn it is, plus a decided tab; no filters; no all-pending list (`ApprovalInbox.php:32-46`) | Different | Current-turn only: (a) engine design §7; filters and oversight list: (c) C-4 | HR finds stuck requests through the register (needs `leave.requests.view`) |
| G15 | Approvers fixed at submission | Chosen at submission | Frozen at submission | = | (a) engine §9 Q3 | — |
| G16 | The requester told: «تمت الموافقة على طلب إجازتك من … إلى …» / «تم رفض طلب إجازتك من … إلى …» | L-LRC:881-897 | E-LR:196-221 | = | — | — |

### 4.8 Leave — HR's side

| # | Rule | Legacy evidence | ERP evidence | Same? | Class | Impact |
|---|---|---|---|---|---|---|
| H1 | An excuse: approved at once, no chain, a for-excuses type, a reason where required, ≥ 1 working day | L-LRC:349-375; `StoreLeaveExcuseRequest` | E-LES:55-64; `FileLeaveExcuseRequest` | = | (a) Q5 | — |
| H2 | Excuse checks in order: overlap → attended days (listed) → balance unless `force` | L-LRC:378-420 | E-LES:70-81 | = | — | A refused check-in is not attended, in both |
| H3 | Attachments on an excuse | None | Optional | New | (a) `LEAVE_BUILD.md` owner answers | — |
| H4 | The excuse notification «تم تسجيل عذر عن غيابك», with deducted or not | L-LRC:445-458 | E-LES:182-188 | = | — | — |
| H5 | Undoing an excuse: excuses only; a standing one only; «already undone»; the row kept, cancelled, with who/when/why; under lock | L-LRC:486-534 | E-LES:125-147 | = | — | — |
| H6 | The undo notification «تم التراجع عن عذر غيابك», the balance day returned | L-LRC:540-555 | E-LES:150-154 | = | — | — |
| H7 | Return to work: approved requests only, an excuse refused, `returned_on` today by default and inside the leave, cut back to the day before and recounted, back on day one → cancelled, `original_end_date` written once, the approval untouched | L-LRC:593-698 | E-RTW:43-101 | = | — | — |
| H8 | Days returned, and the notification | L-LRC:700-736 | E-RTW:96-98, 113-132 | = | — | See §6 S-4 |
| H9 | Who files and undoes excuses and records a return to work | One permission `leave.excuse`: admin, HR **and every manager and chief**, within the department (L-RC:15-33, 96-122; L-AS:1261-1269) | Three row-scoped grants, granted to no role (`Leave/routes.php:95-109`) | ⚠ Different | (a) `LEAVE_BUILD.md` §7 says so; provisioning = **(c) C-2** | Managers refused until granted |

### 4.9 Leave — the register

| # | Rule | Legacy evidence | ERP evidence | Same? | Class | Impact |
|---|---|---|---|---|---|---|
| I1 | The window: from/to, month (+ year), a bare year = that year, else the current month; ≤ 366 days | `LeaveRegisterRequest.php:12-37, 175-190` | ERP `LeaveRegisterRequest.php:15-46, 72-81` | = | — | — |
| I2 | An invalid month or year | Silently falls back to the current month (legacy `LeaveRegisterRequest.php:175-180`) | 422 | Different | (c) C-7 | — |
| I3 | Approved only by default; `statuses` widens | legacy `LeaveRegisterRequest.php:137-149` | ERP `LeaveRegisterRequest.php:107-109` | = | — | — |
| I4 | Per row `days_in_range` (working) and `calendar_days_in_range` | L-LRC:1194-1224 | `LeaveRegister.php:54-56, 147-163` | = | — | — |
| I5 | The summary over the whole set: days, calendar days, deducted days, excuses, employees, requests, by type (by days, descending), by status | L-LRC:1121-1186 | `LeaveRegister.php:102-142` | = | — | — |
| I6 | Who reads it | `leave.register.view`: admin and HR, company-wide; deliberately not managers (`routes/api.php:335-346`) | `leave.requests.view`, row-scoped, grantable to managers | Different | (a) `LEAVE_BUILD.md` §6–7 | Wider by design |

### 4.10 Notifications, step by step

| Step | Legacy (type · recipient) | ERP (type · recipient) | Same? | Class |
|---|---|---|---|---|
| Leave filed | `leave_request_submitted` · first approver | `approval.step_pending` · current approver(s) | = text | — (type ids renamed for the new app) |
| Step approved, next turn | `leave_request_approval_required` · next approver | `approval.step_pending` · next | = | — |
| Final approve / reject | `leave_request_reviewed` · requester | `leave.approved` / `leave.rejected` · requester | = | — |
| Reassigned | `leave_request_approval_reassigned` · new approver | `approval.step_pending` (current) / `approval.step_assigned` (ahead) · new approver | = | — |
| Withdrawn | n/a | nobody | new | (c) C-5 |
| Receipt to the requester | none | none (`leave.submitted` catalogued, unsent) | = | (a) owner answer |
| Excuse filed / undone | `leave_excuse_recorded` / `leave_excuse_cancelled` · employee | `leave.excuse_filed` / `leave.excuse_cancelled` · employee | = | — |
| Return to work | `leave_returned_to_work` · employee | `leave.returned_to_work` · employee | = | — |
| Record refused | `attendance_rejected` · employee | `attendance.record_rejected` · employee | = | — |
| Refusal undone | none | none (`attendance.record_unrejected` catalogued, unused) | = | (a?) |
| Check-out reminder | `attendance_checkout_reminder` · open check-ins | `attendance.checkout_reminder` · open check-ins with active accounts | ≈ | (c) C-9 |
| Day closed | none | none (`attendance.missing_checkout` catalogued, unused) | = | (a?) |

### 4.11 Who may see or do what (scope)

| Capability | Legacy (roles, `RoleCatalog.php` + `canView`/`applyUserFilters`) | ERP | Class |
|---|---|---|---|
| Model | Role-based. Admin and HR (`can_view_attendance`) reach everyone; manager, chief and **section manager reach their whole department** (L-AS:1280-1309) | Permission + row-scoped grant over the **employee's current unit and its subtree** (GAP-3, D6) | (a) Q13, GAP-3 |
| Attendance reports, records, absent today | admin, HR, managers, chiefs (dept) | `attendance.records.view` grant | (a) → C-2 |
| Correct a check-out | admin, HR, managers, chiefs (dept) — `legacy_unscoped_manager` excluded (L-RC:62-73) | `attendance.records.correct` grant | (a) → C-2 |
| Refuse / un-refuse | admin, HR only (`EnsureCanRejectAttendance.php`; L-RC:110) | `attendance.records.reject` grant | (a) |
| Excuse, undo excuse, return to work | admin, HR, managers, chiefs (dept) — one permission | three grants | (a) `LEAVE_BUILD.md` §7 → C-2 |
| Balances: read / manage | read: managers (dept) + HR; manage: admin, HR | `leave.balances.view` / `.update` grants | (a) → C-2 |
| Register | admin, HR | `leave.requests.view` grant | (a) |
| Reassign approvals | admin | `approvals.reassign` grant, "HR company-wide at first" | (a) engine §9 Q4 |
| **Seeded defaults** | Roles carry these permissions out of the box | `RoleSeeder` seeds only `super_admin`, with no permissions; nothing grants any of the 14 new permissions | **(c) C-2** |

### 4.12 Not ported / out of scope

| # | Item | Class |
|---|---|---|
| X1 | XLSX exports of every list and report (legacy `AttendanceExportService`) | (d) dashboard feature, later (WO §4.5) |
| X2 | Legacy `/approved-leave-users` (who is on leave on one date) | (d) — the daily board's `on_leave` / `excused` sections and the register cover it (WO §8 slice 2b) |

---

## 5. Undocumented differences, ranked by risk

| Rank | Id | Difference | Same input → outcome | Risk | Proposed handling |
|---|---|---|---|---|---|
| 1 | **C-1** | **المدير العام cannot file leave**, and neither can anyone without a unit. Step 1's resolver walks up from the requester's unit, skipping the requester; above the root there is nothing, so it throws «لا يمكن تقديم الطلب: لا يوجد مدير مباشر في الهيكل التنظيمي لاعتماده.» (E-RES:70-73; `ManagementChain.php` `candidateIds`, 123-128). The decision round's Q2 covered مدير الموارد's own leave only. No test covers the general manager filing | The GM files annual leave → legacy: pending with the approver they chose · ERP: 422, nothing saved | **High** — a live user is blocked; approvals' §9 Q2 ("refuse when a step resolves to nobody") was answered for a *vacant* post, not for the top of the tree | Owner decides who approves the GM's leave (Q1 below); add a test either way |
| 2 | **C-2** | **Go-live authority parity.** Every manager and chief in legacy, for their department, could read the attendance reports and records, correct a check-out, file and undo excuses, record a return to work and read balances; HR could do all of that company-wide, plus refuse records, manage balances and read the register (L-RC:15-33, 96-122). The ERP has 14 separate permissions, all row-scoped and none seeded on any role. Section managers' reach also changes: legacy gave them the **whole department** (L-AS:1286-1287), the ERP gives their own subtree. `LEAVE_BUILD.md` §7 notes it for excuses, and approvals §9 Q4 for reassignment, but there is no go-live grant plan | A section manager opens their department's daily board → legacy: the department · ERP: 403 until granted, then only their section | **High / Medium** — not a bug; a configuration gap that will look like a regression on day one | Owner sets the grant plan (Q2); HR applies it before the pilot. Worth a seeded "unit manager" template if the owner wants legacy's defaults |
| 3 | **C-3** | **Suspended employees are handled inconsistently.** They cannot record attendance (422) but can file leave (no status check in `MyLeaveController` / E-LRS), and they drop out of the monthly report and absent-today (E-POP:52-53). Legacy had no suspended state (soft delete only). The recording rule was raised in WO §8 slice 1 and is unanswered; the other two are consequences nobody raised | A suspended employee files leave → accepted; the same person checks in → refused | Medium-Low | Fold into Q4 |
| 4 | **C-4** | **Approvals oversight.** Legacy's queue had search, unit, type, date and "reviewed" filters, and admins saw every pending request to reassign from (L-LRC:110-209). The ERP inbox has no filters (`MyApprovalsRequest`), and nothing lists all pending approvals for `approvals.view` / `approvals.reassign` holders; HR has to use the register with `statuses=1`, which needs `leave.requests.view` | HR looks for requests stuck on an absent approver → legacy: the admin queue · ERP: the register, only with another grant | Medium-Low (reassignment was never used in legacy, Q1) | Owner: needed for v1? (Q10) |
| 5 | **C-5** | **Withdrawal does not tell the approver** whose turn it was. Their `approval.step_pending` notification now opens a cancelled request (E-APS:248-269; E-LR:196-221) | — | Low | Q7 |
| 6 | **C-6** | **The `type` value changed on the wire**: `check_in` / `check_out` → `1` / `2`, for record and sync. WO §4.5 and mapping §10 say the sync accepts "the legacy payload shape" — true of its structure (`records[]` + `selfies[]`), not of its values | An old app build syncs → legacy: saved · ERP: 422 for the whole batch | Low — the pilot uses the migrated app (§4.10), and legacy keeps its own server | Correct the wording in WO §4.5 / mapping §10, or accept both encodings |
| 7 | **C-7** | The register's **invalid month/year**: legacy silently uses the current month, the ERP answers 422 | `?month=13` → legacy: this month · ERP: 422 | Low (arguably better) | Record it |
| 8 | **C-8** | **Departed (soft-deleted) employees** appear on the daily board and their grid opens (E-POP:37-40, E-ARS:341); legacy excluded deleted users (L-AS:903-911, 1249-1253) | — | Low (arguably better: history stays readable) | Record it |
| 9 | **C-9** | **Notifications skip inactive accounts** (the reminder, a refusal, leave outcomes — `NotificationService.php:137, 163`); legacy sent to any non-deleted user | — | Negligible | Record it |
| 10 | **C-10** | The default allocation's **env key was renamed** `ANNUAL_LEAVE_DAYS` → `LEAVE_ANNUAL_DAYS`, and legacy's code fallback was 14, not 15 (L-U:178) | Only if production legacy sets the key to another value | Negligible (D13 fixed 15) | Check legacy's production `.env` before cutover |

---

## 6. Legacy defects the ERP inherited unchanged (same in both — for the owner)

These are **=** in the tables: the port is faithful. Each still gives a wrong
or confusing number, so each is a candidate change request, not a parity
failure.

| Id | Defect | Legacy | ERP | Effect |
|---|---|---|---|---|
| S-1 | **Future working days count as absent** in the monthly report and the grid, and pull the attendance rate down | L-AS:770, 818, 1011-1013 | E-ARS:188, 219, 406-408 | On 20 Oct, the current month shows 11 future "absent" days per person. Only `/api/me/summary` clips at today |
| S-2 | **A late check-out does not clear `missing_checkout`.** When an offline check-out for a day already closed syncs the next morning, the check-out is accepted (the check-in still stands open), but the check-in keeps its flag. The monthly report counts a missing check-out; the grid and the board show the day as checked out | Check-out does not touch the check-in (L-AS:198-223); monthly counts the flag (L-AS:792-799); grid prefers the check-out (L-AS:966-971) | E-AS:90-114; E-ARS:195-196 vs 386-387 | The monthly report and the grid disagree for the same day; HR sees a "missing check-out" with nothing to correct (the correction is refused because the day has a check-out) |
| S-3 | **A corrected check-out time is unbounded**: any moment after the check-in, even the next day or in the future. The row lands on another date, so the day it corrects stays open and the next day gets an orphan check-out | L-AC:468-476 | E-AOS:69-72; `CorrectCheckoutRequest` | Wrong hours and a broken day from a typo |
| S-4 | **"Days returned" on a second return to work is cumulative**: measured from the original span, not from the previous shortening, so the second notification over-states it | L-LRC:705-710 | E-RTW:96-98 | «وأُعيد إلى رصيدك N يوم» repeats days already returned. The balance itself is right |
| S-5 | **An excuse can be filed for future dates.** There is no "the absence has happened" guard, so a future excuse then blocks check-in on those days | L-LRC:349-443 | E-LES:55-112 | An HR typo blocks someone from checking in |
| S-6 | **A future `recorded_at` is accepted** | `RecordAttendanceRequest` | same | Already raised (WO §8 slice 1) |

---

## 7. Documentation inaccuracies found

1. **Q13's description of legacy** (`DECISION_ROUND_ATTENDANCE_LEAVE.md` Q13)
   says legacy scopes by the unit at the time, so after a transfer "the old
   manager keeps seeing the old records". Legacy's reads in fact scope by the
   user's **current** department: `applyUserFilters` (L-AS:1286-1287), the
   records list's `whereHas('user')` scopes (`AttendanceRecord.php:229-240`),
   and `canView` (L-AS:1261-1269). The snapshot columns are written but not
   read by these screens. The outcome the owner chose (A) is therefore the
   same as legacy, not a change from it.
2. **`LEAVE_BUILD.md` §4 and E-LRS's docblock** say legacy locked the user row
   when filing. Legacy's `store()` takes no lock and runs its balance and
   overlap checks before its transaction (L-LRC:261-287). The ERP's lock is a
   fix (F7, class b), not a port.
3. **WO §4.5 and `LEGACY_TO_ERP_MAPPING.md` §10**: "legacy payload shape
   accepted" for sync. Only the structure is (C-6).
4. **The dashboard catch-up note** lists the future-days count as "NEW". It is
   legacy behaviour (§3).
5. **Legacy's own comment** (L-AS:759-761) says admins and managers have
   `attendance_check = false`; the decision round found all 236 live users
   have it on. No effect on the ERP.

---

## 8. Open questions for the project owner

Asked, not defaulted. Each names the legacy behaviour first.

1. **المدير العام's own leave (C-1).** Legacy: the chief picked any other
   manager or chief to approve. ERP: refused. Options:
   **A** — a named approver for the GM's requests only (for example مدير
   الموارد, or a deputy you name);
   **B** — the GM's leave is recorded by HR as an excuse (approved at once, as
   legacy's excuses);
   **C** — the GM's own leave needs no approval (approved on filing).
   Also: can anyone be imported without a unit? If so, the same applies to
   them.
2. **The go-live grant plan (C-2).** Which roles, with which scope, should
   reproduce legacy's defaults? Recommended starting point, matching legacy:
   managers and chiefs over «الوحدات التي أديرها» with
   `attendance.records.view`, `.correct`, `leave.excuses.create`, `.delete`,
   `leave.requests.returnToWork` and `leave.balances.view`; HR company-wide
   with all fourteen. And: should a section manager see their **section** (the
   ERP) or the **whole department** (legacy)?
3. **Future days in HR's reports (S-1).** Keep legacy's count (future working
   days are absent), or clip expected and absent days at today, as
   `/api/me/summary` already does? If you keep it, should the dashboard open
   the monthly report on last month, as legacy's did?
4. **Suspended employees (C-3).** Confirm the reading that a suspended
   employee cannot record attendance. Should they be able to file leave? Should
   they have a monthly-report row and appear absent?
5. **Undoing a refusal and closing the day** — notify the employee? (Open since
   2026-10-16; both events are catalogued and unused.)
6. **A future `recorded_at`** — keep accepting it (legacy), or refuse it?
   (Open since 2026-10-16.)
7. **Withdrawal (C-5)** — tell the approver whose turn it was?
8. **A late check-out after the day closed (S-2)** — clear the missing
   check-out flag when the check-out lands (the reports then agree), or keep
   legacy's behaviour?
9. **The second return to work (S-4)** — say only the days returned this time?
10. **Approvals oversight (C-4)** — does v1 need a list of every pending
    approval for HR, and filters on the approver's inbox, as legacy's queue
    had?
11. **Correcting a check-out (S-3)** — require the time to be on the check-in's
    day and not in the future?
12. **Excuses for future dates (S-5)** — allow, or refuse past today?

---

## 9. Method

Each rule was traced in legacy first (controller → service → model scope →
form request → scheduled command), then in the ERP, then compared line by
line. Message strings, query predicates (`accepted()`, `approved()`,
`overlapping()`), the order of checks, locks, and who is notified were
compared, not only the feature names. The ERP test suite for the four
modules was run; legacy's was read but not run (it needs MySQL). Sibling
session notes (`side-session-notes/local_55836422-…`) supplied the dashboard
catch-up's observations, re-checked here in §3.
