# Work shifts — per-shift rules: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Each shift carries dated rules (two graces, check-in opens, check-out closes, overtime on + minimum, required minutes); every attendance reader judges a day by the rules in force on it; reports gain overtime and short-day minutes; the dashboard edits the rules.

**Architecture:** A `shift_rules` table of dated versions, a `ShiftRules` value object carried on each `ExpectedShift` the resolver builds, and the six readers of `config('attendance.…')` switched to it. Late, early, overtime and short minutes stay computed at read time in `DutyPresence`. The dashboard's shift form takes an end time and the rules, and lists the versions.

**Tech Stack:** Laravel 11, PHPUnit, Pint (backend); React 18, react-bootstrap, `node --test` (dashboard `../erp-dashboard`).

**Spec:** [`docs/superpowers/specs/2026-11-11-work-shifts-per-shift-rules-design.md`](../specs/2026-11-11-work-shifts-per-shift-rules-design.md) — "spec §n" below.

## Global Constraints

- **Prerequisite:** piece 3 (`work-shifts-dashboard`, both repos) is committed by the owner. Then branch `work-shifts-rules` from it in both repos. If it is not committed, stop and ask.
- **No commit, stage, reset, stash or push without the owner's explicit go-ahead.** Leave changes in the working tree; `git add -N` new files.
- Another agent may edit these trees: `git status` and re-read a file before editing it.
- Rule fields and limits (spec §2.1), exact: `late_grace_minutes` 0–120 · `early_leave_grace_minutes` 0–120 · `check_in_opens_minutes` 0–240 · `check_out_closes_minutes` 0–480 · `overtime_enabled` bool · `overtime_min_minutes` 0–240 · `required_minutes` 1…the shift's `duration_minutes`.
- Defaults, exact (spec §2.2): late 15 / early 15 from `config('attendance.grace_minutes')`; opens 60 from `config('attendance.check_in_opens_minutes')`; closes 240 from `config('attendance.close_after_minutes')`; overtime off, minimum 0; required = the shift's length. The seed and every new shift's first version are effective from **`2000-01-01`**.
- In force on a date = the latest `effective_from` ≤ that date. New versions: `effective_from` ≥ today (Damascus, `AttendanceCalendar` timezone).
- A duty of several shifts: opening window and late grace from its **first** shift; close, early grace and overtime from its **last** shift (spec §3.3).
- Arabic copy, exact (spec §5.1): «تم حفظ قواعد الوردية.» · «تم حذف قواعد الوردية.» · «لا يمكن أن تبدأ القواعد قبل اليوم.» · «توجد قواعد لهذه الوردية من التاريخ نفسه.» · «لا يمكن حذف قواعد بدأ العمل بها.» · «الساعات المطلوبة لا تتجاوز طول الوردية.»
- Nothing is paid, deducted or approved; overtime and short are computed and shown only.
- Gates — backend: `php artisan test`, `vendor/bin/pint --test`; dashboard: `npm test`, `VITE_API_URL=https://example.test npm run build`, `npx eslint <touched js/jsx> --rule "react/prop-types: off"` (repo-wide `npm run lint` already fails on untouched files).

## Review Focus

- **A rule edit must never change a past day.** A version from tomorrow leaves today's and yesterday's late/close exactly as before. Test in Task 2 (`test_a_future_version_leaves_earlier_days_alone`).
- **A shift with no version loaded must still work** (unit-built `ExpectedShift`, a shift created through `Shift::create` in a seeder or test): it falls back to the defaults, never a null crash. Test in Task 1 (`test_a_new_shift_gets_a_default_first_version`) and Task 2 (`ExpectedShift::rules()` fallback).
- **Overtime at the minimum's boundary** — 29 → 0, 30 → 30, and the minimum 0 meaning any minute after the end counts. Test in Task 4.
- **Short day never on an open or missing check-out**, leave, excuse or absence. Test in Task 4.
- **Overnight end time on the form** — 22:00 → 06:00 is 480 minutes; equal start and end is 1440. Test in Task 5.

---

### Task 1 (backend): `shift_rules`, the model, the seed

**Files:**
- Create: `database/migrations/Scheduling/2026_11_11_100000_create_shift_rules_table.php`
- Create: `database/migrations/Scheduling/2026_11_11_100100_seed_rules_for_existing_shifts.php` (data migration)
- Create: `app/Modules/Scheduling/Models/ShiftRule.php`
- Create: `app/Modules/Attendance/Support/ShiftRules.php`
- Modify: `app/Modules/Scheduling/Models/Shift.php` (relation, `rulesOn`, `created` hook)
- Test: `tests/Feature/Scheduling/ShiftRulesModelTest.php`

**Interfaces:**
- Produces: `final readonly class ShiftRules(int $lateGraceMinutes, int $earlyLeaveGraceMinutes, int $checkInOpensMinutes, int $checkOutClosesMinutes, bool $overtimeEnabled, int $overtimeMinMinutes, int $requiredMinutes)`; `static ShiftRules::defaults(int $durationMinutes): self` (from config, overtime off, min 0, required = duration); `toArray(): array` with the spec's snake_case keys.
- Produces: `ShiftRule` model (`$fillable` = the spec §2.1 columns; casts: `effective_from` date, `overtime_enabled` bool, ints); `toValue(): ShiftRules`.
- Produces: `Shift::rules(): HasMany` ordered by `effective_from`; `Shift::rulesOn(string $date): ShiftRules` — the loaded (or queried) version with the latest `effective_from` ≤ `$date`, else `ShiftRules::defaults($this->duration_minutes)`.
- `Shift::created` hook inserts the first version (defaults, from `2000-01-01`, `created_by` null).

- [ ] **Step 1: Write the failing tests** — `test_a_new_shift_gets_a_default_first_version` (create via `Shift::create`; one rule, `effective_from` 2000-01-01, values 15/15/60/240/false/0/duration); `test_rules_on_picks_the_latest_version_on_or_before_the_date` (versions 2000-01-01 late 15 and 2026-12-01 late 5 → `rulesOn('2026-11-30')->lateGraceMinutes === 15`, `rulesOn('2026-12-01') === 5`); `test_the_migration_seeds_every_existing_shift` (run the seed migration's `up()` against a shift whose rules were deleted → one 2000-01-01 version with defaults; follow `DefaultScheduleMigrationTest`'s pattern); `test_effective_from_is_unique_per_shift` (a second row on the same date → `QueryException`).
- [ ] **Step 2: Run** `php artisan test --filter=ShiftRulesModelTest` → FAIL (class/table missing).
- [ ] **Step 3: Implement** the two migrations (table per spec §2.1 with `unique(['shift_id','effective_from'])`, FK cascade; the seed inserts a version for every shift lacking one, values from config at migration time), the model, the value object, the relation, `rulesOn`, the hook.
- [ ] **Step 4: Run** the filter → PASS; then `php artisan test` → the whole existing suite still green (the hook must not break any test that creates shifts).
- [ ] **Step 5: Stop** — changes unstaged for review.

---

### Task 2 (backend): rules ride on the expected shift; the six readers switch

**Files:**
- Modify: `app/Modules/Attendance/Support/ExpectedShift.php` (+ `?ShiftRules $rules = null` last param; `rules(): ShiftRules` = `$this->rules ?? ShiftRules::defaults(minutes between startsAt and endsAt)`)
- Modify: `app/Modules/Scheduling/Services/ScheduleResolver.php` (`expected()` passes `$shift->rulesOn($date)`; eager-load `rules` wherever shifts are loaded — spans' `schedule.days.shift`, changes' `shift`, the default schedule; `dutiesAround` drops config)
- Modify: `app/Modules/Attendance/Support/Duty.php` (`of(array $shifts)` / `runs(array $shifts)` — opens from the first shift's `rules()->checkInOpensMinutes`, close from the last's `checkOutClosesMinutes`)
- Modify: `app/Modules/Attendance/Support/DutyPresence.php` (`split(Duty, ?in, ?out, now)` — late grace from the shift late is judged on, early grace from the shift early is judged on)
- Modify: `app/Modules/Attendance/Services/AttendanceDays.php`, `AttendanceShiftClock.php`, `app/Modules/Scheduling/Services/ShiftSwapPlan.php`, `ShiftSwapService.php` (drop the `config('attendance.check_in_opens_minutes'|'close_after_minutes'|'grace_minutes')` reads; call the new signatures)
- Modify: `tests/Unit/Attendance/DutyPresenceTest.php` — **mechanical only**: build `ExpectedShift` with `new ShiftRules(GRACE, GRACE, 60, 240, false, 0, minutes)` and call the new signatures; every assertion unchanged.
- Test: `tests/Feature/Attendance/PerShiftRulesTest.php`

**Interfaces:**
- Consumes: Task 1 `ShiftRules`, `Shift::rulesOn`.
- Produces: `ExpectedShift::rules(): ShiftRules`; `Duty::of(array $shifts): Duty`; `Duty::runs(array $shifts): array`; `DutyPresence::split(Duty $duty, ?CarbonInterface $in, ?CarbonInterface $out, CarbonInterface $now): array`.

- [ ] **Step 1: Write the failing feature tests** (time frozen as `ShiftReportsTest` does; `SchedulingSeeder`):
  - `test_a_per_shift_late_grace_decides_late` — set `DAY`'s first version (2000-01-01) to late grace 5 → a 09:06 check-in is 6 minutes late on the daily board; with the default 15 it is 0.
  - `test_a_per_shift_opening_window_decides_matching` — opens 30 → a check-in at 08:20 for 09:00 is outside the schedule; at 08:31 it is the shift's.
  - `test_a_per_shift_close_decides_the_missing_checkout` — closes 60 → the shift clock marks an open check-in missing at 17:00, not 20:00 (`ShiftClockTest`'s pattern).
  - `test_a_future_version_leaves_earlier_days_alone` — version from tomorrow with late grace 0 → today's 09:10 check-in stays 0 late; a check-in tomorrow at 09:10 is 10 late.
  - `test_a_duty_takes_check_in_rules_from_its_first_shift_and_close_from_its_last` — three back-to-back shifts with different rules; the duty's `opensAt`/`closesAt` follow first/last.
- [ ] **Step 2: Run** `php artisan test --filter=PerShiftRulesTest` → FAIL.
- [ ] **Step 3: Implement** the files listed. `rulesOn` must read the eager-loaded `rules` relation (no query per day): assert with a query-count check in one test (`DB::enableQueryLog`, the calendar over 14 days for 5 people issues no `shift_rules` query per day).
- [ ] **Step 4: Run** the filter → PASS; then the **whole suite unchanged** → green (proof nothing moved for default rules); `vendor/bin/pint --test`.
- [ ] **Step 5: Stop** — unstaged for review.

---

### Task 3 (backend): the rules API

**Files:**
- Create: `app/Modules/Scheduling/Requests/ShiftRuleRequest.php`
- Create: `app/Modules/Scheduling/Controllers/ShiftRuleController.php` (`store`, `destroy`)
- Create: `app/Modules/Scheduling/Resources/ShiftRuleResource.php`
- Modify: `app/Modules/Scheduling/Requests/ShiftRequest.php` (optional rule fields on POST; prohibited on PATCH)
- Modify: `app/Modules/Scheduling/Controllers/ShiftController.php` (`store` writes the given fields into the first version, in one transaction; `index`/`show` eager-load `rules`)
- Modify: `app/Modules/Scheduling/Resources/ShiftResource.php` (+ `rules` = in force today; `rule_versions` only when the relation is loaded on `show`)
- Modify: `app/Modules/Scheduling/routes.php` (`POST shifts/{shift}/rules`, `DELETE shifts/{shift}/rules/{rule}`, `scheduling.manage`; a rule of another shift → 404)
- Test: `tests/Feature/Scheduling/ShiftRuleEndpointsTest.php`

**Interfaces:**
- Consumes: Task 1 model.
- Produces (API, spec §5.1): shift `rules` object with the seven snake_case keys; `rule_versions[]` each `{id, effective_from, …seven keys, created_by: {id, name} | null}` newest first (`name` = the account's employee's `full_name`, as `ShiftChangeResource`).

- [ ] **Step 1: Write the failing tests** — create with `late_grace_minutes: 5, overtime_enabled: true, overtime_min_minutes: 30` → first version holds them, the rest defaults; create with `required_minutes` > duration → 422 «الساعات المطلوبة لا تتجاوز طول الوردية.»; POST version from yesterday → 422 «لا يمكن أن تبدأ القواعد قبل اليوم.»; POST twice for one date → 422 «توجد قواعد لهذه الوردية من التاريخ نفسه.»; POST valid → 201 «تم حفظ قواعد الوردية.» and `show` lists it first; DELETE a future version → 200 «تم حذف قواعد الوردية.»; DELETE the first or an in-force version → 422 «لا يمكن حذف قواعد بدأ العمل بها.»; each field's range bound (one below/above) → 422 on that field; without `scheduling.manage` → 403; another shift's rule id → 404.
- [ ] **Step 2: Run** `php artisan test --filter=ShiftRuleEndpointsTest` → FAIL.
- [ ] **Step 3: Implement** the files listed.
- [ ] **Step 4: Run** the filter → PASS; whole suite; Pint.
- [ ] **Step 5: Stop** — unstaged for review.

---

### Task 4 (backend): two graces, overtime, short days, the report fields

**Files:**
- Modify: `app/Modules/Attendance/Support/ShiftPresence.php` (+ `int $overtimeMinutes = 0`, `int $shortMinutes = 0`)
- Modify: `app/Modules/Attendance/Support/DutyPresence.php` (overtime on the duty's last shift; short per shift — spec §4.3–§4.4)
- Modify: `app/Modules/Attendance/Support/DayFacts.php` (+ `overtimeMinutes(): int`, `shortMinutes(): int`, 0 unless the day is a completed check-out — state `CheckedOut`)
- Modify: `app/Modules/Attendance/Services/AttendanceReportService.php` — beside every `late_minutes`/`early_departure_minutes` emit (rows at ~406, ~527; monthly counters ~275/293/226; totals ~324): `overtime_minutes`, `short_minutes`; monthly and totals `overtime_count`, `overtime_minutes`, `short_count`, `short_minutes`. Not sort keys.
- Test: `tests/Unit/Attendance/DutyPresenceTest.php` (new cases), `tests/Feature/Attendance/ShiftReportsTest.php` (new cases)

**Interfaces:**
- Consumes: Task 2 signatures; `ExpectedShift::rules()`.
- Produces: the report fields above (spec §4.5), read by Task 6.

- [ ] **Step 1: Write the failing tests:**
  - Unit: overtime off → 0 after a 40-minute stay; on, minimum 30 → out +29 → 0, +30 → 30, +40 → 40; minimum 0 → +1 → 1; open check-in (no out) → 0. Short: required 420 on a 420 shift, in 09:20 out 16:00 → short 20; required 390, same → 0 (within 400 ≥ 390); in 08:00 out 15:00 (early arrival does not offset) on required 420 → short 60. Two graces: late 5 / early 20 → in 09:06 is 6 late, out 15:45 is 0 early, out 15:39 is 21 early.
  - Feature (`ShiftReportsTest`): the daily row and monthly row/totals carry `overtime_minutes`, `short_minutes`, `overtime_count`, `short_count` with those values; a missing check-out, a leave day and an absent day all report `short_minutes` 0 and `overtime_minutes` 0. And `GET /api/me/attendance/days` (as the employee) carries `overtime_minutes` and `short_minutes` on the same day — if that route does not read `AttendanceReportService`, add the two fields where it builds its day rows.
- [ ] **Step 2: Run** `php artisan test --filter=DutyPresenceTest` and `--filter=ShiftReportsTest` → FAIL.
- [ ] **Step 3: Implement** — overtime: *after* = minutes from the duty's last shift's end to the check-out; `after >= min ? after : 0` when enabled. Short: `max(0, required - minutesWithin)` per shift, only when there is a check-out.
- [ ] **Step 4: Run** both filters → PASS; whole suite; Pint.
- [ ] **Step 5: Stop** — unstaged for review.

---

### Task 5 (dashboard): the shift form — end time, rules, versions

**Files:**
- Modify: `src/sections/scheduling/shiftDisplay.js` (+ `durationFromTimes(start: "HH:MM", end: "HH:MM") -> number` — end ≤ start wraps to the next day; equal → 1440; `endFromDuration(start, minutes) -> "HH:MM"`), `shiftDisplay.test.js`
- Modify: `src/sections/scheduling/schedulingPayload.js` (+ `toRulesBody(form) -> {late_grace_minutes, …seven keys}` numbers/boolean; `toShiftBody` takes `ends_at` from the form and sends `duration_minutes` via `durationFromTimes`; create body also carries `toRulesBody`), `schedulingPayload.test.js`
- Create: `src/sections/scheduling/rulesView.js` (+ test): `RULE_DEFAULTS`, `canDeleteRuleVersion(version, todayDamascus) -> boolean` (effective_from > today), `ruleSummary(rules) -> string`
- Modify: `src/sections/scheduling/ShiftFormModal.jsx` (times: «البداية», «النهاية», «ينتهي في اليوم التالي» hint, the length shown; rules fields per spec §5.2 labels; on edit, the versions list, «قواعد جديدة من تاريخ» form → `POST /scheduling/shifts/{id}/rules`, «حذف» on a future version)
- Create: `src/sections/scheduling/ShiftRuleVersions.jsx` (the versions list + new-version form, used inside the modal)
- Modify: `src/sections/scheduling/Shifts.jsx` («عمل إضافي» badge when `rules.overtime_enabled`)

**Interfaces:**
- Consumes: Task 3 API shapes; piece 3's `confirmDestructive`, `parseApiError`, `todayInDamascus`.

- [ ] **Step 1: Write the failing tests** — `durationFromTimes("09:00","16:00") === 420`; `("22:00","06:00") === 480`; `("08:00","08:00") === 1440`; `endFromDuration("22:00",480) === "06:00"`; `toRulesBody` turns string inputs into numbers and the switch into a boolean, required hours given as hours+minutes into `required_minutes`; `canDeleteRuleVersion({effective_from:"2026-11-12"}, "2026-11-11") === true`, `"2026-11-11"` → false, `"2000-01-01"` → false.
- [ ] **Step 2: Run** `npm test` → FAIL.
- [ ] **Step 3: Implement** the files listed. A 422 shows inside the modal; the length and end lock when the server refuses a used shift's time change (piece 3 behaviour).
- [ ] **Step 4: Run** `npm test`, the build, eslint on touched files → pass.
- [ ] **Step 5: Stop** — unstaged for review.

---

### Task 6 (dashboard): overtime and short-day columns

**Files:**
- Modify: `src/sections/attendance/DailySection.jsx`, `AttendanceMonthly.jsx` (four count/minutes columns + totals), `EmployeeAttendance.jsx` — «عمل إضافي» and «نقص ساعات» next to «تأخر» / «انصراف مبكر», minutes through `formatMinutes` (`src/sections/attendance/minutesText.js`); monthly labels «مرات العمل الإضافي», «دقائق العمل الإضافي», «مرات نقص الساعات», «دقائق نقص الساعات», plain (not sortable) headers.

- [ ] **Step 1: Implement** the columns (no new logic; `formatMinutes` is already tested).
- [ ] **Step 2: Run** `npm test`, the build, eslint on touched files → pass.
- [ ] **Step 3: Stop** — unstaged for review.

---

### Task 7: docs, then the browser walk

**Files:**
- Modify (backend): `docs/DECISION_ROUND_WORK_SHIFTS.md` (a dated "Reopened 2026-11-11" entry under S4, S5, S10, S11 with R1–R7's answers and a link to the spec); `docs/API_DOCUMENTATION.md` §19.1 (shift `rules`, `rule_versions`, the two routes, the 422s), §14 (the new fields; not sort keys), §12 (check-in window and close now per shift); `config/attendance.php` comments (defaults only); `docs/START_HERE.md` §2/§10; `docs/EMPLOYEE_APP_V1_WORK_ORDER.md` §8 (a dated entry: what was built, deploy `migrate`, verification counts).
- Modify (dashboard): `docs/MANUAL_DASHBOARD_TEST_SCENARIOS.md` — cases: set guards' late grace to 5 from tomorrow and see today unchanged; enable overtime with minimum 30 and see a 40-minute stay counted; required 7 h on an 8 h shift.

- [ ] **Step 1: Write the docs.**
- [ ] **Step 2: Whole-branch verification** — backend `php artisan test` (counts) + Pint; dashboard `npm test`, build, eslint on touched files.
- [ ] **Step 3: Browser walk** (controller, with the owner signed in; navigate in-app — a reload signs out): create a shift with an overnight end time; edit rules from tomorrow; delete a future version; see overtime and short on the daily board for seeded check-ins.
- [ ] **Step 4: Stop** — report; the owner commits.
