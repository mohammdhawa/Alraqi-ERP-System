# Work shifts — the dashboard's screens: design

> **Status:** design, agreed section by section on 2026-11-10. Not built.
> **Piece 3 of 4.** Work shifts is built as four pieces, each with its own spec,
> plan and build: (1) the backend core —
> [`2026-11-09-work-shifts-backend-core-design.md`](2026-11-09-work-shifts-backend-core-design.md);
> (2) one-off changes and duty exchanges —
> [`2026-11-10-work-shifts-changes-and-swaps-design.md`](2026-11-10-work-shifts-changes-and-swaps-design.md);
> **(3) this one, the dashboard's screens**; (4) the app. Pieces 1 and 2 are
> merged to `main` (pull request 8, `6493015`).
> **Repositories:** one backend step in `alraqi-erp-system` (§3), everything
> else in `../erp-dashboard`.
> **The API it reads:** [`API_DOCUMENTATION.md`](../../API_DOCUMENTATION.md)
> §19 (work shifts), §14 (attendance reports), §15 (approvals), §6
> (notifications).

---

## 1. Intent

**What it is for.** Pieces 1 and 2 put work shifts in the API: 27 routes, 22 of
them HR's. Nothing in the dashboard reads them, so today a developer sets up a
schedule. This piece gives HR the screens to run work shifts themselves, and
gives the guards' managers a screen to decide an exchange.

**Who it is for.** HR, holding `scheduling.view` (read) and `scheduling.manage`
(write) — both company-only, carried by no seeded role. The guards' direct
managers, who decide exchanges in the approvals inbox.

**What success looks like.**

- HR sets up the guards' cycle, assigns it to the guards in bulk, sees who works
  when on one calendar, fixes a day from that calendar, and cancels an approved
  exchange — without a developer.
- A manager opens a pending exchange from the inbox and approves, rejects or
  reassigns it in the dashboard.
- The attendance screens show each row's shift and its late and early minutes.
- An admin can grant `scheduling.view` / `scheduling.manage` from the roles
  screen.

### 1.1 Decided while designing (2026-11-10)

| # | Question | Answer |
|---|---|---|
| D1 | Do managers decide exchanges in the dashboard, or only in the app? | **In the dashboard too** — a general approval screen (§7.2). |
| D2 | Where does HR make day-to-day edits? | **From the calendar** — click a day to change it, tick rows to assign a schedule; the employee profile also gets a «الجدول» tab. |
| D3 | Which changes to the existing attendance screens? | **The shift, late and early-departure columns and totals** only. Not the outside-schedule flag, not the minutes within/outside the shift, not new wording for statuses `7` and `9` (the screens already show the server's `status_label`, and nothing in the dashboard assumes Friday is the only day off). |
| D4 | Approach | **Dashboard-only, no new backend detail for approvers** — the manager decides from the approval's `subject.summary` (both names, both duties' spans); the server re-checks every rule at approval. Exchange details for approvers (`details` for `shift_swap`, an approver read of the exchange) can be added later without rework. A calendar library was rejected: a resource timeline is a paid plugin, and the free ones handle RTL and per-employee paging poorly. |
| D5 | The calendar API lists leavers, filters a unit without its subtree, and has no search | **Fix it in the backend first** (§3), matching the attendance reports. |

### 1.2 Not in this piece

- The app's screens (piece 4), including the guard filing an exchange.
- The outside-schedule flag and minutes within/outside the shift on the
  attendance screens (D3).
- A screen for the guard's own exchange in the dashboard: the `shift_swap.*`
  notifications go to guards, who use the app, so in the dashboard they keep
  the full text (§7.3).
- Drag-selecting days on the calendar: click plus shift-click covers it and
  works by keyboard (§5.3).
- Disabling a used shift's time fields or the default schedule's editor ahead
  of time: the API carries no `in_use` / `is_default` flag, so the screens show
  the server's refusal instead of guessing (§10).

---

## 2. Structure

### 2.1 The module

`src/sections/scheduling/` in the dashboard. Screens are `.jsx`; rules live in
pure `*.js` helpers beside them, tested with `node --test` — the pattern
`attendance/` and `leave/` already follow (no `api` import in a helper, since
`api.js` touches `import.meta`).

### 2.2 Routes and sidebar

A new sidebar group «الورديات والجداول», after «الحضور»:

| Path | Screen | Gate (`requiredPermissions`) |
|---|---|---|
| `scheduling/calendar` | the calendar (§5) — the group's first link | `scheduling.view` |
| `scheduling/shifts` | shifts (§6.1) | `scheduling.view` |
| `scheduling/schedules` | schedules (§6.2) | `scheduling.view` |
| `scheduling/swaps` | HR's exchanges (§7.1) | `scheduling.view` |
| `approvals/:id` | the general approval screen (§7.2) — no sidebar link | none: admission is the server's, as `leave/requests/:id` |

Write controls on every screen show only with `scheduling.manage`.
Bulk assignment is a modal on the calendar, not a page. The employee profile
gains a «الجدول» tab (§6.3).

### 2.3 Changes to what exists

- `src/data/permissionsCatalog.js` — a «الورديات» group:
  `scheduling.view` «عرض الورديات والجداول», `scheduling.manage` «إدارة
  الورديات والجداول». Without it the roles screen cannot grant them.
- `src/sections/approvals/approvalView.js` — `subjectPath` sends a
  `shift_swap` subject to `approvals/{approval id}` (§7.2).
- `src/sections/approvals/approvalFilters.js` and the inbox — a `subject_type`
  filter (§7.3).
- `src/data/notificationDestinations.js` — `approval.step_pending` and
  `approval.step_assigned` on a `shift_swap` reference open the inbox filtered
  to exchanges (§7.3).
- The attendance screens — §8.

---

## 3. Step 1, backend: the calendar's people filters

**Today** `GET /api/scheduling/calendar` lists **every** employee, leavers
included; `department_id` matches that exact unit, not the units under it;
there is no search. Every attendance report filters people differently: units
with their subtree, `search` on a name part or the staff number (§14, "The
person filters").

**After:**

- **Employed people only** — `Employee::employed()` (نشط or موقوف, not منتهي
  الخدمة). A person who left is not on the calendar even for dates before they
  left; their past days are in the attendance reports.
- **The reports' person filters**: `employee_id` / `employee_ids`,
  `department_id` / `department_ids` (each with its subtree), `search` — the
  same rules, the same CSV-or-array lists.
- `from`, `to` (at most 92 days), `per_page` (1–100) unchanged.

**One implementation, not two.** The filter is `AttendancePopulation::filter()`
today, with its rules and CSV normalisation in
`Attendance/Requests/Concerns/ReportsOnPeople`. Attendance already reads
Scheduling (the resolver), so Scheduling must not read Attendance back. The
filter moves to where both can read it — a scope on `Employee` (HR), which both
modules already read — with the people rules and their normalisation beside it.
The attendance reports keep their behaviour exactly; their tests prove it.

**Tests first**, failing: a leaver is not listed; a division's filter lists the
people of its sections; `department_ids` and `employee_ids` as CSV and as an
array; `search` by a name part and by staff number; an unknown unit or person
narrows to nobody (an empty page), as on the reports — today's calendar answers
422 there, so this is the one visible change besides the three above. Then the
whole suite and Pint.

**Docs:** API doc §19.5; `START_HERE.md` §2 and §10.

---

## 4. Shared pieces

- **`ShiftChangeModal`** — «تعديل الوردية», used by the calendar (§5.3) and the
  profile tab (§6.3). Input: employee, `from`, `to` (≤ 31 days). On open it
  reads `GET /api/hr/employees/{employee}/shift-changes?from&to` and lists what
  is already there. Fields: a shift (active shifts only) or «يوم راحة», and
  `reason` (required). On a past date a note: the check-ins will be matched
  again and the employee is not told. Submits `POST …/shift-changes`. It is
  all or nothing on the server, so a 422 shows its message in the modal and
  nothing is written. Existing HR changes in the range each offer «إزالة»
  (`DELETE …/shift-changes/{change}`); an exchange's change offers a link to
  it instead.
- **`AssignScheduleModal`** — one person (§6.3) or many (§5.4): an active
  schedule, `from_date` (today or later), `cycle_start_date` shown and
  required only for a cycle schedule (`kind` `2`).
- **Option lists** — active shifts and active schedules, read once per screen.
- **Display helpers (pure)** — a shift's span (`starts_at`, end, «اليوم
  التالي» when `ends_next_day`), a schedule day's label (§6.2), minutes (§8).

---

## 5. The calendar (`scheduling/calendar`)

### 5.1 Filters

Kept in the URL, read through a pure helper (`calendarFilters.js`, after
`dailyFilters.js`), so a link reproduces the view:

- **Window** `from`/`to` — default the 14 days from today (Damascus); presets
  «هذا الأسبوع» (Saturday to Friday) and «هذا الشهر»; at most 92 days, the API's
  limit. An untrustworthy link (bad dates, inverted, too long) falls back to
  the default.
- **Unit** — the existing department picker; the filter includes sub-units
  (§3).
- **Person** — `employee_id` (set by the profile's «عرض في التقويم» link) and
  `search`.
- **Page** — 25 people a page.

### 5.2 The grid

- One row per person, one column per date. The first column — name and staff
  number — stays in place while the dates scroll sideways inside the table; the
  page itself never scrolls sideways.
- A date's header: weekday and day number; today's column highlighted.
- A cell: the shift's `code`, its name and times on hover (`title`); «راحة»
  when `shift` is `null`; a small marker when the shift ends the next day.
- **Colour by `source`**, with a legend: `default` light grey, `assignment`
  plain, `change` amber, `swap` blue. The mapping is a pure helper; an unknown
  source renders plain, never breaks the grid.

### 5.3 Editing a day (`scheduling.manage`)

Every cell is a button, so the grid works by keyboard.

- **Click** a cell → `ShiftChangeModal` for that person and date.
- **Shift-click** a second cell in the same row → the modal for the range
  between them. A pure helper (`rangeSelection.js`) orders the two dates and
  refuses a range in another row or longer than 31 days, with a message,
  before any request.
- A cell whose `source` is `swap` opens the modal read-only, with its link to
  the exchange in `scheduling/swaps` — an exchange's days change only by
  cancelling it (API §19.6).
- After a save or a removal the current page reloads from the server.

### 5.4 Bulk assignment

- A checkbox per row, and one in the header for the page. «إسناد جدول» opens
  `AssignScheduleModal` in bulk mode with a choice of whom: **the ticked
  people** (`employee_ids`), or **everyone in the filtered unit and its
  sub-units** (`department_id` + `include_subtree: true`; offered only when a
  unit is filtered).
- `POST /api/scheduling/assignments/bulk` is per person, never all or nothing:
  the modal ends on a table — person, assigned or refused, the server's reason
  — and the grid reloads.

---

## 6. Shifts, schedules, the profile tab

### 6.1 Shifts (`scheduling/shifts`)

- A table: code, name, start, length, end (with «اليوم التالي»), active.
- A modal to create and edit: `code` (capitals, digits, `_`; locked on edit),
  `name`, `starts_at` (`HH:MM`), `duration_minutes` (1–1440), `is_active`.
- Delete with `confirmDestructive`.
- A refused time change or delete of a shift in use shows the server's 422
  message («لا يمكن تعديل وقت وردية مستخدمة في جدول؛ أنشئ وردية جديدة.»).

### 6.2 Schedules (`scheduling/schedules`)

- A table: code, name, `kind_label`, `cycle_days`, active.
- The editor, a modal: `code`, `name`, `kind` (أسبوعي / دوري) and `cycle_days`
  (7 for weekly; 1–28 for a cycle) — the last three locked once created — then
  **one row per position**: its label, a shift (active shifts) or «راحة», and
  that shift's hours. A weekly schedule lists its rows **from Saturday**, the
  company's week, while still sending each row's own `position` (`0` Sunday …
  `6` Saturday); a cycle's rows read «اليوم 1» … «اليوم N». The label is a pure
  helper.
- Every refusal is the server's 422, shown in the modal: more than three
  back-to-back shifts, a schedule already assigned, the default schedule.

### 6.3 The employee profile's «الجدول» tab

Shown with `scheduling.view`; its buttons need `scheduling.manage`. Mounted on
enter, like the profile's other tabs.

- **Schedule periods** — `GET …/work-schedules`, newest first: schedule,
  `from_date`, `to_date` or «مستمر», `cycle_start_date`, `created_by`.
  «إسناد جدول» (`AssignScheduleModal`, one person), «إنهاء» on the open period
  (a `to_date` today or later), «حذف» on a period that has not started. With no
  period: «يتبع الجدول الافتراضي».
- **One-off changes** — `GET …/shift-changes` for a chosen month (default this
  month): date, shift or «راحة», `origin_label`, `reason`, `created_by`.
  «إزالة» on an HR change; an exchange's change links to it. «تعديل الوردية»
  opens `ShiftChangeModal`.
- **«عرض في التقويم»** — `scheduling/calendar?employee_id={id}`.

---

## 7. Exchanges and their approval

### 7.1 HR's exchanges (`scheduling/swaps`)

- A server-paged table (`DataTableServer`): requester, partner, each duty (date
  and span), `status_label`, filed at. Filters in the URL: `status`, employee
  (`EmployeePicker`, requester or partner), `from`/`to` (≤ 92 days).
- A row opens a detail modal: both duties with their shifts, the reason, and
  for a cancelled one why and when, and who once the API carries it.
- **«إلغاء التبديل»** (`scheduling.manage`): shown when `status` is `2`
  approved and the earlier duty's `starts_at` is after now — a pure helper; the
  server re-checks, and its refusal shows as it comes. Asks for `reason`
  (required), then `POST /api/scheduling/swaps/{swap}/cancel` and a reload.
- A calendar or profile link `scheduling/swaps?swap={id}` opens that exchange's
  detail directly.

### 7.2 The general approval screen (`approvals/:id`)

For any approval whose subject has no screen of its own — today `shift_swap`.

- Reads `GET /api/approvals/{id}` (the approval object, §15.1): the subject's
  `title` and `summary`, the requester, `submitted_at`, the status.
- Reuses the leave screen's `ApprovalChain`, `DecisionModal` and
  `ReassignModal`. Its buttons follow the object's `can_act` and
  `can_reassign`; after an action it redraws from the object the server
  returns. **No withdraw button**: withdrawing is the subject's own route
  (`/api/me/shift-swaps/{swap}/cancel`, the guard's, in the app), not the
  engine's, so this screen has nothing general to call.
- A 403 or 404 is the usual `StatePanel`.
- `subjectPath` sends a `shift_swap` subject here, so a row in the inbox and in
  «كل الاعتمادات» opens it.

### 7.3 Notifications

- `approval.step_pending` / `approval.step_assigned` reference **the subject**
  — the exchange's id — not the approval's, and the dashboard has no way from
  one to the other. For a `shift_swap` reference they open **the inbox filtered
  to exchanges** (`approvals?subject_type=shift_swap`), where the manager's
  pending exchange is. This needs the inbox's `subject_type` filter, which the
  API already takes (§15.2).
- `shift_swap.*` and `shift.change_*` keep the full text: their recipients are
  guards and employees, whose screens are the app's (§6's table: "the
  exchange, read-only" would need a guard's screen this piece does not build).

---

## 8. The attendance screens (D3)

- **Daily board** (`AttendanceDaily` / `DailySection`): a «الوردية» column — the
  shift's `code`, name and times on hover, «—» when `null` — and «تأخر» /
  «انصراف مبكر» in minutes, blank when zero.
- **Monthly grid** (`AttendanceMonthly`): `late_count` / `late_minutes` and
  `early_departure_count` / `early_departure_minutes` per row and in the
  totals row.
- **The employee's days** (the profile's attendance tab, `EmployeeAttendance`):
  shift, late, early departure.
- Minutes read through one pure helper: «16 د», «1 س 5 د». Labels stay the
  server's.

---

## 9. State, errors, tests

**State.** Local component state and the URL, as the attendance and leave
screens; no new Redux slice. Every successful write reloads from the server.

**Errors**, the dashboard's existing handling (`parseApiError`):

- a 422 shows its message inside the modal, which stays open with what was
  typed;
- a 403 is the `StatePanel` refusal;
- a network failure is a toast.

**Tests.**

- **Backend** (§3): feature tests first, then the whole suite and Pint.
- **Dashboard**, `node --test` on each pure helper: the calendar's filters
  (defaults, presets, the 92-day cap, a bad link), colour by source, range
  selection (same row, ordered, ≤ 31 days), the change / assignment / bulk
  bodies, schedule day labels (weekly from Saturday with the right `position`;
  cycle «اليوم N»), whether an exchange can be cancelled, `subjectPath` and the
  notification destination for `shift_swap`, minutes. Then `npm run lint` and
  `npm run build`.
- **Manual**, new cases in the dashboard's
  `docs/MANUAL_DASHBOARD_TEST_SCENARIOS.md`: set up and bulk-assign the guards'
  cycle; change a future day and a past day; remove a change; cancel an
  exchange; a manager approves one from the inbox; a refused change shows its
  reason.

---

## 10. What the API lacks (recorded, not worked around)

- **No `in_use` on a shift, no `is_default` on a schedule.** The screens cannot
  disable what the server will refuse; they show the refusal. Adding the flags
  would let the forms lock the fields ahead of time.
- **No exchange detail for an approver** (D4). The manager decides from
  `subject.summary`. Adding `details` for `shift_swap` to the inbox row and an
  approver read of the exchange, as leave has, would show each duty's shifts;
  piece 4's manager screen would use it too.
- **A notification about an approval names the subject, not the approval**
  (§7.3), so the dashboard opens the filtered inbox rather than the request.
- **`ShiftSwapResource` has no `cancelled_by`.** The detail shows a cancellation's
  reason and time but not who cancelled it (§7.1 asked for who).

---

## 11. Build order

Each step reviewable on its own.

1. **Backend** — the calendar's people filters (§3), with API doc §19.5.
2. **Dashboard groundwork** — the permissions catalog, the routes and the
   sidebar group, the option lists and display helpers (§2, §4).
3. **Shifts and schedules** (§6.1, §6.2).
4. **The calendar** — grid, `ShiftChangeModal`, bulk assignment (§5).
5. **The profile's «الجدول» tab** (§6.3).
6. **Exchanges and approval** — HR's list, the approval screen, the inbox's
   `subject_type` filter, notification routing (§7).
7. **The attendance columns** (§8).
8. **Docs** — the backend's `START_HERE.md` §10 and work order §8; the
   dashboard's docs and manual scenarios.

**Branches:** `work-shifts-dashboard` in each repository — off `main` in the
backend, off `phase_1` (its current branch) in the dashboard. Nothing is
committed or pushed without the project owner's say.

**Deploy:** the backend step has no migration. After the dashboard's step 2, an
admin adds `scheduling.view` and `scheduling.manage` to HR's role on the roles
screen.
