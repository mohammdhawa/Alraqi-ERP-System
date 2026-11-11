# Work shifts — the dashboard's screens: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** HR runs work shifts from the dashboard (shifts, schedules, an editable calendar, bulk assignment, a profile tab, exchanges) and managers decide exchanges there; the attendance screens show each row's shift and its late and early minutes.

**Architecture:** One backend task fixes the calendar API's people filters by sharing the attendance reports' filter. Everything else is a new `src/sections/scheduling/` module in the dashboard, following its existing pattern — `.jsx` screens over pure `*.js` helpers tested with `node --test` — plus small edits to the approvals, notifications and attendance screens.

**Tech Stack:** Laravel 11 / PHPUnit / Pint (backend); React 18, react-bootstrap, react-select, axios, `node --test` (dashboard). No new dependency.

**Spec:** [`docs/superpowers/specs/2026-11-10-work-shifts-dashboard-design.md`](../specs/2026-11-10-work-shifts-dashboard-design.md) — read it with this plan; "spec §n" below refers to it.

**Repositories:** Task 1 is in `alraqi-erp-system` (this repo). Tasks 2–10 are in `../erp-dashboard`; their paths are relative to that repo. Task 11 touches both.

## Global Constraints

- Branches: `work-shifts-dashboard` — off `main` in the backend, off `phase_1` in the dashboard.
- **No commit and no push without the project owner's explicit go-ahead.** Each task's last step stages its files and stops; commit only when told.
- Another agent may edit these repos at the same time: run `git status` and re-read a file before editing it.
- Every label on screen is Arabic and verbatim from the spec or the server (`*_label`, `message`). Never word a server state yourself.
- Pure helpers import nothing that touches `import.meta` (no `redux/api`), so `node --test` can load them.
- "Today" is Damascus: `todayInDamascus(now)` from `src/sections/attendance/recordFilters.js`.
- Limits: a calendar or list window ≤ **92** days; a one-off change ≤ **31** days; calendar page **25** people.
- Write controls need `scheduling.manage`; screens need `scheduling.view` (`hasPermission(permissions, …)` from `src/data/permissionsCatalog.js`).
- Requests go through `api` (`src/redux/api.js`, base `/api`); errors through `parseApiError` (`src/utils/apiHelpers.js`): a 422 shows inside the open modal, a 403/404 is `StatePanel`, a network failure is `toastNotify(message, "error")`. Every successful write reloads from the server.
- Destructive actions confirm with `confirmDestructive({ action, subject, text, confirmButtonText })`.
- Verification in the dashboard: `npm test`, `npm run lint`, `npm run build`. In the backend: `php artisan test`, `vendor/bin/pint --test`.

## Review Focus

- **A day off must be sent as `shift_id: null`**, never `""` or `0` — the server reads `null` as «يوم راحة»; anything else is a 422 or a wrong shift. Test in Task 5 (`toShiftChangeBody`) and Task 4 (`toScheduleBody`).
- **`cycle_start_date` only for a cycle schedule** — required for `kind` 2, refused for `kind` 1 («الجدول الأسبوعي لا يأخذ تاريخ بداية دورة.»). Test in Task 5 (`toAssignmentBody`, `toBulkBody`).
- **A weekly schedule shown from Saturday still sends each row's own `position`** (Saturday is `6`). A mix-up shifts every guard's week by a day. Test in Task 2 (`displayPositions`) and Task 4 (`toScheduleBody`).
- **An exchange's cancellability is judged on the EARLIER of its two duties**, which may be the partner's. Test in Task 8 (`canCancelSwap`).
- **"Past" and "today" are Damascus dates, not the browser's** — a browser at UTC late in the evening is already tomorrow in Damascus. Test in Task 2 (`isPastDate`) and Task 6 (default window).

---

### Task 1 (backend): the calendar's people filters

**Files:**
- Create: `app/Modules/HR/Requests/Concerns/FiltersPeople.php`
- Modify: `app/Modules/Attendance/Requests/Concerns/ReportsOnPeople.php` (drop `normalisePeople`, `peopleRules`; `use FiltersPeople`)
- Modify: `app/Modules/HR/Models/Employee.php` (add `scopeMatchingPeople`)
- Modify: `app/Modules/Attendance/Services/AttendancePopulation.php` (`filter()` delegates; drop `ids()`)
- Modify: `app/Modules/Scheduling/Requests/ScheduleCalendarRequest.php`
- Modify: `app/Modules/Scheduling/Controllers/ScheduleCalendarController.php`
- Test: `tests/Feature/Scheduling/ScheduleCalendarTest.php`
- Docs: `docs/API_DOCUMENTATION.md` §19.5, `docs/START_HERE.md` §2 and §10

**Interfaces:**
- Produces: `trait FiltersPeople { protected function normalisePeople(): void; protected function peopleRules(): array; }` — moved verbatim from `ReportsOnPeople` (CSV-or-array `employee_ids`/`department_ids`; rules `employee_id`, `employee_ids.*`, `department_id`, `department_ids.*` as `integer|min:1`, `search` nullable string ≤ 100).
- Produces: `Employee::scopeMatchingPeople(Builder $query, array $filters): void` — the body of today's `AttendancePopulation::filter()` (ids, units via `Department::subtreeIds()`, `search` via `SearchTerm::orWhere` on `first_name`, `middle_name`, `last_name`, `employee_number`).
- `AttendancePopulation::filter(Builder $employees, array $filters): Builder` keeps its signature and returns `$employees->matchingPeople($filters)`, so its five callers do not change.
- The calendar request: `prepareForValidation()` calls `normalisePeople()`; `rules()` = `from`, `to`, `per_page` as today + `peopleRules()` (the old `Rule::exists` on `department_id`/`employee_id` goes — an unknown id narrows to nobody, as on the reports).
- The controller: `Employee::query()->employed()->matchingPeople($request->validated())->orderBy('first_name')->orderBy('id')->paginate(...)`.

- [ ] **Step 1: Branch.** `git switch -c work-shifts-dashboard main`; `git status` is clean apart from the spec and this plan.
- [ ] **Step 2: Write the failing tests** in `ScheduleCalendarTest` (build units with `Department::create` and `DepartmentLevel::cases()[i]->value`, as `tests/Feature/Attendance/Concerns/AttendanceTree.php` does):
  - `test_a_person_who_left_is_not_listed` — an employee with `status => EmployeeStatus::Terminated` and one active; `?from=2026-11-14&to=2026-11-15` → `meta.total` 1, `data.0.employee.id` the active one.
  - `test_a_unit_filter_includes_its_sub_units` — division A, section A1 under it, B beside; one person each; `department_id={A}` → the A and A1 people, not B's (`meta.total` 2).
  - `test_unit_and_person_lists_take_csv_or_an_array` — `department_ids={A1},{B}` and `department_ids[]=…` give the same two people; `employee_ids=` likewise.
  - `test_search_by_name_part_or_staff_number` — `Employee::factory()->named('سامر خالد الحلبي')`; `search=خالد` and `search={its employee_number}` each list exactly that person.
  - `test_an_unknown_unit_narrows_to_nobody` — `department_id=999999` → 200, `meta.total` 0.
- [ ] **Step 3: Run them to see them fail.** `php artisan test --filter=ScheduleCalendarTest` → the five new tests FAIL (leaver listed, sub-unit missing, unknown unit 422, search ignored); the three existing ones pass.
- [ ] **Step 4: Implement** the trait, the scope, the delegation, the request and the controller as in Interfaces.
- [ ] **Step 5: Run the tests.** `php artisan test --filter=ScheduleCalendarTest` → all PASS. Then `php artisan test` → whole suite green (the attendance and leave reports prove the move kept their behaviour); `vendor/bin/pint --test` clean.
- [ ] **Step 6: Docs.** API doc §19.5: employed people only; the person filters of §14 ("The person filters"); an unknown id is an empty page, no longer a 422. START_HERE §2 (branch, test count) and §10 item 0 (piece 3 started).
- [ ] **Step 7: Stage** the files above; commit only with the owner's go-ahead.

---

### Task 2: dashboard groundwork — permissions, display helpers, options

**Files:**
- Modify: `src/data/permissionsCatalog.js`, `src/data/permissionsCatalog.test.js`
- Create: `src/sections/scheduling/shiftDisplay.js`, `src/sections/scheduling/shiftDisplay.test.js`
- Create: `src/sections/scheduling/schedulingDates.js`, `src/sections/scheduling/schedulingDates.test.js`
- Create: `src/sections/scheduling/useSchedulingOptions.js`

**Interfaces:**
- Produces (`schedulingDates.js`, pure): `isPastDate(date: "YYYY-MM-DD", now = new Date()) -> boolean` — `date < todayInDamascus(now)`.
- Produces (`shiftDisplay.js`, pure):
  - `WEEK_ORDER = [6, 0, 1, 2, 3, 4, 5]` — Saturday first.
  - `shiftSpan(shift: {starts_at: "HH:MM", duration_minutes, ends_next_day}) -> string` — `"09:00 – 16:00"`; with `ends_next_day` `"22:00 – 06:00 (اليوم التالي)"`.
  - `displayPositions(kind: 1|2, cycleDays: number) -> number[]` — weekly `WEEK_ORDER`; cycle `[0 … cycleDays−1]`.
  - `scheduleDayLabel(kind, position) -> string` — weekly `WEEKDAY_NAMES[position]` (import from `src/sections/attendance/employeeGridFilters.js`); cycle `` `اليوم ${position + 1}` ``.
  - `calendarCellTitle(shift: {name, starts_at: ISO, ends_at: ISO} | null) -> string` — `"مناوبة حراسة · 08:00 – 08:00"` (the `HH:MM` of each ISO string, which already carries `+03:00`); `null` → `"راحة"`.
- Produces (`useSchedulingOptions.js`): `useSchedulingOptions(enabled: boolean) -> { shifts, schedules, loading, error, reload }` — `useResourceOptions("/scheduling/shifts", …)` and `useResourceOptions("/scheduling/schedules", …)` (`src/sections/departments/useResourceOptions.jsx`), each option `{ value: id, label: \`${code} — ${name}\`, item }`, **active ones only** (`item.is_active`).

- [ ] **Step 1: Branch.** In `../erp-dashboard`: `git switch -c work-shifts-dashboard phase_1`.
- [ ] **Step 2: Write the failing tests.**
  - `permissionsCatalog.test.js`: `ALL_PERMISSION_NAMES.length` is **48**; `permissionLabel("scheduling.view") === "عرض الورديات والجداول"`, `permissionLabel("scheduling.manage") === "إدارة الورديات والجداول"`; the company-only list gains `"scheduling.manage"` and `"scheduling.view"` (in sorted place).
  - `shiftDisplay.test.js`: `shiftSpan({starts_at:"09:00",duration_minutes:420,ends_next_day:false})` → `"09:00 – 16:00"`; `{starts_at:"22:00",duration_minutes:480,ends_next_day:true}` → `"22:00 – 06:00 (اليوم التالي)"`; `{starts_at:"08:00",duration_minutes:1440,ends_next_day:true}` → `"08:00 – 08:00 (اليوم التالي)"`; `displayPositions(1, 7)` deep-equals `[6,0,1,2,3,4,5]`; `displayPositions(2, 6)` → `[0,1,2,3,4,5]`; `scheduleDayLabel(1, 6) === "السبت"`, `scheduleDayLabel(1, 5) === "الجمعة"`, `scheduleDayLabel(2, 0) === "اليوم 1"`; `calendarCellTitle(null) === "راحة"`.
  - `schedulingDates.test.js`: `isPastDate("2026-11-14", new Date("2026-11-14T21:30:00Z"))` → `true` (Damascus is already the 15th); `isPastDate("2026-11-15", same)` → `false`; `isPastDate("2026-11-14", new Date("2026-11-14T09:00:00Z"))` → `false`.
- [ ] **Step 3: Run to see them fail.** `npm test` → the new assertions FAIL.
- [ ] **Step 4: Implement.** Catalog group `{ module: "الورديات والجداول", key: "scheduling", permissions: [view, manage] }` after the attendance group; **not** added to `HONOURED_FROM_SCOPED_GRANT` (both are company-only). Then `shiftDisplay.js` and `useSchedulingOptions.js` as in Interfaces.
- [ ] **Step 5: Run.** `npm test` PASS; `npm run lint` clean.
- [ ] **Step 6: Stage**; commit only with the owner's go-ahead.

---

### Task 3: shifts screen, the sidebar group and its routes

**Files:**
- Create: `src/sections/scheduling/schedulingPayload.js`, `src/sections/scheduling/schedulingPayload.test.js`
- Create: `src/sections/scheduling/Shifts.jsx`, `src/sections/scheduling/ShiftFormModal.jsx`
- Modify: `src/data/Routingdata.jsx` (lazy import; route `scheduling/shifts`, next free `id`, `requiredPermissions: ["scheduling.view"]`)
- Modify: `src/data/Sidemenudata.jsx` (group «الورديات والجداول» after «الحضور», `requiredPermissions: []`, icon `ri-calendar-line`; child «الورديات» → `scheduling/shifts`, `["scheduling.view"]` — same object shape as the attendance children)

**Interfaces:**
- Produces (`schedulingPayload.js`): `toShiftBody(form: {code, name, starts_at, duration_minutes, is_active}, { editing: boolean }) -> object` — trims text, `duration_minutes` as a Number, `is_active` boolean; **omits `code` when editing** (PATCH refuses it). Later tasks add their body builders to this same file.
- Consumes: `shiftSpan` (Task 2).

- [ ] **Step 1: Write the failing test** `toShiftBody`: create → all five keys, `duration_minutes: 480` from `"480"`; editing → no `code` key; `name: "  ليلي "` → `"ليلي"`.
- [ ] **Step 2: Run** `npm test` → FAIL.
- [ ] **Step 3: Implement** `toShiftBody`; then `Shifts.jsx` — `GET /scheduling/shifts` (a plain list, no paging) into a react-bootstrap `Table`: «الرمز», «الاسم», «البداية», «المدة» (minutes), «النهاية» (`shiftSpan`), «الحالة» («مفعّلة» / «موقوفة»). With `scheduling.manage`: «إضافة وردية», per row «تعديل» and «حذف» (`confirmDestructive({ action: "حذف الوردية", subject: shift.name, text: "تُحذف الوردية ولا يمكن استخدامها في جدول جديد." })` then `DELETE /scheduling/shifts/{id}`). `ShiftFormModal` — fields per spec §6.1, `code` disabled when editing, `POST`/`PATCH`; a 422 keeps the modal open and shows `message` (and `errors[field][0]` under the field). Route and sidebar entries as in Files.
- [ ] **Step 4: Run** `npm test`, `npm run lint`, `npm run build` → all pass.
- [ ] **Step 5: Check in the browser** (dev server, a user with both permissions): list shows `DAY` and `GUARD`; create `NIGHT` 22:00/480 → its end reads «06:00 (اليوم التالي)»; editing `DAY`'s start shows «لا يمكن تعديل وقت وردية مستخدمة في جدول؛ أنشئ وردية جديدة.»; a user with only `scheduling.view` sees no buttons.
- [ ] **Step 6: Stage**; commit only with the owner's go-ahead.

---

### Task 4: schedules screen

**Files:**
- Modify: `src/sections/scheduling/schedulingPayload.js`, `schedulingPayload.test.js`
- Create: `src/sections/scheduling/Schedules.jsx`, `src/sections/scheduling/ScheduleFormModal.jsx`
- Modify: `src/data/Routingdata.jsx` (route `scheduling/schedules`), `src/data/Sidemenudata.jsx` (child «الجداول»)

**Interfaces:**
- Produces: `toScheduleBody(form: {code, name, kind, cycle_days, is_active, days: {[position]: shiftId | ""}}, { editing }) -> object` — `days` is `[{position, shift_id}]` for **every** position `0 … cycle_days−1`, ascending, `shift_id` a Number or **`null`** for `""`; `kind` and `cycle_days` Numbers; **editing omits `code`, `kind`, `cycle_days`**.
- Consumes: `displayPositions`, `scheduleDayLabel`, `shiftSpan`, `useSchedulingOptions` (Task 2).

- [ ] **Step 1: Write the failing tests** `toScheduleBody`:
  - weekly, `days = {0:"1",1:"1",2:"1",3:"1",4:"1",5:"",6:"1"}` → `days[5]` is `{position: 5, shift_id: null}`, `days[6]` is `{position: 6, shift_id: 1}`, length 7, ascending.
  - a form whose `days` was filled in Saturday-first display order still yields ascending positions with each shift on its own position.
  - cycle of 6 with positions 3–5 `""` → three `null`s; editing → no `code`/`kind`/`cycle_days`.
- [ ] **Step 2: Run** `npm test` → FAIL.
- [ ] **Step 3: Implement** `toScheduleBody`; `Schedules.jsx` — table «الرمز», «الاسم», «النوع» (`kind_label`), «المدة» (`cycle_days` + « يوماً»), «الحالة»; manage buttons as Task 3 (delete: `action: "حذف الجدول"`). `ScheduleFormModal` — `kind` select (`1` «أسبوعي», `2` «دوري»), `cycle_days` (fixed at 7 and hidden for weekly; 1–28 for a cycle), both locked when editing; then one row per `displayPositions(kind, cycle_days)`: `scheduleDayLabel`, a select of active shifts plus «راحة» (value `""`), and the chosen shift's `shiftSpan`. Editing pre-fills `days` from the schedule object (`shift?.id ?? ""`).
- [ ] **Step 4: Run** `npm test`, `npm run lint`, `npm run build`.
- [ ] **Step 5: Check in the browser:** `OFFICE` opens with Saturday first and Friday «راحة»; saving it shows «لا يمكن حذف الجدول الافتراضي أو إيقافه أو تعديل أيامه.»; a new 6-day cycle with four `GUARD` days in a row shows «لا يمكن أن تتصل أكثر من ثلاث ورديات متتالية بلا فاصل.».
- [ ] **Step 6: Stage**; commit only with the owner's go-ahead.

---

### Task 5: the shared modals — one-off change and schedule assignment

**Files:**
- Modify: `src/sections/scheduling/schedulingPayload.js`, `schedulingPayload.test.js`
- Create: `src/sections/scheduling/ShiftChangeModal.jsx`, `src/sections/scheduling/AssignScheduleModal.jsx`

**Interfaces:**
- Produces (pure):
  - `toShiftChangeBody({ from, to, shiftId, reason }) -> { from, to, shift_id: number | null, reason }` — `shiftId === ""` → `null`; `reason` trimmed.
  - `toAssignmentBody({ scheduleId, fromDate, cycleStartDate, kind }) -> { work_schedule_id, from_date, cycle_start_date? }` — `cycle_start_date` present **only** when `kind === 2`.
  - `toBulkBody({ scheduleId, fromDate, cycleStartDate, kind, who: "people" | "unit", employeeIds: number[], departmentId }) -> object | null` — the assignment fields plus `employee_ids` (people) or `department_id` + `include_subtree: true` (unit); `null` when `who === "unit"` with no `departmentId`, or `"people"` with an empty list.
- Produces (components):
  - `<ShiftChangeModal show employee={{id, full_name}} from to onHide onSaved />` — reads `GET /hr/employees/{id}/shift-changes?from&to` on open; lists existing rows (date, shift name or «راحة», `origin_label`, `reason`); an `origin` 1 row has «إزالة» (`confirmDestructive({ action: "إزالة التعديل", subject: date, text: "يعود الموظف إلى جدوله في هذا اليوم.", confirmButtonText: "إزالة" })` → `DELETE …/shift-changes/{change}` → `onSaved()`); an `origin` 2 row links to `scheduling/swaps?swap={shift_swap_id}` instead. Form: shift select (a placeholder «اختر الوردية…» = `""` first, then active shifts, then «يوم راحة» = `"off"`; `toShiftChangeBody` maps `"off"` to `shift_id: null`; save stays disabled until a choice; a failed shifts load shows the error with «إعادة المحاولة» and blocks save) (amended after the final review, 2026-11-10), `reason` (required), and when `isPastDate(from, now)` the note «تعديل يوم مضى يعيد مطابقة تسجيلات الحضور في الأيام المعدّلة، ولا يُبلَّغ الموظف به.». Submit `POST …/shift-changes` with `toShiftChangeBody` → toast `message`, `onSaved()`. `readOnly` prop (an exchange's day): no form, only the list and the link.
  - `<AssignScheduleModal show mode="one" | "bulk" employee? unitFilter?={id, name} selectedIds?={number[]} onHide onSaved />` — schedule select (active), `from_date` (min today), `cycle_start_date` shown and required only when the chosen schedule's `kind === 2`. `"one"` → `POST /hr/employees/{id}/work-schedules`. `"bulk"` → radio «الموظفون المحددون (N)» / «كل موظفي {unitFilter.name} ووحداته» (the second only with a `unitFilter`), `POST /scheduling/assignments/bulk` with `toBulkBody`; then the modal shows a result table — «الموظف» (name looked up from the page rows passed in, else the id), «النتيجة» («أُسند» / «رُفض»), «السبب» (`reason`) — and a «إغلاق» that calls `onSaved()`.
- Consumes: `isPastDate`, `useSchedulingOptions` (Task 2).

- [ ] **Step 1: Write the failing tests:**
  - `toShiftChangeBody({from:"2026-11-14",to:"2026-11-15",shiftId:"",reason:" تكليف "})` deep-equals `{from:"2026-11-14",to:"2026-11-15",shift_id:null,reason:"تكليف"}`; `shiftId:"3"` → `shift_id: 3`.
  - `toAssignmentBody({...,kind:1,cycleStartDate:"2026-11-14"})` has **no** `cycle_start_date` key; `kind:2` has it.
  - `toBulkBody({who:"unit",departmentId:""})` → `null`; `{who:"people",employeeIds:[]}` → `null`; `{who:"unit",departmentId:"9"}` → `department_id: 9, include_subtree: true`, no `employee_ids`; `{who:"people",employeeIds:[31,32]}` → `employee_ids: [31,32]`, no `department_id`.
- [ ] **Step 2: Run** `npm test` → FAIL.
- [ ] **Step 3: Implement** the three builders, then the two modals as in Interfaces.
- [ ] **Step 4: Run** `npm test`, `npm run lint`.
- [ ] **Step 5: Stage**; commit only with the owner's go-ahead. (The modals are exercised in the browser in Tasks 6 and 7.)

---

### Task 6: the calendar

**Files:**
- Create: `src/sections/scheduling/calendarFilters.js`, `calendarFilters.test.js`
- Create: `src/sections/scheduling/calendarCells.js`, `calendarCells.test.js`
- Create: `src/sections/scheduling/ScheduleCalendar.jsx` (filters, paging, modals), `src/sections/scheduling/CalendarGrid.jsx` (the table)
- Modify: `src/index.scss` — the grid's sticky first column, `overflow-x: auto` on its wrapper, and the source classes (light grey, amber, blue with a legend), RTL-correct (`inset-inline-start`)
- Modify: `src/data/Routingdata.jsx` (route `scheduling/calendar`), `src/data/Sidemenudata.jsx` (child «التقويم», **first** in the group)

**Interfaces:**
- Produces (`calendarFilters.js`):
  - `CALENDAR_DEFAULT_DAYS = 14`, `CALENDAR_PER_PAGE = 25`, `MAX_WINDOW_DAYS = 92`.
  - `defaultCalendarFilters(now) -> { from, to, department_id: "", employee_id: "", search: "" }` — today (Damascus) through today + 13.
  - `presetWindow("week" | "month", now) -> { from, to }` — the Saturday-to-Friday week holding today; the calendar month holding today.
  - `calendarFiltersFromSearch(get, defaults)` — `from`/`to` taken **together** only when both are real dates, `from ≤ to`, ≤ 92 days, else both from `defaults`; `department_id`/`employee_id` via `isPositiveId`; `search` capped with `MAX_SEARCH`.
  - `toCalendarParams(f, page) -> { from, to, page, per_page: 25, department_id?, employee_id?, search? }` — empty values omitted.
  - `windowDates(from, to) -> string[]` — every date, inclusive.
- Produces (`calendarCells.js`):
  - `sourceClass(source) -> string` — `default` → `"sched-default"`, `assignment` → `""`, `change` → `"sched-change"`, `swap` → `"sched-swap"`, anything else → `""`.
  - `MAX_CHANGE_DAYS = 31`; `rangeBetween(anchor: {employeeId, date}, target: {employeeId, date}) -> { from, to } | { error }` — ordered; another employee → `{ error: "اختر الأيام من صف الموظف نفسه." }`; more than 31 days → `{ error: "لا يتجاوز تعديل الوردية 31 يوماً." }`.
- Consumes: `calendarCellTitle` (Task 2), `ShiftChangeModal`, `AssignScheduleModal` (Task 5).

- [ ] **Step 1: Write the failing tests:**
  - `defaultCalendarFilters(new Date("2026-11-14T21:30:00Z"))` → `from: "2026-11-15"` (Damascus is already the 15th), `to: "2026-11-28"`.
  - `presetWindow("week", new Date("2026-11-18T09:00:00Z"))` (a Wednesday) → `{from:"2026-11-14", to:"2026-11-20"}`; `"month"` → `{from:"2026-11-01", to:"2026-11-30"}`.
  - from search: a 93-day window, an inverted one, and `from=2026-02-30` each fall back to the defaults' `from` **and** `to`; `department_id=0` dropped; a 150-character `search` cut to 100.
  - `toCalendarParams({...defaults, search:""}, 2)` has no `search`, `per_page: 25`, `page: 2`.
  - `windowDates("2026-11-29","2026-12-02")` → four dates across the month.
  - `sourceClass("swap") === "sched-swap"`, `sourceClass("x") === ""`.
  - `rangeBetween({employeeId:1,date:"2026-11-20"},{employeeId:1,date:"2026-11-14"})` → `{from:"2026-11-14", to:"2026-11-20"}`; another employee → the error; 2026-11-01 to 2026-12-02 (32 days) → the 31-day error; 2026-11-01 to 2026-12-01 (31 days) → a range.
- [ ] **Step 2: Run** `npm test` → FAIL.
- [ ] **Step 3: Implement** both helpers.
- [ ] **Step 4: Implement the screen** (spec §5): the filter bar (two dates, the «هذا الأسبوع» / «هذا الشهر» presets, the department picker as `DailyFilterBar` builds it with `useResourceOptions("/departments", departmentToOption, …)`, a search box), synced to the URL with `useSearchParams`; `GET /scheduling/calendar` with `toCalendarParams`; `TablePaginationServer` under the grid. `CalendarGrid`: a header row of `windowDates` (weekday from `WEEKDAY_NAMES`, day number; today's column gets a class); one row per person — a checkbox (manage only), name + staff number (sticky), then a `<button>` per day with `sourceClass(day.source)`, text `day.shift?.code ?? "راحة"`, `title={calendarCellTitle(day.shift)}`, a «⁺¹» marker when the shift's `ends_at` date is after `day.date`. A legend above the grid. Click (manage): open `ShiftChangeModal` for that day (`readOnly` when `source === "swap"`); shift-click with an anchor: `rangeBetween` → the modal for the range, or `toastNotify(error, "error")`. A view-only user's cells are not buttons (plain cells with the same title). «إسناد جدول» (manage) opens `AssignScheduleModal` in `"bulk"` mode with the ticked ids and the filtered unit. After any `onSaved`, reload the page.
- [ ] **Step 5: Run** `npm test`, `npm run lint`, `npm run build`.
- [ ] **Step 6: Check in the browser:** a guard with `GUARD` from today shows three `GUARD` days, three «راحة», coloured as a schedule; record a night shift on one office worker's day → the cell turns amber; shift-click across 5 days → one change of 5 days; shift-click into another row → the error toast; a past day shows the re-matching note; bulk-assign `GUARD` to two ticked people with one refused → the result table names the refusal; at 375 px wide the page does not scroll sideways, the grid does.
- [ ] **Step 7: Stage**; commit only with the owner's go-ahead.

---

### Task 7: the employee profile's «الجدول» tab

**Files:**
- Create: `src/sections/scheduling/EmployeeSchedule.jsx`
- Modify: `src/sections/hr/EmployeeProfile.jsx` (a tab `{ key: "schedule", title: "الجدول", show: canViewScheduling }` after «الحضور»; a `Tab.Pane eventKey="schedule" mountOnEnter`)

**Interfaces:**
- Produces: `<EmployeeSchedule employee={current} />`.
- Consumes: `ShiftChangeModal`, `AssignScheduleModal` (Task 5); `monthBoundsInDamascus` (`src/sections/attendance/employeeGridFilters.js`).

- [ ] **Step 1: Implement** (spec §6.3). Card «فترات الجدول»: `GET /hr/employees/{id}/work-schedules` → «الجدول», «من», «إلى» (`to_date` or «مستمر»), «بداية الدورة», «بواسطة»; empty → «يتبع الجدول الافتراضي». Manage: «إسناد جدول» (`AssignScheduleModal mode="one"`); «إنهاء» on the row with `to_date === null` (a small form: `to_date`, min today → `PATCH …/work-schedules/{id}` `{ to_date }`); «حذف» on a row with `from_date` after today (Damascus) (`confirmDestructive({ action: "حذف فترة الجدول", subject: schedule name, text: "تُحذف الفترة التي لم تبدأ، وتعود الفترة السابقة مفتوحة." })` → `DELETE`). Card «تعديلات الوردية»: a month picker (default `monthBoundsInDamascus()`), `GET …/shift-changes?from&to` → «التاريخ», «الوردية» (name or «راحة»), «المصدر» (`origin_label`), «السبب», «بواسطة»; «إزالة» on `origin` 1, a link on `origin` 2; «تعديل الوردية» opens `ShiftChangeModal` with `editableRange` (from/to date inputs, default today, checked with `rangeBetween`) (amended after the final review, 2026-11-10; it was from = to = today). A link «عرض في التقويم» → `scheduling/calendar?employee_id={id}`.
- [ ] **Step 2: Run** `npm run lint`, `npm run build`, `npm test`.
- [ ] **Step 3: Check in the browser:** the tab hides without `scheduling.view`; assign `GUARD` from tomorrow → a row from tomorrow, the previous span closed today; delete it → gone, the previous span open again; a change recorded here shows in the month list and on the calendar.
- [ ] **Step 4: Stage**; commit only with the owner's go-ahead.

---

### Task 8: HR's exchanges

**Files:**
- Create: `src/sections/scheduling/swapView.js`, `swapView.test.js`
- Create: `src/sections/scheduling/ShiftSwaps.jsx`, `src/sections/scheduling/SwapDetailModal.jsx`
- Modify: `src/data/Routingdata.jsx` (route `scheduling/swaps`), `src/data/Sidemenudata.jsx` (child «تبديل المناوبات»)

**Interfaces:**
- Produces (`swapView.js`):
  - `SWAP_STATUSES = ["1","2","3","4","5"]` (labels come from `status_label`; the filter's option text: «قيد الاعتماد», «معتمد», «مرفوض», «مسحوب», «ملغى من الموارد البشرية»).
  - `canCancelSwap(swap, now = new Date()) -> boolean` — `swap.status === 2` and the **earlier** of `requester_duty.starts_at` / `partner_duty.starts_at` is after `now`.
  - `defaultSwapFilters() -> { status: "", employee_id: "", from: "", to: "" }`; `swapFiltersFromSearch(get, defaults)` — `status` in `SWAP_STATUSES`, `employee_id` positive, `from`/`to` real dates (dropped together when inverted or > 92 days).
  - `toSwapParams(f, page, perPage) -> object` — empty values omitted.
- Produces: `<SwapDetailModal swap show onHide onCancelled />` — both duties (date, `starts_at`–`ends_at`, each shift), reason, `status_label`, and for status 5 `cancel_reason` and `cancelled_at`; «إلغاء التبديل» when `canCancelSwap` and `scheduling.manage` → a required «سبب الإلغاء» → `POST /scheduling/swaps/{id}/cancel` `{ reason }`; a 422 shows its message in place.

- [ ] **Step 1: Write the failing tests:** `canCancelSwap` — approved, requester duty 2026-11-20T08:00+03:00, partner duty 2026-11-17T08:00+03:00, `now` 2026-11-18T00:00+03:00 → **false** (the partner's duty, the earlier one, has started); `now` 2026-11-16 → true; status 1 → false. `swapFiltersFromSearch`: `status=9` dropped; `from` after `to` drops both. `toSwapParams` omits empty values.
- [ ] **Step 2: Run** `npm test` → FAIL.
- [ ] **Step 3: Implement** `swapView.js`; `ShiftSwaps.jsx` — `DataTableServer` over `GET /scheduling/swaps` with `toSwapParams`: «مقدّم الطلب», «الزميل», «مناوبته» and «مناوبة الزميل» (date + span), «الحالة», «تاريخ التقديم»; filters (status select, `EmployeePicker`, two dates) in the URL; `onRowClick` opens `SwapDetailModal`; `?swap={id}` on load opens `GET /scheduling/swaps/{id}` in the modal.
- [ ] **Step 4: Run** `npm test`, `npm run lint`, `npm run build`.
- [ ] **Step 5: Check in the browser** with an approved exchange seeded through the API (two guards, one manager): it lists; its detail cancels with a reason; it then reads «ملغى من الموارد البشرية» and both guards' days on the calendar are back to their schedule.
- [ ] **Step 6: Stage**; commit only with the owner's go-ahead.

---

### Task 9: the approval screen, the inbox filter and notification routing

**Files:**
- Create: `src/sections/approvals/ApprovalRequest.jsx`
- Modify: `src/sections/approvals/approvalView.js`, `approvalView.test.js` (`subjectPath`)
- Modify: `src/sections/approvals/ApprovalsInbox.jsx`, `ApprovalsList.jsx` (pass the approval id; the type filter)
- Modify: `src/sections/approvals/approvalFilters.js`, `approvalFilters.test.js`, `src/redux/actions/approvalsParams.js`, `approvalsParams.test.js` (`subject_type`)
- Modify: `src/sections/approvals/ApprovalFilterBar.jsx` (a «نوع الطلب» select: «الكل», «إجازة» `leave_request`, «تبديل مناوبة» `shift_swap`)
- Modify: `src/data/notificationDestinations.js`, `notificationDestinations.test.js`
- Modify: `src/data/Routingdata.jsx` (route `approvals/:id`, `requiredPermissions: []` — admission is the server's)

**Interfaces:**
- Changes: `subjectPath(subject, approvalId) -> string | null` — `leave_request` → `leave/requests/{subject.id}` (unchanged); `shift_swap` → `approvals/{approvalId}` (null without an id); anything else → null. The inbox passes `row.approval_id`, the list `row.id`.
- Adds: `SUBJECT_TYPES = ["leave_request", "shift_swap"]` in `approvalsParams.js`; `subject_type` in `defaultApprovalFilters()` (`""`), read back only when in `SUBJECT_TYPES`, sent by `addFilters` only when valid.
- Adds to `BY_TYPE`: `approval.step_pending` and `approval.step_assigned` resolve a `leave_request` reference as today and a `shift_swap` reference to `"approvals?subject_type=shift_swap"`.
- Produces: `<ApprovalRequest />` at `approvals/:id` — `GET /approvals/{id}`; title `subject.title`, `subject.summary`, the requester (name, staff number), `submitted_at` (`formatAbsolute`), `status_label`; `ApprovalChain approval={approval}`; «اعتماد» / «رفض» when `can_act` (`DecisionModal`, then `POST /approvals/{id}/steps/{current_step_id}/{approve|reject}`), «إعادة إسناد» when `reassignableSteps(approval).length` (`ReassignModal`, `PATCH …/steps/{stepId}/assignee`) — the leave screen's `decide`/`reassign` handlers as the model; redraw from `response.data.data`; on 403/404/422 (`refreshAfterRefusal`) toast the message and re-read. **No withdraw** (spec §7.2).

- [ ] **Step 1: Write the failing tests:** `subjectPath({type:"shift_swap",id:5}, 17) === "approvals/17"`; `subjectPath({type:"shift_swap",id:5}) === null`; the leave case unchanged. `approvalFiltersFromSearch` reads `subject_type=shift_swap`, drops `subject_type=memo`. `buildInboxParams("pending",1,15,{subject_type:"shift_swap"})` includes `subject_type: "shift_swap"`; `{subject_type:"x"}` omits it. `destinationOf({type:"approval.step_pending", reference:{type:"shift_swap", id:5}}) === "approvals?subject_type=shift_swap"`; a `leave_request` reference still gives `leave/requests/{id}`; `shift_swap.approved` stays `null`.
- [ ] **Step 2: Run** `npm test` → FAIL.
- [ ] **Step 3: Implement** the helpers, the filter bar's select, the two list call sites, and `ApprovalRequest.jsx`. The inbox screen reads `subject_type` from the URL through `approvalFiltersFromSearch`, so the notification's link arrives filtered.
- [ ] **Step 4: Run** `npm test`, `npm run lint`, `npm run build`.
- [ ] **Step 5: Check in the browser** as the guards' manager with a pending exchange: the inbox row opens `approvals/{id}`; approve → the chain shows the decision and the calendar shows both guards' exchanged days in blue; the bell's «طلب تبديل مناوبة جديد» opens the inbox filtered to exchanges; a leave row still opens the leave screen.
- [ ] **Step 6: Stage**; commit only with the owner's go-ahead.

---

### Task 10: the attendance columns

**Files:**
- Create: `src/sections/attendance/minutesText.js`, `minutesText.test.js`
- Modify: `src/sections/attendance/DailySection.jsx` (the attendance table at lines ~144–180)
- Modify: `src/sections/attendance/AttendanceMonthly.jsx` (the column list at lines ~42–50 and the totals row)
- Modify: `src/sections/attendance/EmployeeAttendance.jsx` (the days table at lines ~379–425)

**Interfaces:**
- Produces: `formatMinutes(n) -> string` — `0`, `null`, `undefined` → `""`; `16` → `"16 د"`; `60` → `"1 س"`; `65` → `"1 س 5 د"`; `130` → `"2 س 10 د"`.
- Consumes: `calendarCellTitle` (Task 2) for the shift cell's `title`.

- [ ] **Step 1: Write the failing test** for `formatMinutes` with exactly the values above.
- [ ] **Step 2: Run** `npm test` → FAIL.
- [ ] **Step 3: Implement** `formatMinutes`; then the columns (spec §8): daily board — «الوردية» (`row.shift?.code` with `title={calendarCellTitle(row.shift)}`, «—» when `null`) before «الحضور», «تأخر» and «انصراف مبكر» (`formatMinutes`) after «ساعات العمل»; monthly — four entries `late_count` «مرات التأخر», `late_minutes` «دقائق التأخر», `early_departure_count` «مرات الانصراف المبكر», `early_departure_minutes` «دقائق الانصراف المبكر» (minutes cells through `formatMinutes`), totals from `totals`; employee days — «الوردية», «تأخر», «انصراف مبكر».
- [ ] **Step 4: Run** `npm test`, `npm run lint`, `npm run build`.
- [ ] **Step 5: Check in the browser:** a check-in at 09:20 under `OFFICE` reads «الوردية: DAY», «تأخر: 20 د» on the daily board and adds 1 / 20 to the monthly row and totals.
- [ ] **Step 6: Stage**; commit only with the owner's go-ahead.

---

### Task 11: docs and the whole-branch check

**Files:**
- Modify (backend): `docs/START_HERE.md` §2 and §10 item 0 (piece 3 built; next piece 4), `docs/EMPLOYEE_APP_V1_WORK_ORDER.md` §8 (a dated entry: what piece 3 built, the backend change, the deploy note).
- Modify (dashboard): `docs/MANUAL_DASHBOARD_TEST_SCENARIOS.md` (spec §9's six manual cases, numbered after the last existing one); `API_DOCUMENTATION.md` at the dashboard root only if it mirrors the backend's — check it first and copy §19 over if it does.

- [ ] **Step 1: Write the docs** above. The deploy note: backend has no migration; after deploy an admin adds `scheduling.view` and `scheduling.manage` to HR's role on the roles screen.
- [ ] **Step 2: Whole-branch verification.** Backend: `php artisan test` (all green, record the count), `vendor/bin/pint --test`. Dashboard: `npm test`, `npm run lint`, `npm run build` — all pass; record the outputs in the work-order entry.
- [ ] **Step 3: Walk the spec's success list** (spec §1) in the browser end to end, once, as HR and as the guards' manager.
- [ ] **Step 4: Stage** both repos' changes and report; commit, push and open pull requests only with the owner's go-ahead.
