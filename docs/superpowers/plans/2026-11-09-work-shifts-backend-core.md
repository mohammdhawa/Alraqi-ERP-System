# Work Shifts — Backend Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the attendance day the shift instead of the calendar date, and give every employee a schedule — fixed day, fixed night, and the guards' 72-hours-on, 3-days-off cycle.

**Architecture:** A check-in records its shift's date (`shift_date`) when it is taken; everything else — expected times, late, early, within and outside minutes — is derived on read. A new `Scheduling` module owns shifts, schedules and dated assignments, and implements Attendance's `ExpectedShifts` contract (as Leave implements `ApprovedLeave`). Built in six steps; step 1 changes no behaviour, and the default schedule is switched on only in step 5.

**Tech Stack:** Laravel 12, PHP ^8.2, MySQL, PHPUnit feature tests (`php artisan test`), Pint.

**Spec:** [`docs/superpowers/specs/2026-11-09-work-shifts-backend-core-design.md`](../specs/2026-11-09-work-shifts-backend-core-design.md) — read it with this plan. Section numbers below (§n) are the spec's.

## Global Constraints

- Attendance timezone only: `AttendanceCalendar::timezone()`; never UTC dates for `shift_date`.
- Config values (spec §3.3): `default_schedule` = `OFFICE` (unset in steps 3–4), `grace_minutes` = 15, `check_in_opens_minutes` = 60, `close_after_minutes` = 240.
- Seeded data (spec §3.4): shift `DAY` «الدوام الإداري» 09:00 / 420 min; schedule `OFFICE` weekly, positions 6,0,1,2,3,4 → `DAY`, 5 off. Shift `GUARD` «مناوبة حراسة» 08:00 / 1440 min; schedule `GUARD` cycle of 6, positions 0,1,2 → `GUARD`, 3,4,5 off.
- Weekly positions are Carbon `dayOfWeek` (0 = Sunday … 6 = Saturday). Cycle position = `((days from cycle_start_date) mod cycle_days + cycle_days) mod cycle_days`.
- A duty is at most 3 back-to-back shifts; reports read records from 2 days before `from`.
- Parity audit S-2 and S-3 stand: a check-out is judged on its recorded moment and clears the missing flag; an HR correction is after the check-in, not in the future, not after the check-in's close.
- Arabic messages exactly as the spec writes them (§5.1, §5.2).
- Permissions `scheduling.view`, `scheduling.manage`: company-only, in no seeded role. Totals after the build: **111 routes, 48 permissions**.
- Every new model uses `App\Shared\Traits\HasAuditLog`. Rules that bind seeders and tinker too live in model hooks calling a `Support/` guard (C4/C5).
- Module code under `app/Modules/Scheduling`, migrations under `database/migrations/Scheduling` (loaded by `ModuleServiceProvider`); its `routes.php` is prefixed `api/scheduling` automatically.
- Commit steps are checkpoints: **commit only with the user's go-ahead** (the project's standing rule). Pint must pass before each.

## Review Focus

1. **A check-in sent with a UTC offset near midnight** (`2026-11-14T21:30:00Z` = 00:30 local on the 15th) must get the *local* `shift_date` — Task 1 pins it.
2. **Dates before a guard's `cycle_start_date`** (PHP's `%` is negative for negative operands) must still map to the right position — Task 6 pins it.
3. **A schedule change today while a check-in from yesterday's duty is still open** (a guard mid-duty) must keep resolving that duty from the assignment in force on its `shift_date` — Task 8 pins it.
4. **A leave spanning an assignment boundary** must count each date under the assignment in force on it — Task 12 pins it.
5. **An employee whose `termination_date` falls inside a report range** must not be expected after it — Task 10 pins it, reusing `AttendancePopulation`'s existing rule.

---

## Step 1 — re-key, no change in behaviour

### Task 1: `shift_date`, `outside_schedule`, `checkout_reminded_at` on records

**Files:**
- Create: `database/migrations/Attendance/2026_11_10_100000_add_shift_columns_to_attendance_records.php`
- Modify: `app/Modules/Attendance/Models/AttendanceRecord.php` (fillable, casts, `booted()` creating hook, `scopeOnDate`, `scopeInDateRange`)
- Test: `tests/Feature/Attendance/ShiftDateColumnTest.php`

**Interfaces:**
- Produces: columns `shift_date` (date, not null, index `(employee_id, shift_date)`), `outside_schedule` (bool, default false), `checkout_reminded_at` (timestamp, null). `scopeOnDate(string $date)` and `scopeInDateRange(?string $from, ?string $to)` keep their names and now filter `shift_date`. A creating hook sets `shift_date` from `recorded_at` (in the attendance timezone) when it is not given, so factories and existing tests need no change.

- [ ] **Step 1: Write the failing tests**

```php
public function test_the_backfill_matches_the_recorded_date_on_every_row(): void
// as WorkSiteRemovalMigrationTest does: run the migration's down(), insert rows with DB::table (recorded_at
// '2026-06-21 08:00:00' and '2026-06-21 23:59:00'), run up() → whereRaw('shift_date <> DATE(recorded_at)')->count() === 0

public function test_a_record_created_without_a_shift_date_takes_its_recorded_date(): void
// factory()->checkIn()->create(['recorded_at' => '2026-06-21 08:00:00']) → shift_date->toDateString() === '2026-06-21'

public function test_a_check_in_sent_in_utc_near_midnight_is_dated_locally(): void
// POST /api/me/attendance recorded_at '2026-11-14T21:30:00Z' (timezone Asia/Damascus) → record shift_date === '2026-11-15'

public function test_on_date_filters_by_shift_date(): void
// a row with recorded_at '2026-06-22 06:00:00' and shift_date '2026-06-21' → onDate('2026-06-21') finds it, onDate('2026-06-22') does not
```

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test --filter=ShiftDateColumnTest`
Expected: FAIL — unknown column `shift_date`.

- [ ] **Step 3: Write the migration** — add the three columns (`shift_date` nullable first), `UPDATE attendance_records SET shift_date = DATE(recorded_at)`, then make it not null and add the index. `down()` drops them.

- [ ] **Step 4: Update the model** — fillable and casts (`shift_date` → `date`, `outside_schedule` → `boolean`, `checkout_reminded_at` → `datetime`); the creating hook; both scopes on `shift_date` (`whereDate` is fine on a `date` column).

- [ ] **Step 5: Run the new tests and the whole suite**

Run: `php artisan test --filter=ShiftDateColumnTest` then `php artisan test`
Expected: PASS, and the suite still at **1289 + 4** passing.

- [ ] **Step 6: Commit** (with the go-ahead) — `Date each attendance record by its shift`

### Task 2: Pair a check-out with its open check-in

**Files:**
- Create: `app/Modules/Attendance/Services/OpenCheckIns.php`
- Modify: `app/Modules/Attendance/Services/AttendanceService.php` (`assertCanCheckIn`, `assertCanCheckOut`, the create call)
- Modify: `app/Modules/Attendance/Services/AttendanceOversightService.php` (`correctCheckout`, `reject`, `unreject`: pair on `shift_date`, not `recorded_at->toDateString()`)
- Modify: `app/Modules/Attendance/Services/AttendanceScheduleService.php` (`stillCheckedIn` on `shift_date`)
- Test: `tests/Feature/Attendance/OpenCheckInTest.php`

**Interfaces:**
- Produces:
  - `OpenCheckIns::closeOf(AttendanceRecord $checkIn): CarbonImmutable` — step 1: end of its `shift_date` (23:59:59). Task 8 replaces the body.
  - `OpenCheckIns::forCheckOut(Employee $employee, CarbonInterface $moment): ?AttendanceRecord` — the latest accepted check-in recorded before `$moment` with no accepted check-out on its `shift_date`, whose `closeOf()` is ≥ `$moment`. Its `status` may be MissingCheckout (S-2).
  - `OpenCheckIns::blocking(Employee $employee, CarbonInterface $moment): ?AttendanceRecord` — the same, used by check-in rule 2.
- Every record `AttendanceService::record()` creates sets `shift_date` explicitly: a check-in's own date (step 1), a check-out the check-in's `shift_date`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_for_check_out_finds_a_flagged_check_in_on_its_date(): void
// check-in 2026-06-21 08:00, status MissingCheckout → forCheckOut($e, '2026-06-21 16:00') returns it (S-2)

public function test_for_check_out_finds_nothing_after_the_close(): void
// same check-in → forCheckOut($e, '2026-06-22 00:30') is null (step 1's close: 23:59:59 of shift_date)

public function test_blocking_is_cleared_by_a_check_out(): void
// check-in 08:00 → blocking($e, '2026-06-21 12:00') returns it; add check-out 16:00 → blocking(…, '2026-06-21 17:00') is null

public function test_close_of_is_the_end_of_the_shift_date(): void
// closeOf(check-in) → '2026-06-21 23:59:59' in the attendance timezone
```

Step 1 changes no behaviour, so every message stays as it is, and the S-2 and S-3 tests in `AttendanceCheckInOutTest` and `AttendanceCheckoutCorrectionTest` must pass unchanged.

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test --filter=OpenCheckInTest`
Expected: FAIL — class `OpenCheckIns` not found.

- [ ] **Step 3: Implement `OpenCheckIns` and switch the three services to it**, keeping every existing message.

- [ ] **Step 4: Run the suite**

Run: `php artisan test`
Expected: all PASS, nothing else changed — S-2, S-3 and the rejection batch tests included.

- [ ] **Step 5: Commit** (with the go-ahead) — `Pair a check-out with its open check-in`

---

## Step 2 — the `Scheduling` module

### Task 3: Tables, models, guards and the seeded schedules

**Files:**
- Create: `database/migrations/Scheduling/2026_11_10_200000_create_shifts_table.php`, `…_200100_create_work_schedules_table.php`, `…_200200_create_work_schedule_days_table.php`, `…_200300_create_employee_work_schedules_table.php`
- Create: `app/Modules/Scheduling/Enums/WorkScheduleKind.php` (`Weekly = 1`, `Cycle = 2`, `label()`: «أسبوعي», «دوري»)
- Create: `app/Modules/Scheduling/Models/{Shift,WorkSchedule,WorkScheduleDay,EmployeeWorkSchedule}.php`
- Create: `app/Modules/Scheduling/Support/ScheduleGuard.php`, `app/Modules/Scheduling/Support/AssignmentGuard.php`
- Create: `app/Modules/Scheduling/Exceptions/SchedulingException.php` (Arabic message, rendered as 422 like `AttendanceException`)
- Create: `database/seeders/SchedulingSeeder.php`; Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/Scheduling/ScheduleModelTest.php`

**Interfaces:**
- Produces:
  - `ScheduleGuard::violations(WorkScheduleKind $kind, int $cycleDays, array $days, array $shiftsById): list<string>` — `$days` is `[position => ?shiftId]`; checks positions complete `0…cycleDays−1`, weekly `cycleDays === 7`, cycle `1…28`, and **no run of more than 3 back-to-back shifts** counting the cycle's wrap-around (back-to-back: one ends at the next one's start, both on consecutive positions).
  - `AssignmentGuard::violations(?int $ignoreId, int $employeeId, WorkSchedule $schedule, string $from, ?string $to, ?string $cycleStart, string $today): list<string>` — `from ≥ today` on create, `to ≥ from`, `to ≥ today` when set, cycle needs `cycleStart`, weekly forbids it, no overlap with another span of the employee.
  - `Shift::isInUse(): bool`, `WorkSchedule::isAssigned(): bool`; model hooks refuse editing `starts_at`/`duration_minutes` of a used shift, the days of an assigned schedule, and deleting either while used.
  - `EmployeeWorkSchedule::scopeInForceOn(Builder $q, string $date)`.
  - `SchedulingSeeder::run()` — idempotent `updateOrCreate` by code.

- [ ] **Step 1: Write the failing tests**

```php
test_the_seeder_creates_office_and_guard_and_is_idempotent()   // run twice → 2 shifts, 2 schedules, 7 + 6 day rows
test_office_has_friday_off()                                   // OFFICE day at position 5 has shift_id null; 0,1,2,3,4,6 → DAY
test_a_schedule_with_four_back_to_back_24h_shifts_is_refused() // cycle 6 [G,G,G,G,off,off] → SchedulingException
test_the_wrap_around_counts()                                  // cycle 6 [G,G,off,off,G,G] → refused (4 in a row across the wrap)
test_incomplete_positions_are_refused()                        // weekly with 6 rows → refused
test_a_used_shifts_start_cannot_change_but_its_name_can()
test_an_assigned_schedules_days_cannot_change()
test_overlapping_assignments_are_refused()
test_a_past_from_date_is_refused()                             // Carbon::setTestNow('2026-11-14 10:00'); from '2026-11-13' → refused
test_a_cycle_assignment_needs_its_start_date()
test_a_new_assignment_closes_the_open_one_the_day_before()     // open from 11-14; new from 11-20 → first to_date 11-19
```

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test --filter=ScheduleModelTest` — Expected: FAIL, classes missing.

- [ ] **Step 3: Write the migrations, enum, models (all `HasAuditLog`; `shifts` and `work_schedules` soft-delete), guards, hooks and seeder**

- [ ] **Step 4: Run the tests**

Run: `php artisan test --filter=ScheduleModelTest` — Expected: PASS.

- [ ] **Step 5: Commit** (with the go-ahead) — `Add shifts, schedules and dated assignments`

### Task 4: Permissions, and the shift and schedule endpoints

**Files:**
- Modify: `database/seeders/PermissionSeeder.php` (group `'scheduling' => ['scheduling.view', 'scheduling.manage']`; labels «عرض الورديات والجداول», «إدارة الورديات والجداول»)
- Modify: `tests/Feature/Auth/PermissionReachTest.php::test_every_permission_is_classified_on_purpose` (add both to the company-only list, sorted)
- Create: `app/Modules/Scheduling/routes.php`, `Controllers/{ShiftController,WorkScheduleController}.php`, `Requests/{ShiftRequest,WorkScheduleRequest}.php`, `Resources/{ShiftResource,WorkScheduleResource}.php`
- Test: `tests/Feature/Scheduling/ShiftEndpointsTest.php`, `tests/Feature/Scheduling/WorkScheduleEndpointsTest.php`

**Interfaces:**
- Produces routes (spec §9.2): `GET|POST /api/scheduling/shifts`, `GET|PATCH|DELETE /api/scheduling/shifts/{shift}`, the same five for `schedules`. Middleware `permission:scheduling.view` on reads, `permission:scheduling.manage` on writes.
- `WorkScheduleResource`: `id, code, name, kind, kind_label, cycle_days, is_active, days: [{position, shift: ShiftResource|null}]`.
- `ShiftResource`: `id, code, name, starts_at ("H:i"), duration_minutes, ends_next_day (bool), is_active`.
- Requests call `ScheduleGuard::violations()` and return its messages as field errors on `days`.

- [ ] **Step 1: Write the failing tests** — for each resource: list and show with `scheduling.view` (200); create, update, delete with `scheduling.manage` (201/200/204); every write with only `scheduling.view` → 403; a roleless user → 403; the in-use refusals of Task 3 surface as 422 with the guard's message; `code` unique against trashed rows; and in `PermissionReachTest` the new list.

- [ ] **Step 2: Run them to see them fail** — `php artisan test --filter="ShiftEndpointsTest|WorkScheduleEndpointsTest|PermissionReachTest"`; Expected: FAIL (404s; the reach test lists two unexpected permissions once the seeder changes).

- [ ] **Step 3: Implement routes, controllers, requests and resources**

- [ ] **Step 4: Run them** — Expected: PASS. `php artisan route:list --path=api --json | php -r 'echo count(json_decode(stream_get_contents(STDIN)));'` → **105**.

- [ ] **Step 5: Commit** (with the go-ahead) — `Manage shifts and schedules over the API`

### Task 5: A person's assignments, and bulk assignment

**Files:**
- Modify: `app/Modules/HR/routes.php` (inside `employees.` group: `employees/{employee}/work-schedules` GET/POST, `…/{assignment}` PATCH/DELETE, `whereNumber`)
- Modify: `app/Modules/Scheduling/routes.php` (`POST assignments/bulk`)
- Create: `app/Modules/Scheduling/Controllers/{EmployeeWorkScheduleController,BulkAssignmentController}.php`, `Requests/{AssignWorkScheduleRequest,EndWorkScheduleRequest,BulkAssignRequest}.php`, `Resources/EmployeeWorkScheduleResource.php`, `Services/ScheduleAssigner.php`
- Test: `tests/Feature/Scheduling/EmployeeWorkScheduleEndpointsTest.php`, `tests/Feature/Scheduling/BulkAssignmentTest.php`

**Interfaces:**
- Produces:
  - `ScheduleAssigner::assign(Employee $employee, WorkSchedule $schedule, string $from, ?string $cycleStart, User $by): EmployeeWorkSchedule` — locks the employee row, closes the open span the day before, creates; throws `SchedulingException`.
  - `ScheduleAssigner::bulk(WorkSchedule $schedule, string $from, ?string $cycleStart, array $employeeIds, User $by): array` → `list<array{employee_id:int, assigned:bool, reason:?string}>`, each person in their own transaction.
  - `EmployeeWorkScheduleResource`: `id, work_schedule: {id, code, name, kind}, from_date, to_date, cycle_start_date, created_by`.
  - Bulk body: `work_schedule_id`, `from_date`, `cycle_start_date?`, and `employee_ids` (1–500, distinct) **or** `department_id` + `include_subtree` (bool) — `Department::subtreeIds()` gives the subtree.

- [ ] **Step 1: Write the failing tests** — history read with `scheduling.view`; assign with `scheduling.manage` (201, previous span closed the day before); PATCH sets only `to_date` (anything else ignored; `to_date` in the past → 422); DELETE of a started span → 422, of a future one → 204; bulk over three employees where one already has an overlapping future span → `assigned: true, true, false` with the guard's reason; bulk by `department_id` + subtree reaches a child unit's staff; 501 ids → 422.

- [ ] **Step 2: Run them to see them fail** — `php artisan test --filter="EmployeeWorkScheduleEndpointsTest|BulkAssignmentTest"`

- [ ] **Step 3: Implement**

- [ ] **Step 4: Run them** — Expected: PASS; route count **110**.

- [ ] **Step 5: Commit** (with the go-ahead) — `Assign schedules to people, one at a time or in bulk`

---

## Step 3 — the resolver, wired in with the open day

### Task 6: `ExpectedShifts`, `ExpectedShift`, `Duty` and the resolver

**Files:**
- Create: `app/Modules/Attendance/Contracts/ExpectedShifts.php` (exactly spec §4.1, with `dutiesHolding`)
- Create: `app/Modules/Attendance/Support/ExpectedShift.php`, `app/Modules/Attendance/Support/Duty.php`
- Create: `app/Modules/Scheduling/Services/ScheduleResolver.php` (implements `ExpectedShifts`)
- Modify: `app/Providers/AppServiceProvider.php` (`$this->app->bind(ExpectedShifts::class, ScheduleResolver::class)`, beside `ApprovedLeave`)
- Modify: `config/attendance.php` (add `default_schedule` → `env('ATTENDANCE_DEFAULT_SCHEDULE')`, null for now; `grace_minutes`, `check_in_opens_minutes`, `close_after_minutes`)
- Create: `tests/Feature/Attendance/Concerns/FakesExpectedShifts.php` (binds a fake built from `[employeeId => [date => [startsAt, minutes]]]`)
- Test: `tests/Feature/Scheduling/ScheduleResolverTest.php`

**Interfaces:**
- Produces:
  - `final readonly class ExpectedShift { string $date; CarbonImmutable $startsAt; CarbonImmutable $endsAt; string $code; string $name; bool $isOpen; public function toArray(): array /* {code,name,starts_at,ends_at} ISO-8601 */ }`
  - `final readonly class Duty { /** @var list<ExpectedShift> */ array $shifts; CarbonImmutable $opensAt; CarbonImmutable $closesAt; bool $isOpen; public function shiftDate(): string; public function startsAt(): CarbonImmutable; public function endsAt(): CarbonImmutable; public function holds(CarbonInterface $m): bool /* opensAt ≤ m < endsAt; an open day: m within its date */; public function shiftOn(CarbonInterface $m): ExpectedShift /* the shift containing m, the first if before its start */ }`
  - Open day (spec §4.5): `startsAt` 00:00, `endsAt` 23:59:59 of the date, `isOpen` true, `opensAt` = `startsAt`, `closesAt` = `endsAt`. Returned for anyone unassigned while `default_schedule` is null.
  - `ScheduleResolver` loads assignments and schedules for all ids in two queries (spec §4.4); duties join back-to-back shifts up to 3, looking back 2 days.

- [ ] **Step 1: Write the failing tests**

```php
test_office_gives_saturday_to_thursday_and_friday_off()          // assign OFFICE; 2026-11-14 (Sat) … 11-20 (Fri): 6 shifts, none on 11-20
test_guard_positions_follow_the_cycle_start()                     // GUARD from 11-14, cycle_start 11-14: shifts on 14,15,16, 20,21,22
test_a_date_before_the_cycle_start_wraps_correctly()              // cycle_start 11-17, assignment from 11-14: 11-14 is position 3 → off; 11-11 is position 0 (if assigned) → guard
test_a_guard_duty_is_three_shifts_from_08_00_to_08_00()           // dutyOn('2026-11-14'): startsAt 11-14 08:00, endsAt 11-17 08:00, 3 shifts
test_a_night_shift_ends_the_next_day()                            // shift 22:00/480 → endsAt next day 06:00, date = start date
test_duties_holding_opens_sixty_minutes_early()                   // OFFICE: 08:00 holds the 11-14 duty; 07:59 holds none; 16:00 holds none
test_the_assignment_in_force_on_each_date_is_used()               // OFFICE until 11-19, GUARD from 11-20
test_unassigned_people_get_the_open_day_while_no_default_is_set() // isOpen true, every date
test_unassigned_people_get_the_default_when_one_is_set()          // config default_schedule = OFFICE
test_working_days_between_counts_dates_with_a_shift()             // guard 11-14..11-19 → 3; office 11-14..11-20 → 6
test_shifts_for_resolves_many_people_in_a_fixed_number_of_queries() // DB::enableQueryLog; 20 people → ≤ 4 queries
```

- [ ] **Step 2: Run them to see them fail** — `php artisan test --filter=ScheduleResolverTest`

- [ ] **Step 3: Implement the value objects and the resolver**; the position formula is the one in Global Constraints.

- [ ] **Step 4: Run them, then the suite** — Expected: PASS; nothing else changes (Attendance does not read the resolver yet).

- [ ] **Step 5: Commit** (with the go-ahead) — `Resolve each person's expected shifts and duties`

### Task 7: The calendar endpoint

**Files:**
- Modify: `app/Modules/Scheduling/routes.php` (`GET calendar`, `permission:scheduling.view`)
- Create: `Controllers/ScheduleCalendarController.php`, `Requests/ScheduleCalendarRequest.php`
- Test: `tests/Feature/Scheduling/ScheduleCalendarTest.php`

**Interfaces:**
- Consumes: `ExpectedShifts::shiftsFor()`.
- Produces: `GET /api/scheduling/calendar?from&to&department_id?&employee_id?&per_page?` → paginated by employee; each row `{employee: EmployeeStub, days: [{date, shift: ExpectedShift::toArray()|null, source: 'assignment'|'default'|'open'}]}`. `to − from` ≤ 92 days, else 422.

- [ ] **Step 1: Write the failing tests** — a guard and an office person over 11-14…11-20 return the expected shift and `source`; 93 days → 422; `scheduling.view` required.
- [ ] **Step 2: Run them to see them fail**
- [ ] **Step 3: Implement** (expose `source` by adding `public string $source` to `ExpectedShift`, set by the resolver)
- [ ] **Step 4: Run them** — PASS; route count **111**.
- [ ] **Step 5: Commit** (with the go-ahead) — `Show everyone's expected shifts over a range`

---

## Step 4 — the rules, the clock, the reports and leave on the resolver

### Task 8: Check-in and check-out against duties

**Files:**
- Modify: `app/Modules/Attendance/Services/OpenCheckIns.php` (`closeOf()`: outside-schedule → 23:59:59 of `shift_date`; else `dutyOn(employee, shift_date)->closesAt`, falling back to 23:59:59)
- Modify: `app/Modules/Attendance/Services/AttendanceService.php` (`assertCanCheckIn` per spec §5.1, returning the attribution; the create call writes `shift_date` and `outside_schedule`)
- Modify: `app/Modules/Attendance/Services/AttendanceOversightService.php::correctCheckout` (S-3 bound: not after `closeOf($checkIn)`; message for a moment after the close: «وقت الانصراف يجب أن يكون قبل انتهاء مهلة الوردية.»; today's same-day message stays for the open day's case because its close is the end of the day)
- Test: `tests/Feature/Attendance/ShiftCheckInRulesTest.php`

**Interfaces:**
- Consumes: `ExpectedShifts::dutiesHolding()`, `dutyOn()`, `OpenCheckIns`.
- Produces: `AttendanceService::attributionFor(Employee $employee, Carbon $moment): array{shift_date: string, outside_schedule: bool}` (private is fine; tests go through the API).

- [ ] **Step 1: Write the failing tests** (guard and office assignments through `ScheduleAssigner`; clock pinned):

```php
test_a_guard_checks_in_once_for_a_72_hour_duty()            // in 11-14 07:30 → shift_date 11-14; in again 11-15 09:00 → 422 'لديك تسجيل حضور قائم؛ يجب تسجيل الانصراف أولاً.'
test_a_guard_checks_out_at_the_end_of_the_duty()            // out 11-17 08:05 → 201, shift_date 11-14
test_a_check_out_after_the_close_is_refused()               // out recorded 11-17 12:01 → 422 'لا يمكن تسجيل الانصراف بدون تسجيل حضور قائم.'; 11:59 → 201
test_a_check_out_recorded_before_the_close_lands_after_it_and_clears_the_flag() // check-in flagged MissingCheckout; out recorded 11-17 11:00 sent at 13:00 → 201, status null
test_a_night_shift_check_out_the_next_morning_pairs()       // 22:00/480: in 21:30, out 06:30 next day → same shift_date
test_an_office_check_in_at_07_59_is_outside_and_at_08_00_is_the_shift() // 07:59 → outside_schedule true, shift_date that date
test_a_friday_check_in_is_accepted_and_flagged()            // OFFICE, 11-20 10:00 → 201, outside_schedule true
test_a_second_outside_check_in_on_one_date_is_refused()
test_leave_blocks_the_shift_the_moment_falls_in()           // approved leave 11-15 only; guard checks in 11-14 07:30 → allowed (first shift's date 11-14)
test_hr_corrects_a_guards_missing_check_out_within_the_close() // 11-17 08:00 → 201; 11-17 12:01 → 422
test_a_schedule_change_today_keeps_the_open_duty()          // guard checked in 11-14; on 11-15 HR assigns OFFICE from 11-15; check-out 11-17 08:00 → 201 (duty from the 11-14 assignment) — Review Focus 3
test_a_sync_with_a_check_out_after_the_close_lands_the_rest() // POST /api/me/attendance/sync: [in 11-14 09:00, out 11-14 20:01, in 11-15 08:55] under OFFICE → first and third saved, second reported with 'لا يمكن تسجيل الانصراف بدون تسجيل حضور قائم.'
```

- [ ] **Step 2: Run them to see them fail** — `php artisan test --filter=ShiftCheckInRulesTest`
- [ ] **Step 3: Implement** spec §5.1–§5.3 exactly; keep the employee row lock and the `client_uuid` flow untouched.
- [ ] **Step 4: Update the existing tests this changes** — in `AttendanceCheckInOutTest`, `AttendanceCheckoutCorrectionTest` and `AttendanceSyncTest`:
  - the check-out refusal's message becomes «لا يمكن تسجيل الانصراف بدون تسجيل حضور قائم.»;
  - a second check-in on a completed open day becomes «لديك تسجيل حضور قائم بالفعل لهذه الوردية.»;
  - the correction's off-day message is unchanged for the open day (its close is the end of the date);
  - tests that pin `attendance.working_days` or `attendance.check_in` to refuse a check-in on a day off or outside a window: delete them. Those refusals no longer exist (S10: accepted and flagged), and `test_a_friday_check_in_is_accepted_and_flagged` and `test_an_office_check_in_at_07_59_is_outside_and_at_08_00_is_the_shift` replace them.
- [ ] **Step 5: Run the suite** — Expected: PASS.
- [ ] **Step 6: Commit** (with the go-ahead) — `Record attendance against each person's duties`

### Task 9: Spreading a duty's presence over its shifts

**Files:**
- Create: `app/Modules/Attendance/Support/ShiftPresence.php`, `app/Modules/Attendance/Support/DutyPresence.php`
- Test: `tests/Unit/Attendance/DutyPresenceTest.php`

**Interfaces:**
- Produces:
  - `final readonly class ShiftPresence { bool $present; int $lateMinutes; int $earlyDepartureMinutes; int $minutesWithin; int $minutesOutside; float $workHours; }`
  - `DutyPresence::split(Duty $duty, ?CarbonInterface $in, ?CarbonInterface $out, CarbonInterface $now, int $graceMinutes): array<string, ShiftPresence>` keyed by shift date. `$out` null and the duty not closed → presence runs to `$now`. Rules: spec §6.1 table. An open-day duty: late, early and outside are 0.

- [ ] **Step 1: Write the failing tests**

```php
test_a_full_guard_duty_is_three_present_shifts_of_24_hours()   // in 08:00 d1, out 08:00 d4 → each workHours 24.0, late/early 0; sum 72.0
test_arriving_on_day_two_leaves_day_one_absent_and_day_two_late()  // in d2 10:00 → d1 present false; d2 late 120
test_leaving_on_day_three_is_an_early_departure()               // out d3 20:00 → d3 early 720, d3 within 720
test_09_15_is_on_time_and_09_16_is_sixteen_minutes_late()      // DAY duty
test_15_44_is_sixteen_minutes_early()
test_arriving_at_08_00_counts_sixty_minutes_outside()          // in 08:00, out 16:00 → within 420, outside 60, workHours 8.0
test_an_open_check_in_runs_to_now()
test_the_open_day_has_no_late_or_outside()
```

- [ ] **Step 2: Run them to see them fail** — `php artisan test tests/Unit/Attendance/DutyPresenceTest.php`
- [ ] **Step 3: Implement** — overlaps in whole minutes; `workHours` = (within + outside) / 60 rounded to 2.
- [ ] **Step 4: Run them** — PASS.
- [ ] **Step 5: Commit** (with the go-ahead) — `Spread a duty's presence over its shifts`

### Task 10: Reports and `/api/me` read shifts

**Files:**
- Modify: `app/Modules/Attendance/Services/AttendanceReportService.php` (`daily`, `monthly`, `days`, `absent`, and the private helpers that call `isWorkingDay` / `workingDaysBetween`)
- Modify: `app/Modules/Me/Services/MySummary.php` (today's card: `shift`, `next_shift`)
- Test: `tests/Feature/Attendance/ShiftReportsTest.php`

**Interfaces:**
- Consumes: `ExpectedShifts::shiftsFor()`, `dutyOn()`, `DutyPresence::split()`, `ApprovedLeave` (unchanged).
- Produces (spec §6.3): each day row gains `shift`, `late_minutes`, `early_departure_minutes`, `minutes_within_shift`, `minutes_outside_shift`, `outside_schedule`; `working_day` = has a shift; per-row `work_hours` = the shift's share; monthly rows and totals gain `late_count`, `late_minutes`, `early_departure_count`, `early_departure_minutes`, `outside_schedule_count`; `NotYet` = shift not ended; absent-today = shift ended with no check-in. Records are read with `inDateRange(from − 2 days, to)` and spread with `dutyOn()`.

- [ ] **Step 1: Write the failing tests**

```php
test_a_guards_grid_shows_three_shifts_sharing_one_check_in()      // check_in_id equal on 11-14,15,16; work_hours 24.0 each; 11-17 Off
test_the_daily_board_on_day_two_shows_the_guard_checked_in()
test_a_duty_begun_before_from_is_spread_into_the_range()           // grid from 11-15: 11-15 and 11-16 present
test_the_monthly_report_counts_late_and_early()                    // office: two days late (16, 30), one early (16) → late_count 2, late_minutes 46, early_departure_count 1
test_the_arithmetic_still_closes()                                 // expected = present + on_leave + excused + absent
test_a_shift_is_not_yet_recorded_until_its_end()                   // now 11-14 15:59, nothing recorded → NotYet; 16:00 → Absent
test_absent_today_lists_only_ended_shifts()
test_fridays_are_off_under_office()
test_no_shift_is_expected_after_termination()                      // termination_date 11-16, range 11-14..11-20 → expected 3 (14,15,16) — Review Focus 5
test_my_summary_shows_todays_and_the_next_shift()
```

- [ ] **Step 2: Run them to see them fail** — `php artisan test --filter=ShiftReportsTest`
- [ ] **Step 3: Implement** — keep `AttendanceDayState` and its precedence; replace only what feeds it.
- [ ] **Step 4: Update the tests that make a day off by pinning `attendance.working_days`** — `AttendanceAbsentTodayTest`, `AttendanceDailyReportTest`, `AttendanceEmployeeGridTest`, `MyAttendanceDaysTest`: make the day off with a schedule instead (assign `OFFICE` and use a Friday), keeping each test's assertions.
- [ ] **Step 5: Run the suite** — PASS.
- [ ] **Step 6: Commit** (with the go-ahead) — `Report attendance per shift`

### Task 11: The shift clock

**Files:**
- Create: `app/Modules/Attendance/Services/AttendanceShiftClock.php`, `app/Console/Commands/RunShiftClock.php`
- Modify: `app/Modules/Attendance/Services/AttendanceScheduleService.php` (delete `remindCheckout`, `closeDay`, `stillCheckedIn`; keep `purgeSelfies`)
- Delete: `app/Console/Commands/CloseAttendanceDay.php`, `app/Console/Commands/SendCheckoutReminders.php`
- Modify: `routes/console.php` (replace the two schedules with `Schedule::command('attendance:shift-clock')->everyFiveMinutes()->withoutOverlapping()`)
- Modify: `tests/Feature/Attendance/AttendanceScheduleCommandsTest.php` (the reminder and close cases move to the new test; selfie purge cases stay)
- Test: `tests/Feature/Attendance/ShiftClockTest.php`

**Interfaces:**
- Produces: `AttendanceShiftClock::run(CarbonInterface $at): array{reminded: int, closed: int}` — over open check-ins (accepted, no check-out on their `shift_date`, `status` null): remind when `endsAt ≤ $at` and `checkout_reminded_at` null (not for `outside_schedule`); close when `closeOf() ≤ $at`. Command `attendance:shift-clock {--at= : a moment, now by default}`. The notification is the existing title, body and `NotificationEventCatalogue::ATTENDANCE_CHECKOUT_REMINDER`.

- [ ] **Step 1: Write the failing tests**

```php
test_a_guard_is_reminded_once_at_08_00_on_day_four()   // run at 11-17 08:00 → reminded 1; again 08:05 → 0
test_office_is_reminded_at_16_00_and_closed_at_20_00() // 15:55 → 0/0; 16:00 → 1/0; 20:00 → 0/1, status MissingCheckout
test_an_outside_check_in_is_closed_at_the_end_of_its_date_and_never_reminded()
test_the_open_day_closes_at_the_end_of_its_date()      // unassigned, open day: 23:59:59 → closed
test_a_night_shift_closes_at_10_00_the_next_day()     // 22:00/480: reminded 06:00, closed 10:00
test_refused_records_are_left_alone()
test_running_twice_changes_nothing()
```

- [ ] **Step 2: Run them to see them fail** — `php artisan test --filter=ShiftClockTest`
- [ ] **Step 3: Implement, delete the two commands, update the schedule**
- [ ] **Step 4: Run the suite** — PASS. `php artisan schedule:list` shows `attendance:shift-clock` every five minutes and no `checkout-reminder` / `auto-close`.
- [ ] **Step 5: Commit** (with the go-ahead) — `One shift clock for the reminder and the close`

### Task 12: Leave counts scheduled days

**Files:**
- Modify: `app/Modules/Leave/Services/LeaveRequestService.php:60`, `LeaveExcuseService.php:62`, `ReturnToWorkService.php:89`, `LeaveRegister.php:161` (`$this->shifts->workingDaysBetween($employee, $start, $end)`)
- Modify: `app/Modules/Leave/Controllers/LeaveRequestController.php::onLeave` (page-level `working_day` = the default schedule has a shift on the date; each row gains `working_day` for its person, via `shiftsFor()`)
- Modify: `app/Modules/Leave/Resources/LeaveRequestResource.php` (the optional row `working_day`, only when the controller supplies it)
- Test: `tests/Feature/Leave/LeaveOnSchedulesTest.php`

**Interfaces:**
- Consumes: `ExpectedShifts::workingDaysBetween()`, `shiftsFor()`.

- [ ] **Step 1: Write the failing tests**

```php
test_a_guards_leave_over_one_cycle_charges_three_days()   // GUARD from 11-14; leave 11-14..11-19 → requested_days 3
test_an_office_week_charges_six_days()                    // OFFICE; 11-14..11-20 → 6
test_a_leave_on_a_friday_alone_is_refused()              // OFFICE; 11-20 only → 422 'الفترة المحددة لا تتضمن أيام عمل.' (the existing zero-day rule)
test_a_leave_across_an_assignment_boundary_counts_each_date_under_its_own() // OFFICE to 11-19, GUARD from 11-20 cycle_start 11-20; leave 11-18..11-23 → 2 (18,19) + 3 (20,21,22) = 5 — Review Focus 4
test_an_approved_leave_keeps_its_snapshot_after_a_schedule_change()
test_the_on_leave_rows_carry_their_own_working_day()
```

- [ ] **Step 2: Run them to see them fail** — `php artisan test --filter=LeaveOnSchedulesTest`
- [ ] **Step 3: Implement**
- [ ] **Step 4: Update the tests that pin `attendance.working_days`** — `LeaveExcuseTest`, `LeaveRegisterTest`, `LeaveRequestSubmissionTest`: make the day off with an `OFFICE` assignment and a Friday, keeping each test's assertions.
- [ ] **Step 5: Run the suite** — PASS; unassigned people under the open day count every day, as today.
- [ ] **Step 6: Commit** (with the go-ahead) — `Charge leave on each person's scheduled days`

---

## Step 5 — the default switched on

### Task 13: `OFFICE` for everyone unassigned

**Files:**
- Modify: `config/attendance.php` (`default_schedule` → `env('ATTENDANCE_DEFAULT_SCHEDULE', 'OFFICE')`; delete `working_days`, `check_in`, `reminder_at`, `auto_close_at`; rewrite the header comment to cite the 2026-11-09 round)
- Modify: `app/Modules/Attendance/Support/AttendanceCalendar.php` (delete `FULL_WEEK`, `workingDays`, `isWorkingDay`, `isChargeableDay`, `workingDaysBetween`, `checkInStart`, `checkInEnd`, `timeOn`, `reminderAt`, `autoCloseAt`)
- Modify: `app/Modules/Scheduling/Services/ScheduleResolver.php`, `app/Modules/Attendance/Support/{ExpectedShift,Duty}.php`, `DutyPresence.php` (delete the open day and `isOpen`)
- Modify: `.env.example` (`ATTENDANCE_DEFAULT_SCHEDULE=OFFICE`; drop the retired keys)
- Modify: `tests/Feature/Attendance/AttendanceCalendarTest.php` (its working-day, window, reminder and close cases go; it keeps the timezone, today, parse and limits), and every test that now runs under `OFFICE` without expecting to — Tasks 8, 10, 11 and 12 already moved their areas' day-off tests onto schedules, so what is left is tests that record on a Friday, before 08:00 or after 16:00 and expect the open day's results.
- Modify: `tests/TestCase.php` (seed `SchedulingSeeder` whenever attendance is exercised — in `setUp`, so the default exists in every test)

- [ ] **Step 1: Switch the config and run the suite** — `php artisan test`; Expected: failures only where a test assumed the open day (e.g. `test_the_shipped_calendar_has_no_day_off_and_no_window`).
- [ ] **Step 2: Rewrite each failing assertion to the decided rules** — Friday off, 09:00–16:00, late from 09:16 — or move its moment onto a working Saturday-to-Thursday hour where the test is about something else. Delete tests of removed behaviour (the shipped calendar, `pinFridayOffAndWindow`), and add `test_the_shipped_default_is_office_saturday_to_thursday` in `AttendanceCalendarTest`.
- [ ] **Step 3: Delete the open day and the retired calendar methods**; `grep -rn "isOpen\|workingDays()\|isWorkingDay\|checkInStart\|autoCloseAt\|reminderAt" app tests` → nothing.
- [ ] **Step 4: Run the suite and Pint** — `php artisan test` and `vendor/bin/pint --test`; Expected: all PASS.
- [ ] **Step 5: Commit** (with the go-ahead) — `Switch on the office schedule as the default`

---

## Step 6 — documentation

### Task 14: The API document, Postman and the project docs

**Files:**
- Modify: `docs/API_DOCUMENTATION.md` — a ⚠️ contract-change note for `working_day` and `working_days` (spec §6.4) in the manner of §8.0; the new day-row and monthly fields with an example; the record's `shift_date` and `outside_schedule`; the new check-in and check-out messages; a new section for the 16 routes with request and response examples (valid JSON — the app parses them as fixtures); the route table rows; the count **111**.
- Modify: `postman/` collection — the 16 requests.
- Modify: `docs/START_HERE.md` — §2 status (routes 111, permissions 48, tests), §6 the four tables, §7 the routes, §10 item 0's progress.
- Modify: `docs/EMPLOYEE_APP_V1_WORK_ORDER.md` — a §8 entry for the build, with the final counts.

- [ ] **Step 1: Write the docs**
- [ ] **Step 2: Verify the counts** — `php artisan route:list --path=api --json | php -r 'echo count(json_decode(stream_get_contents(STDIN)));'` → 111; permissions → `php artisan tinker --execute='echo App\Modules\Auth\Models\Permission::count();'` → 48; the documented test count equals `php artisan test`'s.
- [ ] **Step 3: Commit** (with the go-ahead) — `Document work shifts`
