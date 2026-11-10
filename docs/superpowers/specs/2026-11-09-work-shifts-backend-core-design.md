# Work shifts — backend core: design

> **Status:** design, agreed section by section on 2026-11-09. Not built.
> **Piece 1 of 4.** Work shifts is built as four pieces, each with its own spec,
> plan and build: **(1) this one, the backend core**; (2) one-off changes and
> guard swaps on the approvals engine; (3) the dashboard's screens; (4) the app.
> **The decisions it builds on:** [`DECISION_ROUND_WORK_SHIFTS.md`](../../DECISION_ROUND_WORK_SHIFTS.md),
> closed 2026-11-09, and what each answer changes in
> [`EMPLOYEE_APP_V1_WORK_ORDER.md`](../../EMPLOYEE_APP_V1_WORK_ORDER.md) §8
> (entry "2026-11-09 — work shifts: the decision round, answered").

---

## 1. Intent

**What it is for.** Today an attendance day is a calendar date: one check-in per
date, the check-out on the same date, one reminder at 17:00 and one close at
23:59 for everyone. That model cannot record a night shift or a guard's 72-hour
duty — legacy has the same model, and 41% of the guards' check-ins there end as
missing check-outs because their duties cross midnight. This piece makes the
attendance day **the shift**, and gives each person a schedule.

**Who it is for.** HR, who define schedules and read the reports; every employee,
whose day is now judged against their own shift; the guards above all.

**What success looks like.**

- A guard records a 72-hour duty with one check-in and one check-out, and every
  report shows three correct 24-hour shifts.
- Office staff see late and early-departure minutes against 09:00–16:00, with 15
  minutes' grace, and no penalty.
- Leave never charges a Friday off or a guard's rest day.
- History does not move when a schedule changes later.
- Nothing in the dashboard or the app breaks before pieces 3 and 4 adopt the new
  fields: every change to the contract is an added field, except the one in §6.4.

### 1.1 The decisions, in brief

| | Decided (round of 2026-11-09) |
|---|---|
| Patterns | Fixed day hours, fixed night hours, the guards' cycle. **No** rotating, flexible or dated hours |
| Default | **09:00–16:00, Saturday to Thursday**, Friday off, for everyone without another schedule |
| Late, early | Recorded, **no penalty**, **15 minutes' grace** |
| Reminder, close | Per shift: the reminder at the end, the close **4 hours** after it |
| Holidays | None yet |
| Guards | **72 hours on from 08:00, then 3 days off**; **one check-in and one check-out per duty**; a missing check-out leaves all three shifts missing |
| Leave | Charges the person's scheduled days only; a 72-hour duty charges **3 days** |
| Matching | A night shift belongs to its **start date**; check-in opens **60 minutes** early; a check-in outside every window is **accepted and flagged** |
| Hours | Within the shift and outside it, both shown; no overtime approval |
| Who | HR only, company-wide |

### 1.2 Decided while designing (2026-11-09)

- **A schedule change starts today or later.** Past days keep the schedule they
  had; a wrong past day is fixed with a one-off change (piece 2), which is
  audited.
- **A shift with no check-in is "not yet recorded" until its end**, then absent.
- **A check-in records its shift's date** when it happens (approach A of three):
  everything else — expected times, late and early, the minutes within and
  outside — is derived on read.

### 1.3 Not in this piece

One-off changes and swaps (piece 2); the dashboard (3); the app (4). Rotating,
flexible and dated hours (S2). A holiday calendar (S6). Overtime approval and any
pay effect (Stage 4). A grace or margin per shift: one value each, company-wide.
Managers editing schedules (S12).

---

## 2. Concepts

- **Shift** — a start time and a length: `DAY` is 09:00 for 420 minutes, `GUARD`
  08:00 for 1440. A shift past midnight (22:00 for 480) ends the next day.
- **Schedule** — which shift falls on which day. **Weekly**: by weekday.
  **Cycle**: by position in an N-day cycle counted from each person's cycle start
  date. A day with no shift is a **day off**.
- **Assignment** — a person's schedule over a dated span. With none in force, the
  person has the **default schedule**.
- **Expected shift** — a person's shift on a date: the date, its start and end as
  full date-times, the shift and the schedule it came from.
- **Duty** — a run of back-to-back expected shifts, each ending exactly when the
  next begins. An office day is a duty of one shift; a guard's 72 hours is a duty
  of three. **A duty is at most three shifts** (72 hours): schedule validation
  refuses a longer run, and the resolver cuts one at three if assignments ever
  join two runs. One check-in and one check-out belong to a duty.
- **Shift date** — the date a record belongs to: the start date of its duty's
  first shift.

---

## 3. Data model

### 3.1 New tables (module `Scheduling`, `database/migrations/Scheduling`)

**`shifts`**

| Column | |
|---|---|
| `code` | unique, validated against trashed rows too |
| `name` | Arabic display name |
| `starts_at` | time, `H:i` |
| `duration_minutes` | 1–1440 |
| `is_active` | an inactive shift cannot be put into a new schedule |

`starts_at` and `duration_minutes` cannot change once any schedule uses the
shift; the name and `is_active` always can. Soft-deleted; deleting is refused
while a schedule uses it.

**`work_schedules`**

| Column | |
|---|---|
| `code`, `name` | as above |
| `kind` | `WorkScheduleKind`: Weekly = 1, Cycle = 2 |
| `cycle_days` | 7 for a weekly schedule; 1–28 for a cycle |
| `is_active` | an inactive schedule cannot be newly assigned |

Its days cannot change once anyone has been assigned it — a change is a new
schedule, assigned from a date. Soft-deleted; deleting is refused once assigned.

**`work_schedule_days`**

| Column | |
|---|---|
| `work_schedule_id` | |
| `position` | weekly: Carbon's `dayOfWeek` (0 = Sunday … 6 = Saturday); cycle: 0 … `cycle_days − 1` |
| `shift_id` | nullable: **null is a day off** |

Unique on (`work_schedule_id`, `position`). Every position from 0 to
`cycle_days − 1` has exactly one row, so a day off is stated, never implied.

**`employee_work_schedules`**

| Column | |
|---|---|
| `employee_id` | restricts on delete |
| `work_schedule_id` | |
| `from_date` | today or later, at creation |
| `to_date` | nullable: null is current |
| `cycle_start_date` | required for a cycle schedule, null for a weekly one: the date that is position 0 |
| `created_by` | the account |

Dated history in the pattern of `employee_assignments`: spans never overlap, at
most one is open, and assigning a new one closes the open one on the day before
its `from_date`. All four models use `HasAuditLog`.

### 3.2 New columns on `attendance_records`

| Column | |
|---|---|
| `shift_date` | date, not null; indexed with `employee_id`. Backfilled with `DATE(recorded_at)` — exactly today's grouping |
| `outside_schedule` | boolean, default false: no shift's window fitted the check-in (S10) |
| `checkout_reminded_at` | timestamp, nullable: on a check-in, when its check-out reminder was sent |

### 3.3 Configuration (`config/attendance.php`)

| Key | Value | |
|---|---|---|
| `default_schedule` | `OFFICE` | the code of the default schedule; unset during build steps 3–4 (§10) |
| `grace_minutes` | 15 | S4 |
| `check_in_opens_minutes` | 60 | S10 |
| `close_after_minutes` | 240 | S5, S10 |

`working_days`, `check_in.start`, `check_in.end`, `reminder_at` and
`auto_close_at` are removed in build step 5. The timezone, the 60-minute minimum
between check-in and check-out, the clock-ahead limit, the leave rule and the
selfie settings stay.

### 3.4 Seeded data (`SchedulingSeeder`)

- Shift **`DAY`** «الدوام الإداري», 09:00, 420 minutes. Schedule **`OFFICE`**,
  weekly: positions 6, 0, 1, 2, 3, 4 (Saturday to Thursday) → `DAY`; position 5
  (Friday) off. The default.
- Shift **`GUARD`** «مناوبة حراسة», 08:00, 1440 minutes. Schedule **`GUARD`**, a
  6-day cycle: positions 0, 1, 2 → `GUARD`; 3, 4, 5 off.

Seeded by code, idempotently, so a re-run changes nothing.

---

## 4. The resolver

### 4.1 Where it lives

The `Scheduling` module owns the tables above and implements
**`App\Modules\Attendance\Contracts\ExpectedShifts`**, bound in its service
provider — the same arrangement as `ApprovedLeave`, which Leave implements for
Attendance. Attendance and Leave read schedules only through the contract, and
their tests can bind a fake.

```php
interface ExpectedShifts
{
    /** @return array<int, array<string, ExpectedShift>> employee id → date → shift (days off absent) */
    public function shiftsFor(array $employeeIds, string $from, string $to): array;

    /**
     * The duties whose check-in window holds $moment, earliest start first.
     * Attendance picks the first without a check-in (§5.1), so Scheduling
     * never reads attendance records.
     *
     * @return list<Duty>
     */
    public function dutiesHolding(Employee $employee, CarbonInterface $moment): array;

    /** The duty that starts on $shiftDate, or null — for a record already dated. */
    public function dutyOn(Employee $employee, string $shiftDate): ?Duty;

    /** Dates in the inclusive span on which the employee has a shift. */
    public function workingDaysBetween(Employee $employee, string $from, string $to): int;
}
```

`ExpectedShift` and `Duty` are read-only value objects in
`Attendance/Support`. A `Duty` holds its shifts in order, its `startsAt`
(the first shift's start), `endsAt` (the last's end), `opensAt`
(`startsAt − check_in_opens_minutes`) and `closesAt` (`endsAt +
close_after_minutes`).

### 4.2 How a person's shift on a date is found

1. The assignment in force on the date (`from_date ≤ date`, and `to_date` null or
   `≥ date`); else the default schedule.
2. Weekly: the row at `date->dayOfWeek`. Cycle: the row at
   `(date − cycle_start_date) mod cycle_days`, in whole days.
3. A row with a shift gives an expected shift starting at `date + starts_at`
   and lasting `duration_minutes`, in the attendance timezone. Otherwise, a day
   off.

**The seam for piece 2:** a one-off change for the person on the date is read
before step 1.

### 4.3 Duties

The resolver walks a person's expected shifts in order and joins each one whose
start equals the previous end, up to three. Finding the duty that holds a date
looks back at most two days. Schedule validation refuses a schedule in which
more than three shifts run back to back — counting the wrap-around of a cycle —
so the cut at three only ever applies across two assignments.

### 4.4 Batch reads

`shiftsFor()` loads every relevant assignment and schedule for the ids in two
queries, so a monthly report over the company resolves in memory. Schedules,
their days and shifts are read once per request.

### 4.5 The temporary open day (build steps 3–4 only)

While `default_schedule` is unset, a person with no assignment gets an **open
day** on every date: it starts at 00:00, check-in is allowed all day, it closes
at 23:59, and it has no late or early departure. That is exactly today's rules,
so wiring the resolver in changes nothing for anyone HR has not assigned.
`ExpectedShift::$isOpen` carries it; build step 5 deletes it.

---

## 5. Recording

### 5.1 Check-in (`AttendanceService::assertCanCheckIn`, replaced)

In order:

1. The moment may be at most `max_clock_ahead_minutes` (10) ahead of the server
   — unchanged.
2. **Refused while the employee has an open check-in** — an accepted check-in
   with no accepted check-out whose close is after this moment: «لديك تسجيل حضور
   قائم؛ يجب تسجيل الانصراف أولاً.» "Open" is judged on moments, not on the
   `status` flag, so a check-in the close has flagged is open for a check-out
   recorded before that close (§5.2, parity audit S-2).
3. The first of `dutiesHolding(employee, moment)` with no accepted check-in:
   - **A duty.** Refused if it already has an accepted check-in: «لديك تسجيل حضور
     قائم بالفعل لهذه الوردية.» Refused if approved leave covers the date of the
     shift the moment falls in (the first shift's, if the moment is before the
     start), unless `allow_attendance_during_leave`. Otherwise accepted with
     `shift_date` = the duty's start date.
   - **No duty.** Accepted with `outside_schedule` = true and `shift_date` = the
     moment's calendar date — unless that date already has an outside-schedule
     check-in, which is refused as today's second check-in is.

If two duties' windows hold the moment — only possible with shifts HR defines
close together — the earlier-starting one without a check-in wins.

### 5.2 Check-out (`assertCanCheckOut`, replaced)

1. It closes **the employee's latest accepted check-in with no accepted
   check-out whose close is after the check-out's moment**, whatever its date.
   A duty closes at `endsAt + close_after_minutes`; an outside-schedule
   check-in at 23:59 of its date. None: «لا يمكن تسجيل الانصراف بدون تسجيل حضور
   قائم.» A check-out tapped after the close finds nothing to close — today's
   rule ("a check-out needs a check-in that same day"), generalised.
2. Refused before the check-in, or within `min_checkout_gap_minutes` of it —
   unchanged.
3. **Judged on the moment the check-out was recorded, not when it arrived.** An
   offline check-out tapped before the close and synced after it is accepted,
   and **clears the check-in's missing-check-out flag** — parity audit S-2, as
   today.
4. The check-out row takes the check-in's `shift_date`. `work_hours` stays the
   actual time between the two.

HR's correction (`PATCH /api/attendance/records/{record}/checkout`) keeps parity
audit S-3 — the corrected moment is after the check-in, not in the future, and
**not after the check-in's close** (today: on the check-in's own day, which is
what the open day's 23:59 close gives) — stays exempt from the minimum gap,
clears the flag, and copies the `shift_date`. A guard's missing check-out can
be corrected to 08:00 on day four.

### 5.3 Offline sync

`POST /api/me/attendance/sync` applies the same rules to each entry by its
`recorded_at`, in order. A check-out tapped after its close finds no check-in to
close, is refused with the message above and reported in the partial result;
the other entries still land. One tapped before the close lands whenever it
arrives (§5.2, rule 3).

### 5.4 HR's refusals

Refusing and un-refusing are unchanged. A refused check-in keeps its
`shift_date`, and a refused day is read on its shift date.

---

## 6. Reading

### 6.1 A duty spread over its shifts — derived, never stored

For each duty with a check-in, the presence interval runs from the check-in to
the check-out (open: to now; missing check-out: none — see below). For each
shift of the duty:

| Value | Rule |
|---|---|
| present | the presence overlaps the shift |
| absent | no overlap, and the shift has ended |
| `late_minutes` | on the **first shift with presence**, when the presence starts more than `grace_minutes` after that shift's start: the minutes from the start (09:16 → 16). Otherwise 0 |
| `early_departure_minutes` | on the **last shift with presence**, when the presence ends more than `grace_minutes` before that shift's end: the minutes to the end. Otherwise 0 |
| `minutes_within_shift` | the overlap |
| `minutes_outside_shift` | time before the duty's start (on the first shift) and after its end (on the last) |
| `work_hours` | the shift's share of the presence: within plus outside. The shares add up to the record's `work_hours` |

**A missing check-out** makes every shift of the duty a missing check-out, with
no hours (S8). An open day (§4.5) has no late, early or outside minutes.
An outside-schedule check-in is a day of its own, flagged, with all its time
outside.

### 6.2 A day's state

`AttendanceDayState` keeps its nine cases and their precedence. What changes is
what feeds them:

- **Off** — the person has no shift on the date: a guard's rest days, Fridays
  under the default.
- **Not yet** — the shift has not ended and nothing covers it (was: the date is
  today or later).
- **Absent** — the shift has ended, with no presence, no leave and no excuse.
- **Expected** — a shift that has ended. The monthly report's arithmetic still
  closes: expected = present + on leave + excused + absent.

### 6.3 Reports

All of them read `shift_date` instead of `whereDate(recorded_at)`, and read
records from two days before the range so that a duty begun before `from` is
spread into it.

- **The daily board** for a date shows each person's shift on that date. A guard
  on day two of a duty shows as checked in.
- **The day grid and `/api/me/attendance/days`** — each row gains:

  ```json
  "shift": { "code": "DAY", "name": "الدوام الإداري",
             "starts_at": "2026-11-14T09:00:00+03:00", "ends_at": "2026-11-14T16:00:00+03:00" },
  "late_minutes": 16, "early_departure_minutes": 0,
  "minutes_within_shift": 404, "minutes_outside_shift": 0,
  "outside_schedule": false
  ```

  `shift` is null on a day off. A guard's three rows each carry the duty's
  `check_in_id`, `check_in_time` and `check_out_time`, and each its own share of
  `work_hours`. Future dates show their shift, so the app's calendar can show the
  schedule.
- **The monthly report** gains `late_count`, `late_minutes`,
  `early_departure_count`, `early_departure_minutes` and
  `outside_schedule_count` per row and in the totals; `expected_days` and
  `present_days` count shifts.
- **Absent today** lists the people whose shift on the date has ended with no
  check-in and no leave. Someone not in by 10:00 is "not yet recorded" on the
  daily board, with their shift beside them, until 16:00.
- **`/api/me/summary`** — today's card gains today's shift and the next one.

### 6.4 The one contract change

`working_day` on a day row and the grid's `working_days` count change meaning:
from the company calendar to **this person has a shift on this date**. The
on-leave list's page-level `working_day` now means **the default schedule has a
shift on the date**, and each of its rows gains a `working_day` of its own for
that person. `API_DOCUMENTATION.md` marks it as a contract change
(⚠️, with the date) in the manner of §8.0. Every other change is an added field.

---

## 7. Leave

- The four places that count leave days — filing (`LeaveRequestService`), HR's
  excuse (`LeaveExcuseService`), return to work (`ReturnToWorkService`) and the
  register (`LeaveRegister`) — call `ExpectedShifts::workingDaysBetween()` for
  the employee instead of `AttendanceCalendar::workingDaysBetween()`. A guard's
  duty is 3 days; a Friday off is never charged (S9).
- `requested_days` stays the snapshot taken at filing (its migration says so on
  purpose), so a later schedule change never re-charges an approved leave.
- **The holiday hook moves.** `AttendanceCalendar::isChargeableDay()` was "the
  one hook for a holiday calendar"; the resolver's `workingDaysBetween()` is now,
  so a future calendar still lands in one place for attendance and leave alike.
- `AttendanceCalendar` keeps the timezone, today, parsing and the clock and gap
  limits, and loses the working days.

---

## 8. The shift clock

`attendance:shift-clock` replaces `attendance:checkout-reminder` (17:00) and
`attendance:auto-close` (23:59). It runs **every five minutes**
(`withoutOverlapping()`), and for each open check-in:

- **Reminder** — once the duty's end has passed and `checkout_reminded_at` is
  null: send «تذكير بتسجيل الانصراف» (the same text and event type as today) and
  set `checkout_reminded_at`. Neither a missed run nor a repeated one sends it
  twice or skips it. A guard is reminded at 08:00 on day four; the default shift
  at 16:00. An outside-schedule check-in is not reminded.
- **Close** — once its close has passed: `status` = missing check-out, nothing
  invented, no hours. Only rows whose `status` is null, so it is idempotent.

`--at=<moment>` runs it as of a given moment, for tests and for re-running.
Refused records are out of it, as today.

---

## 9. HR's endpoints and permissions

### 9.1 Permissions

`scheduling.view` (read shifts, schedules, assignments and the calendar) and
`scheduling.manage` (create, change, assign). Both **company-only**: they are not
added to `PermissionReach`'s allow-list, and `PermissionReachTest` lists them in
the company-only set. Seeded as permission rows with Arabic labels by
`PermissionSeeder`; **no seeded role carries them** — `RoleSeeder` seeds only
the super admin — so an admin adds them to HR's role, as with
`hr.employees.import`. **48 permissions.**

### 9.2 Routes (16; 111 in all)

| Method | Path | Permission |
|---|---|---|
| GET, POST | `/api/scheduling/shifts` | view, manage |
| GET, PATCH, DELETE | `/api/scheduling/shifts/{shift}` | view, manage, manage |
| GET, POST | `/api/scheduling/schedules` | view, manage |
| GET, PATCH, DELETE | `/api/scheduling/schedules/{schedule}` | view, manage, manage |
| GET, POST | `/api/hr/employees/{employee}/work-schedules` | view, manage |
| PATCH, DELETE | `/api/hr/employees/{employee}/work-schedules/{assignment}` | manage |
| POST | `/api/scheduling/assignments/bulk` | manage |
| GET | `/api/scheduling/calendar` | view |

- **A schedule** is created with its days in one body: `days: [{position,
  shift_id|null}]`, complete and in range. PATCH changes the days only while
  nobody has been assigned it.
- **An assignment**: `work_schedule_id`, `from_date` (today or later),
  `cycle_start_date` (a cycle only). PATCH only ends it — `to_date`, today or
  later. DELETE only one that has not started.
- **Bulk**: `work_schedule_id`, `from_date`, `cycle_start_date`, and either
  `employee_ids` (at most 500) or `department_id` with `include_subtree`. The
  result lists each person as assigned or refused, with the reason; it is never
  all-or-nothing.
- **The calendar**: `from`, `to` (at most 92 days), `department_id`,
  `employee_id`; everyone's expected shift per date and where it came from
  (`assignment` or `default`), paginated by employee.

Rules shared between the form requests and the model hooks (C4/C5), each with
its Arabic message: no overlapping assignments; a cycle needs its start date; a
schedule's positions are complete and in range; no run of more than three
back-to-back shifts; nothing in use edited or deleted.

**Managers** see their staff's shifts on the daily board and the grid, row-scoped
as today, and have no access to these routes.

---

## 10. Build order

Each step lands with the suite green.

1. **Re-key, no change in behaviour.** The three columns of §3.2, backfilled.
   Every rule and report reads `shift_date`; a check-out pairs with the latest
   check-in whose close (23:59 of its date) is after it. The 1289 existing tests
   pass unchanged, S-2 and S-3 among them.
2. **The `Scheduling` module:** tables, models, guards, `SchedulingSeeder`, the
   16 routes and 2 permissions. Attendance does not read it yet.
3. **The resolver and the `ExpectedShifts` contract**, wired into attendance with
   `default_schedule` unset: the open day (§4.5) for anyone unassigned, so only
   people HR assigns change.
4. **The rules (§5), the shift clock (§8), the reports and `/api/me` (§6), and
   leave (§7)** on the resolver.
5. **The default switched on:** `default_schedule = OFFICE`. Delete the open day
   and the retired config keys; deliberately update the tests that assumed every
   day is a working day and check-in is open all day.
6. **Documentation:** `API_DOCUMENTATION.md` (with §6.4's contract change),
   `postman/`, `START_HERE.md` and the work order's §8.

---

## 11. Testing

Every case pins the clock (`Carbon::setTestNow`) in the attendance timezone.

- **Resolver:** weekly positions by weekday; cycle positions from the start date,
  before and after it; the boundary between two assignments; a duty built from
  back-to-back shifts and cut at three; days off; the default with no
  assignment; the open day while the default is unset.
- **Guards:**
  - a full duty → three present shifts, 72 hours in total, no late, no early;
  - first check-in on day two at 10:00 → day one absent, day two late 120
    minutes;
  - check-out on day three at 20:00 → early departure of 720 minutes on that
    shift;
  - no check-out → three missing check-outs, no hours;
  - a check-out recorded at 12:01 on day four → refused; at 11:59 → accepted,
    even when it syncs after the close has flagged the duty (the flag clears);
  - HR corrects a missing check-out to 08:00 on day four → accepted; to 12:01 →
    refused;
  - one reminder at 08:00 on day four, none on a second run;
  - the next duty's window opens at 07:00 on day seven, with no overlap.
- **A night shift, 22:00–06:00:** dated on its start date; check-out at 06:30 the
  next day; the close at 10:00.
- **Office (the default):**
  - check-in at 09:15 → on time; at 09:16 → 16 minutes late;
  - check-out at 15:44 → 16 minutes early;
  - a check-in on a Friday, or at 16:30 → accepted and flagged outside the
    schedule;
  - check-in at 07:59 → outside (the window opens at 08:00); at 08:00 → the
    shift's, with 60 minutes outside.
- **Offline sync:** a batch with a check-out recorded after its close → that
  entry refused with its reason, the others land.
- **Leave:** a guard's leave over one six-day cycle → 3 days; an office week,
  Saturday to Friday → 6; a leave on a Friday alone → 0.
- **Reports:** the arithmetic closes; a duty begun before `from` is spread into
  the range; the monthly late, early and outside counts and minutes; absent-today
  before and after a shift's end.
- **Endpoints:** a past `from_date` refused; overlapping assignments refused; a
  used shift's start refused; a schedule with a fourth back-to-back shift
  refused; bulk reports each refusal and assigns the rest.
- **Permissions:** both classified company-only in `PermissionReachTest`.
- **Step 1's guarantee:** the backfill matches `DATE(recorded_at)` on every row,
  and the existing suite passes untouched.
