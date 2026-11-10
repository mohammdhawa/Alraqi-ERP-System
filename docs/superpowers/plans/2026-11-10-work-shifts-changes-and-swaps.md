# Work Shifts — One-off Changes and Duty Exchanges Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give a person a shift on a date that overrides their schedule — recorded by HR, or written by an exchange of two guards' duties approved by their direct manager.

**Architecture:** One table, `shift_changes`, holds per-person, per-date overrides (a shift or a day off); the resolver reads it before assignments, so every reader of `ExpectedShifts` follows without change. HR writes rows directly and re-matches the check-ins they touch; an exchange is a new `Approvable` subject (`shift_swaps`) on the approvals engine whose approval writes its rows. Built in five steps on a branch cut from `work-shifts-core`.

**Tech Stack:** Laravel 12, PHP ^8.2, MySQL (SQLite in tests), PHPUnit feature tests (`php artisan test`), Pint.

**Spec:** [`docs/superpowers/specs/2026-11-10-work-shifts-changes-and-swaps-design.md`](../specs/2026-11-10-work-shifts-changes-and-swaps-design.md) — read it with this plan. §n is the spec's section; "core §n" is the piece 1 spec's.

## Global Constraints

- Attendance timezone only (`AttendanceCalendar::timezone()`); dates are `Y-m-d` strings in it.
- A run is at most **6** back-to-back shifts (`RunLimit::MAX = 6`); a duty stays at most 3 (`Duty::MAX_SHIFTS`). Every reader's six-day look-back is unchanged.
- HR's change range: at most **31** days per request; HR's list range and the swap list range: at most **92** days. `options` looks **31** days ahead.
- Arabic messages exactly as the spec writes them (§4.2, §5.1, §5.2, §5.3, §6.4).
- No new permission (**48**). HR's routes use `scheduling.view` / `scheduling.manage`. The guard's routes need only `auth:sanctum`. **122 routes** at the end (111 + 11).
- Every new model uses `HasAuditLog` and is added to `Relation::enforceMorphMap` in `AppServiceProvider` (`shift_change`, `shift_swap`) in the same commit — `MorphMapTest` fails otherwise.
- Every new notification `type:` is a constant in `App\Shared\Support\NotificationEventCatalogue` with an Arabic label — `NotificationEventCatalogueTest` fails otherwise.
- Scheduling does not import Leave's models: it reads leave through the new `BlockingLeave` contract (Task 5), as Attendance reads `ApprovedLeave`.
- Module code under `app/Modules/Scheduling`, migrations under `database/migrations/Scheduling`.
- Commit steps are checkpoints: **commit only with the user's go-ahead** (the project's standing rule). Pint must pass before each.

## Review Focus

1. **A partner who has left** (a `termination_date` on or before his duty) or who has no account — filing is refused for the first (`«الزميل المحدد ليس على رأس عمله.»`), and the second files fine with nobody to notify. Task 5 pins both.
2. **Two exchanges filed at once for the same duty** — the second must be refused by rule 5, not land beside the first. Task 6 locks both employee rows in ascending id order and pins the sequential case.
3. **An HR range that crosses an exchange's dates partly** — nothing at all is written, and the message names the first held date. Task 4 pins it.
4. **Removing a change** re-matches too — a check-in that was the night shift's goes back to outside the schedule. Task 4 pins it.
5. **A night change on the last date of the range** — its check-out lands the next morning, after `to`; the re-match window (`to + 1`) must carry that check-out's `shift_date` along. Task 3 pins it.

---

## Step 1 — `shift_changes` and the resolver

### Task 1: The table, the model and "in use"

**Files:**
- Create: `database/migrations/Scheduling/2026_11_11_100000_create_shift_changes_table.php`
- Create: `app/Modules/Scheduling/Enums/ShiftChangeOrigin.php` (`Hr = 1`, `Swap = 2`; `label()`: «تعديل من الموارد البشرية», «تبديل مناوبة»)
- Create: `app/Modules/Scheduling/Models/ShiftChange.php`
- Create: `app/Modules/Scheduling/Support/ShiftChangeGuard.php`
- Modify: `app/Modules/Scheduling/Models/Shift.php` (`isInUse()` also true when a `ShiftChange` references it)
- Modify: `app/Providers/AppServiceProvider.php` (morph alias `shift_change`)
- Test: `tests/Feature/Scheduling/ShiftChangeModelTest.php`

**Interfaces:**
- Produces:
  - Table per spec §3.1; unique `(employee_id, date)`; `shift_swap_id` a nullable unsigned big integer, indexed — **the foreign key is added in Task 5** with `shift_swaps`.
  - `ShiftChange`: fillable `employee_id, date, shift_id, origin, shift_swap_id, reason, created_by`; casts `date` → `date`, `origin` → `ShiftChangeOrigin`; relations `employee()`, `shift()`, `createdBy()`; `scopeForEmployees(Builder, array $ids)`, `scopeBetween(Builder, string $from, string $to)`. A `saving` hook throws `SchedulingException` with the first of `ShiftChangeGuard::violations()`.
  - `ShiftChangeGuard::violations(ShiftChange $change): list<string>` — the shift, when set, is active on create or when `shift_id` changes: «الوردية المحددة غير مفعّلة.»; Swap ⇔ `shift_swap_id` set: «مصدر التعديل لا يطابق التبديل.»; Hr needs a non-blank `reason`: «سبب التعديل مطلوب.»

- [ ] **Step 1: Write the failing tests**

```php
test_a_change_is_one_per_person_per_date()           // second create for the same (employee, date) → QueryException (unique)
test_a_null_shift_is_a_day_off_and_is_stored()       // create with shift_id null → fresh()->shift_id === null
test_an_inactive_shift_is_refused()                  // Shift is_active false → SchedulingException 'الوردية المحددة غير مفعّلة.'
test_an_hr_change_needs_a_reason()                   // origin Hr, reason '  ' → 'سبب التعديل مطلوب.'
test_origin_and_swap_id_must_agree()                 // origin Swap, shift_swap_id null → 'مصدر التعديل لا يطابق التبديل.'
test_a_shift_a_change_uses_is_in_use()               // Shift unused by any schedule, one change → isInUse() true; PATCH its starts_at → 422; DELETE → 422
test_deleting_and_updating_a_change_are_audited()    // update shift_id then delete → two audit_logs rows for alias 'shift_change'
```

- [ ] **Step 2: Run them to see them fail** — `php artisan test --filter=ShiftChangeModelTest`; Expected: FAIL, class missing.
- [ ] **Step 3: Write the migration, enum, model, guard; extend `Shift::isInUse()`; add the morph alias**
- [ ] **Step 4: Run them, then `MorphMapTest`** — Expected: PASS.
- [ ] **Step 5: Commit** (with the go-ahead) — `Record a person's shift on a date`

### Task 2: The resolver reads changes first, and the run limit

**Files:**
- Modify: `app/Modules/Attendance/Support/ExpectedShift.php` (add a trailing `public ?int $shiftId = null`)
- Modify: `app/Modules/Scheduling/Services/ScheduleResolver.php`
- Create: `app/Modules/Scheduling/Support/RunLimit.php`
- Modify: `app/Modules/Scheduling/Requests/ScheduleCalendarRequest.php` only if it validates `source` values (it should not); the calendar resource passes `source` through
- Test: `tests/Feature/Scheduling/ShiftChangeResolverTest.php`

**Interfaces:**
- Consumes: `ShiftChange` (Task 1).
- Produces:
  - Step 0 (§4.1): `resolve()` first looks up the person's change on the date — a shift gives an `ExpectedShift` with `source` `change` (Hr) or `swap` (Swap); null gives a day off with that same source in the calendar. `spansFor()` gains a sibling `changesFor(array $ids, string $from, string $to): Collection` (grouped by employee, keyed by date, `with('shift')`) — one query.
  - `fromSchedule()` and step 0 set `ExpectedShift::$shiftId`.
  - `ScheduleResolver::shiftsWith(Employee $employee, string $from, string $to, array $overrides): array<string, ExpectedShift>` — as `shiftsFor()` for one person, with `$overrides` (`array<string $date, ?int $shiftId>`, source `change`) applied over the stored changes. For trials before writing.
  - `RunLimit::MAX = 6`; `RunLimit::longest(list<ExpectedShift> $shifts): int` — the longest run of consecutive shifts where each `startsAt` equals the previous `endsAt` (input in date order).
  - `ScheduleResolver::runViolation(Employee $employee, array $overrides): ?string` — resolves `shiftsWith()` from 6 days before the earliest override date to 6 days after the latest; returns «يتجاوز هذا التعديل ست ورديات متتالية للموظف.» when `RunLimit::longest() > RunLimit::MAX`, else null.

- [ ] **Step 1: Write the failing tests** (seed `SchedulingSeeder`; `Carbon::setTestNow('2026-11-13 10:00:00')`; 2026-11-14 is a Saturday)

```php
test_a_change_wins_over_an_assignment()        // OFFICE from 11-14; change 11-15 → NIGHT (22:00/480) → shiftsFor 11-15: startsAt 22:00, source 'change'
test_a_change_wins_over_the_default()          // unassigned; change 11-20 (Friday) → DAY → shift on 11-20
test_a_null_change_is_a_day_off()              // OFFICE; change 11-16 null → no 11-16 key in shiftsFor
test_the_calendar_shows_change_and_swap()      // one Hr and one Swap row → calendar sources 'change', 'swap'
test_a_run_of_six_is_two_duties()              // GUARD cycle 11-14 (duties 14–16, 20–22); changes 11-17,18,19 → GUARD and 11-20 → null:
                                                // dutyOn('2026-11-14') and dutyOn('2026-11-17') each 3 shifts; dutiesHolding at 11-18 10:00 → the 11-17 duty
test_a_seventh_back_to_back_shift_is_a_violation() // GUARD cycle 11-14: overrides 11-17..11-19 → GUARD (run 14–22 = 9) → the message;
                                                // overrides 11-17..19 → GUARD plus 11-20 → null (run 14–19 = 6) → null
test_changes_add_one_query_to_a_batch_read()   // 20 people, DB::enableQueryLog → shiftsFor ≤ 5 queries
test_shift_id_is_set_on_every_expected_shift()
```

- [ ] **Step 2: Run them to see them fail** — `php artisan test --filter=ShiftChangeResolverTest`
- [ ] **Step 3: Implement** step 0, `changesFor`, `shiftsWith`, `RunLimit`, `runViolation`.
- [ ] **Step 4: Run them, then the suite** — Expected: PASS; nothing else changes (no rows exist outside these tests).
- [ ] **Step 5: Commit** (with the go-ahead) — `Read a person's changes before their schedule`

---

## Step 2 — HR's one-off changes

### Task 3: Re-matching the check-ins a change touches

**Files:**
- Create: `app/Modules/Scheduling/Services/CheckInRematch.php`
- Test: `tests/Feature/Scheduling/CheckInRematchTest.php`

**Interfaces:**
- Consumes: `ExpectedShifts::dutiesHolding()`, `App\Modules\Attendance\Services\OpenCheckIns::closeOf()`, `AttendanceRecord` scopes `forEmployee`, `checkIns`, `checkOuts`, `accepted`, `inDateRange`.
- Produces: `CheckInRematch::run(Employee $employee, string $from, string $to): void` — must be called inside the caller's transaction, after the rows are written. Spec §5.3 exactly: accepted check-ins with `shift_date` in `[from − 6, to + 1]`, ordered by `recorded_at`; claim the first unclaimed holding duty; else outside on the calendar date; update each check-in's `shift_date` / `outside_schedule` and its check-out row's `shift_date` (the check-out found before re-dating, by the old `shift_date`); throws `SchedulingException`:
  - «يتعارض هذا التعديل مع تسجيل حضور في {date}.» — every holding duty claimed, or a second outside check-in on one date (`{date}` = the check-in's local date);
  - «يقع تسجيل انصراف {date} بعد إغلاق الوردية الجديدة.» — a check-out's `recorded_at` after `closeOf()` of its re-dated check-in (`{date}` = the check-out's local date);
  - then the flags: no check-out and `status` MissingCheckout and `closeOf()` > now → `status` null; no check-out and the duty's end > now → `checkout_reminded_at` null.

- [ ] **Step 1: Write the failing tests** (records created with the factory and explicit `shift_date` / `outside_schedule`, then a change row written directly, then `run()`)

```php
test_an_outside_check_in_becomes_the_night_shifts()   // OFFICE; in 11-15 21:30 outside, out 11-16 06:20; change 11-15 → NIGHT; run(11-15, 11-15)
                                                      // → in: shift_date 11-15, outside false; out: shift_date 11-15
test_removing_the_change_puts_it_back_outside()       // after the above, delete the change; run → in outside true, shift_date 11-15
test_a_check_out_after_the_new_close_is_refused()     // OFFICE; in 11-15 04:30 (outside), out 11-15 10:00; change 11-15 → a shift 03:00/120 (holds 04:30; close 09:00)
                                                      // → SchedulingException 'يقع تسجيل انصراف 2026-11-15 بعد إغلاق الوردية الجديدة.'
test_two_check_ins_on_one_duty_are_refused()          // OFFICE; in 11-15 08:30, out 16:00, in 23:00 (outside); change 11-15 → a shift 08:00/1440 (holds both)
                                                      // → 'يتعارض هذا التعديل مع تسجيل حضور في 2026-11-15.'
test_a_night_change_on_the_last_date_carries_its_check_out() // Review Focus 5: change 11-20 → NIGHT, in 11-20 21:50, out 11-21 06:10 (shift_date 11-21 before);
                                                      // run(11-20, 11-20) → out shift_date 11-20
test_a_missing_flag_clears_when_the_new_close_is_ahead() // now 11-15 21:00; OFFICE in 11-15 09:00, status MissingCheckout, checkout_reminded_at set, no out;
                                                      // change 11-15 → a shift 08:00/840 (ends 22:00) → status null, checkout_reminded_at null
test_refused_check_ins_keep_their_date()
```

- [ ] **Step 2: Run them to see them fail** — `php artisan test --filter=CheckInRematchTest`
- [ ] **Step 3: Implement `run()`**
- [ ] **Step 4: Run them** — Expected: PASS.
- [ ] **Step 5: Commit** (with the go-ahead) — `Match check-ins again under a changed shift`

### Task 4: HR's change routes and the notice

**Files:**
- Create: `app/Modules/Scheduling/Services/ShiftChanger.php`
- Create: `app/Modules/Scheduling/Controllers/EmployeeShiftChangeController.php` (`index`, `store`, `destroy`)
- Create: `app/Modules/Scheduling/Requests/ShiftChangesIndexRequest.php`, `RecordShiftChangeRequest.php`
- Create: `app/Modules/Scheduling/Resources/ShiftChangeResource.php`
- Modify: `app/Modules/HR/routes.php` (beside the `work-schedules` routes: `employees/{employee}/shift-changes` GET `scheduling.view`, POST `scheduling.manage`; `employees/{employee}/shift-changes/{change}` DELETE `scheduling.manage`, `whereNumber('change')`; names `shift-changes.index|store|destroy`)
- Modify: `app/Shared/Support/NotificationEventCatalogue.php` (`SHIFT_CHANGE_RECORDED = 'shift.change_recorded'` «تعديل الوردية», `SHIFT_CHANGE_REMOVED = 'shift.change_removed'` «إلغاء تعديل الوردية»)
- Test: `tests/Feature/Scheduling/ShiftChangeEndpointsTest.php`

**Interfaces:**
- Consumes: `ShiftChange`, `ScheduleResolver::runViolation()`, `CheckInRematch::run()`, `NotificationService::sendToUser(User|int, string $title, ?string $body, ?string $type, ?Model $reference)`.
- Produces:
  - `ShiftChanger::record(Employee $employee, string $from, string $to, ?Shift $shift, string $reason, User $by): Collection<int, ShiftChange>` — one transaction with the employee row locked: refuse the first date holding a Swap change («هذا اليوم ضمن تبديل مناوبة معتمد؛ يجب إلغاء التبديل أولاً.» prefixed with the date: `"{date}: …"`); `runViolation()` with every date as an override; `updateOrCreate` per date (origin Hr); `CheckInRematch::run($employee, $from, $to)`. After commit, when `$to ≥ today`, one notice (§5.4).
  - `ShiftChanger::remove(ShiftChange $change, User $by): void` — Swap origin → «هذا التعديل ناتج عن تبديل مناوبة؛ يُلغى بإلغاء التبديل.»; else delete, `runViolation()` is not needed, re-match the date, notice when the date ≥ today.
  - Notice copy (§5.4): title «تعديل الوردية»; body one day: «تم تعديل ورديتك ليوم {date}: {shift name}» or «… يوم راحة»; a range: «تم تعديل ورديتك من {from} إلى {to}: {shift name|يوم راحة}»; removal: title «إلغاء تعديل الوردية», body «عادت ورديتك ليوم {date} إلى جدولك.». Reference: the first `ShiftChange` row (recorded) or the employee (removed — the row is gone).
  - `ShiftChangeResource`: `id, date, shift: ShiftResource|null, origin, origin_label, shift_swap_id, reason, created_by: {id, name}|null, created_at`.
  - `RecordShiftChangeRequest`: `from` date, `to` date ≥ from, span ≤ 31 days («لا يمكن أن تتجاوز الفترة 31 يوماً.»); `shift_id` nullable, exists in `shifts` (not trashed); `reason` required string ≤ 1000.
  - `ShiftChangesIndexRequest`: `from`, `to` required, span ≤ 92 days.
  - Store returns 201 with `data.shift_changes` (a list); destroy 204; another employee's change → 404.

- [ ] **Step 1: Write the failing tests**

```php
test_hr_records_a_night_change_and_it_is_listed()      // POST 11-15..11-15 NIGHT → 201; GET from 11-14 to 11-20 → one row, origin 1, shift code NIGHT
test_a_range_writes_one_row_per_date()                 // 11-15..11-17 null, reason → 3 rows, shift null
test_a_new_change_replaces_an_old_one_on_a_date()      // NIGHT then DAY on 11-15 → one row, shift DAY; audit log has the update
test_a_range_over_a_swap_date_writes_nothing()         // Review Focus 3: Swap row on 11-17; POST 11-15..11-18 → 422 message starting '2026-11-17: هذا اليوم ضمن تبديل'; 0 Hr rows
test_a_seventh_back_to_back_shift_is_refused()         // guard + 11-17..11-20 GUARD → 422 'يتجاوز هذا التعديل ست ورديات متتالية للموظف.'
test_a_conflicting_recorded_check_in_refuses_everything() // Task 3's two-check-ins case through the API → 422, no rows
test_removing_a_change_re_matches()                    // Review Focus 4: night change + check-in 21:30; DELETE → 204; check-in outside_schedule true
test_a_swap_change_cannot_be_deleted()                 // → 422 'هذا التعديل ناتج عن تبديل مناوبة؛ يُلغى بإلغاء التبديل.'
test_a_future_change_notifies_once_and_a_past_one_is_silent() // 11-15..11-17 → 1 notification type 'shift.change_recorded', body 'تم تعديل ورديتك من 2026-11-15 إلى 2026-11-17: يوم راحة'; 11-10 → 0
test_the_permissions()                                 // GET with scheduling.view 200, POST with view only 403, roleless 403; 31-day limit 422; 93-day list 422
```

- [ ] **Step 2: Run them to see them fail** — `php artisan test --filter=ShiftChangeEndpointsTest`
- [ ] **Step 3: Implement** the service, controller, requests, resource, routes and catalogue entries.
- [ ] **Step 4: Run them, then the catalogue test** — Expected: PASS; route count **114**:
  `php artisan route:list --path=api --json | php -r 'echo count(json_decode(stream_get_contents(STDIN)));'`
- [ ] **Step 5: Commit** (with the go-ahead) — `Let HR change a person's shift on given dates`

---

## Step 3 — the exchange subject

### Task 5: `shift_swaps`, the subject and the check

**Files:**
- Create: `database/migrations/Scheduling/2026_11_11_200000_create_shift_swaps_table.php` (and the FK `shift_changes.shift_swap_id → shift_swaps.id`, restrict)
- Create: `app/Modules/Scheduling/Enums/ShiftSwapStatus.php` (`Pending = 1` «قيد الاعتماد», `Approved = 2` «معتمد», `Rejected = 3` «مرفوض», `Withdrawn = 4` «مسحوب», `Cancelled = 5` «ملغى»)
- Create: `app/Modules/Scheduling/Models/ShiftSwap.php` (implements `Approvable`)
- Create: `app/Modules/Scheduling/Contracts/BlockingLeave.php`
- Create: `app/Modules/Leave/Services/BlockingLeaveFromRequests.php` (implements it with `LeaveRequest::blocking()->overlapping()`)
- Create: `app/Modules/Scheduling/Services/ShiftSwapPlan.php`, `ShiftSwapCheck.php`, `ShiftSwapApprovalRoute.php`
- Modify: `app/Providers/AppServiceProvider.php` (bind `BlockingLeave`; morph alias `shift_swap`)
- Test: `tests/Feature/Scheduling/ShiftSwapCheckTest.php`, `tests/Feature/Scheduling/Concerns/SwapFixtures.php`

**Interfaces:**
- Consumes: `ExpectedShifts::dutyOn()`, `ScheduleResolver::runViolation()`, `App\Shared\Services\ManagementChain::resolve(Employee): ?Employee`, `EmployeeWorkSchedule::scopeInForceOn()`.
- Produces:
  - Table per spec §3.2. `ShiftSwap`: relations `requester()`, `partner()`, `approval()` (morphOne, as `LeaveRequest`), `changes()` (hasMany `ShiftChange`), `cancelledBy()`; casts the two dates, `status` → `ShiftSwapStatus`; `scopeInvolving(Builder, int $employeeId)` (requester or partner); `scopeLive(Builder)` (Pending or Approved).
  - `BlockingLeave::blocksAny(int $employeeId, string $from, string $to): bool` — pending or approved leave over any day.
  - `ShiftSwapPlan::overrides(ShiftSwap $swap): array<int $employeeId, array<string $date, ?int $shiftId>>` — from both duties (`dutyOn`): the requester's duty dates → null for him and his shifts' ids for the partner; the partner's the other way; on a date in both duties, the **taken** shift wins (§6.3). `ShiftSwapPlan::write(ShiftSwap $swap): void` — writes them as origin Swap rows with `shift_swap_id`. `ShiftSwapPlan::duties(ShiftSwap $swap): array{0: ?Duty, 1: ?Duty}` (requester's, partner's).
  - `ShiftSwapCheck::violation(ShiftSwap $swap, bool $atFiling): ?string` — rules 1–7 of §6.2 in order, rule 3 only when `$atFiling`, rule 5 excluding `$swap` itself when it has an id. Plus, before rule 2: a partner whose `termination_date` is on or before his duty's date → «الزميل المحدد ليس على رأس عمله.» (Review Focus 1). Messages:
    1. «لا يمكنك تبديل مناوبة مع نفسك.»
    2. «تبديل المناوبات متاح لموظفي الحراسة فقط.»
    3. «يجب أن يكون للزميل المدير المباشر نفسه.»
    4. «التاريخ المحدد ليس بداية مناوبة.» · «لا يمكن تبديل المناوبة بنفسها.» · «لا يمكن تبديل مناوبة بدأت.»
    5. «إحدى المناوبتين ضمن طلب تبديل آخر.» · «يوجد تعديل على أحد أيام التبديل.»
    6. «توجد إجازة تتداخل مع إحدى المناوبتين.»
    7. `runViolation()`'s message.
  - `ShiftSwapApprovalRoute::make(): ApprovalRoute` — `ApprovalRoute::sequential(RejectionPolicy::Ends, StepDefinition::directManager())`.
  - `ShiftSwap` as `Approvable`: `approvalRequester()` = requester; `canBeApproved()` = `ShiftSwapCheck::violation($this, false)`; `describeForApprover()` titles «طلب تبديل مناوبة جديد» (Submitted), «دورك لمراجعة طلب تبديل مناوبة» (YourTurn), «تم إسنادك لاعتماد طلب تبديل مناوبة» (both Reassigned), bodies «{requester} طلب تبديل مناوبة مع {partner}.»; `approvalSummary()` = title «تبديل مناوبة», summary «{requester} ↔ {partner} · {requester_duty_date} ⇄ {partner_duty_date}»; `approvalSummaryRelations()` = `['requester', 'partner']`; `approverRowDetails()` = `[]`. `onApprovalDecided()` here only mirrors the status; Task 6 adds the withdrawal's notice, Task 7 the approval's rows and the decision notices.
  - `SwapFixtures` (test trait): uses `LeaveFixtures::buildLeaveTree()`; `guardA` and `guardB` in unit A1 (manager: `headA`, by climbing), `guardC` in B (another manager); `assignGuard(Employee, string $cycleStart)`. With the clock at `2026-11-13 10:00`: `guardA` cycle from 11-14 (duties 11-14, 11-20, …), `guardB` from 11-17 (11-17, 11-23, …).

- [ ] **Step 1: Write the failing tests** (an unsaved `ShiftSwap` passed to `violation(…, true)`)

```php
test_opposite_teams_pass()                          // A 11-20 ⇄ B 11-17 → null
test_with_oneself_is_refused()                      // rule 1
test_a_partner_who_has_left_is_refused()            // B termination_date 11-16 → 'الزميل المحدد ليس على رأس عمله.'
test_an_office_partner_is_refused()                 // partner on OFFICE → 'تبديل المناوبات متاح لموظفي الحراسة فقط.'
test_another_manager_is_refused_at_filing_only()    // guardC → rule 3 message; violation($swap, false) skips rule 3
test_a_date_that_is_not_a_duty_start_is_refused()   // A 11-15 → 'التاريخ المحدد ليس بداية مناوبة.'
test_an_opened_duty_is_refused()                    // now 11-17 07:30, B 11-17 → 'لا يمكن تبديل مناوبة بدأت.'
test_a_duty_in_another_live_swap_is_refused()       // a pending ShiftSwap holding A 11-20 → rule 5 first message
test_an_hr_change_on_a_written_date_is_refused()    // Hr change for B on 11-21 → 'يوجد تعديل على أحد أيام التبديل.'
test_pending_leave_on_either_duty_is_refused()      // B pending leave 11-21 → rule 6
test_overrides_for_opposite_teams()                 // A: 11-20,21,22 → null; 11-17,18,19 → GUARD id; B the mirror
test_overrides_for_offset_duties_take_the_taken_shift() // a third guard from 11-15 ⇄ A 11-14: on 11-15, 11-16 each has the taken shift
test_the_route_is_one_direct_manager_step()
```

- [ ] **Step 2: Run them to see them fail** — `php artisan test --filter=ShiftSwapCheckTest`
- [ ] **Step 3: Implement** the migration, enum, model, contract and its Leave implementation, plan, check and route; bind and alias.
- [ ] **Step 4: Run them, then `MorphMapTest`** — Expected: PASS.
- [ ] **Step 5: Commit** (with the go-ahead) — `Model a duty exchange and the rules it must pass`

### Task 6: The guard's routes

**Files:**
- Create: `app/Modules/Scheduling/Services/ShiftSwapService.php` (`options`, `file`, `withdraw`)
- Create: `app/Modules/Scheduling/Controllers/MyShiftSwapController.php` (`options`, `index`, `store`, `show`, `cancel`)
- Create: `app/Modules/Scheduling/Requests/FileShiftSwapRequest.php`
- Create: `app/Modules/Scheduling/Resources/ShiftSwapResource.php`
- Create: `app/Modules/Scheduling/Support/SwapNotices.php`
- Modify: `app/Modules/Me/routes.php` (`GET shift-swaps/options`, `GET shift-swaps`, `POST shift-swaps`, `GET shift-swaps/{swap}`, `POST shift-swaps/{swap}/cancel`; `whereNumber('swap')`; `options` before `{swap}`)
- Modify: `app/Shared/Support/NotificationEventCatalogue.php` (`SHIFT_SWAP_SUBMITTED = 'shift_swap.submitted'` «تم تقديم طلب تبديل المناوبة», `SHIFT_SWAP_REQUESTED = 'shift_swap.requested'` «طلب تبديل مناوبة معك», `SHIFT_SWAP_WITHDRAWN = 'shift_swap.withdrawn'` «سحب طلب تبديل المناوبة»)
- Test: `tests/Feature/Scheduling/MyShiftSwapsTest.php`

**Interfaces:**
- Consumes: Task 5's model, check, plan and route; `ApprovalService::submit(Model&Approvable, ApprovalRoute): Approval`, `ApprovalService::withdraw(Approval, User): Approval`.
- Produces:
  - `ShiftSwapService::options(Employee $me): array{my_duties: list<array>, partners: list<array{employee: array, duties: list<array>}>}` — duties that start within 31 days and have not opened; a duty is `{date, starts_at, ends_at, shifts: [ExpectedShift::toArray()]}`; partners are employees with a cycle schedule in force today, the same `ManagementChain` manager, and no termination before today, excluding me.
  - `ShiftSwapService::file(Employee $requester, User $by, array $data): ShiftSwap` — one transaction locking both employee rows **in ascending id order** (Review Focus 2); `violation($swap, true)` → `SchedulingException`; create Pending; `submit($swap, ShiftSwapApprovalRoute::make())`; after commit `SwapNotices::filed($swap)`.
  - `ShiftSwapService::withdraw(ShiftSwap $swap, User $by): ShiftSwap` — requester only, Pending only, else 422 «لا يمكن سحب هذا الطلب.»; the engine's `withdraw()`. `ShiftSwap::onApprovalDecided()` gains its withdrawal branch here: status Withdrawn, `decided_at`, then `SwapNotices::withdrawn($this)` (Task 7 adds the other branches).
  - `SwapNotices::filed(ShiftSwap)` — requester: title «تم تقديم طلب تبديل المناوبة», body «طلبك لتبديل مناوبة {requester_duty_date} مع {partner} بانتظار اعتماد المدير المباشر.»; partner: title «طلب تبديل مناوبة معك», body «طلب {requester} تبديل مناوبة {partner_duty_date} معك مقابل مناوبته {requester_duty_date}.». `SwapNotices::withdrawn(ShiftSwap)` — partner: «سحب {requester} طلب تبديل المناوبة معك.». A person without an active account is skipped. Reference: the swap.
  - `ShiftSwapResource`: `id, status, status_label, reason, requester: {id, name}, partner: {id, name}, requester_duty: duty|null, partner_duty: duty|null, approval_id, decided_at, cancelled_at, cancel_reason, created_at, my_role: 'requester'|'partner'|null` (null on HR's routes).
  - `FileShiftSwapRequest`: `my_duty_date` date, `partner_id` exists in employees, `partner_duty_date` date, `reason` nullable ≤ 1000.
  - Store 201 `data.shift_swap`; show 404 for anyone but the two; index paginated (`per_page` ≤ 50), newest first, `involving()`.

- [ ] **Step 1: Write the failing tests**

```php
test_options_lists_my_duties_and_my_colleagues()   // now 11-13 10:00, A: my_duties start 11-14, 11-20, 11-26, …; partners: B only (not C, another manager)
test_a_guard_files_an_exchange()                   // POST A 11-20 ⇄ B 11-17 → 201, status 1; approvals row with one pending step for headA
test_filing_tells_both()                           // notifications: A 'shift_swap.submitted', B 'shift_swap.requested'
test_a_partner_without_an_account_is_not_told()    // Review Focus 1: B's user deleted → 201, one notification (A's)
test_each_refusal_is_a_422_with_its_message()      // rule 4 opened duty, rule 6 leave — through the API
test_a_second_exchange_for_the_same_duty_is_refused() // Review Focus 2: two sequential POSTs naming A 11-20 → second 422 'إحدى المناوبتين ضمن طلب تبديل آخر.'
test_both_see_it_and_nobody_else_does()            // GET show as A, as B → 200 with my_role; as C → 404
test_the_requester_withdraws_while_pending()       // POST cancel as A → 200 status 4; B told 'shift_swap.withdrawn'; as B → 422 'لا يمكن سحب هذا الطلب.'
test_the_list_is_mine_newest_first()
```

- [ ] **Step 2: Run them to see them fail** — `php artisan test --filter=MyShiftSwapsTest`
- [ ] **Step 3: Implement**
- [ ] **Step 4: Run them, then the catalogue test** — Expected: PASS; route count **119**.
- [ ] **Step 5: Commit** (with the go-ahead) — `Let a guard ask to exchange a duty`

---

## Step 4 — the outcome

### Task 7: Approval writes the rows; HR lists and cancels

**Files:**
- Modify: `app/Modules/Scheduling/Models/ShiftSwap.php` (`onApprovalDecided`)
- Modify: `app/Modules/Scheduling/Services/ShiftSwapService.php` (`cancel`)
- Modify: `app/Modules/Scheduling/Support/SwapNotices.php` (`decided`, `cancelled`)
- Create: `app/Modules/Scheduling/Controllers/ShiftSwapController.php` (`index`, `show`, `cancel`)
- Create: `app/Modules/Scheduling/Requests/ShiftSwapsIndexRequest.php`, `CancelShiftSwapRequest.php`
- Modify: `app/Modules/Scheduling/routes.php` (`GET swaps` view, `GET swaps/{swap}` view, `POST swaps/{swap}/cancel` manage; names `swaps.index|show|cancel`)
- Modify: `app/Shared/Support/NotificationEventCatalogue.php` (`SHIFT_SWAP_APPROVED = 'shift_swap.approved'` «تمت الموافقة على تبديل المناوبة», `SHIFT_SWAP_REJECTED = 'shift_swap.rejected'` «تم رفض تبديل المناوبة», `SHIFT_SWAP_CANCELLED = 'shift_swap.cancelled'` «تم إلغاء تبديل المناوبة»)
- Test: `tests/Feature/Scheduling/ShiftSwapOutcomeTest.php`

**Interfaces:**
- Consumes: `ShiftSwapPlan::write()`, `ShiftSwapPlan::duties()`, the approval routes `POST /api/approvals/{approval}/steps/{step}/approve|reject`.
- Produces:
  - `onApprovalDecided(Approval)` completed: Approved → status Approved, `decided_at`, `ShiftSwapPlan::write($this)`; Rejected → status Rejected, `decided_at`; then `SwapNotices::decided($this)` to both people — «تمت الموافقة على تبديل مناوبتك {my date} مع {other}.» (type approved) / «تم رفض طلب تبديل المناوبة بين {requester} و{partner}.» (type rejected). The withdrawal branch is Task 6's.
  - `ShiftSwapService::cancel(ShiftSwap $swap, User $by, string $reason): ShiftSwap` — Approved only («لا يمكن إلغاء إلا تبديل معتمد.»); refused once the earlier of the two duties' `opensAt` ≤ now («لا يمكن إلغاء تبديل بدأت إحدى مناوبتيه.»), the duties read from the swap's `changes` (the first change date with a shift, less `check_in_opens_minutes`); deletes `changes()`, sets Cancelled, `cancelled_by`, `cancelled_at`, `cancel_reason`, in one transaction; after commit `SwapNotices::cancelled()` to both: «ألغت الموارد البشرية تبديل المناوبة بين {requester} و{partner}.».
  - `ShiftSwapsIndexRequest`: `status` in enum, `employee_id`, `from`/`to` (span ≤ 92) on either duty date, `per_page` ≤ 100. `CancelShiftSwapRequest`: `reason` required ≤ 1000.

- [ ] **Step 1: Write the failing tests**

```php
test_approval_writes_twelve_rows()                 // A 11-20 ⇄ B 11-17, headA approves → 12 Swap rows; A: shifts 11-17,18,19, none 11-20..22; B the mirror
test_each_guard_works_six_days_as_two_duties()     // A works 11-14..11-19 (own duty, then B's): in 11-14 07:30, out 11-17 08:00, in 11-17 08:01
                                                   // → second check-in shift_date 11-17; the grid shows 6 present shifts
test_approval_is_refused_when_leave_arrived_after_filing() // B files leave 11-21 after filing → approve 422 with rule 6's message; status still 1, 0 rows
test_rejection_writes_nothing_and_tells_both()     // 0 rows; two 'shift_swap.rejected' notifications
test_another_manager_cannot_decide()               // headB approves → 403
test_it_shows_in_the_managers_inbox()              // GET /api/me/approvals as headA → a row with summary title 'تبديل مناوبة'
test_hr_lists_and_shows_swaps()                    // scheduling.view: filter status=2 and employee_id=B → one; my_role null
test_hr_cancels_before_the_duty_opens()            // now 11-16 10:00 → 200, status 5, 0 rows, two 'shift_swap.cancelled'; A's 11-20 shift back from the schedule
test_hr_cannot_cancel_after_a_duty_opens()         // now 11-17 07:00 → 422 'لا يمكن إلغاء تبديل بدأت إحدى مناوبتيه.'
test_hr_cannot_cancel_a_pending_swap()
test_leave_after_an_approved_swap_charges_the_new_days() // A files leave 11-17..11-19 → requested_days 3
test_an_hr_change_cannot_overwrite_swap_days()     // POST shift-changes for B on 11-21 → 422
```

- [ ] **Step 2: Run them to see them fail** — `php artisan test --filter=ShiftSwapOutcomeTest`
- [ ] **Step 3: Implement**
- [ ] **Step 4: Run the suite and Pint** — `php artisan test` and `vendor/bin/pint --test`; Expected: all PASS; route count **122**.
- [ ] **Step 5: Commit** (with the go-ahead) — `Write an approved exchange into both guards' days`

---

## Step 5 — documentation

### Task 8: The API document, Postman and the project docs

**Files:**
- Modify: `docs/API_DOCUMENTATION.md` — in the work shifts section (§19): changes (the three HR routes, the re-match and its refusals), exchanges (the five `/api/me` routes, the three HR routes, the statuses, the check's messages), the calendar's new `source` values, the `shift_swap` subject in the approvals section, the eight notification types; request and response examples as valid JSON; the route table; the count **122**.
- Modify: `postman/` collection — the 11 requests.
- Modify: `docs/START_HERE.md` — §2 status (122 routes, 48 permissions, the test count), §6 the two tables, §7 the routes, §10 item 0: piece 2 built.
- Modify: `docs/EMPLOYEE_APP_V1_WORK_ORDER.md` — a §8 entry for piece 2, with the answers of spec §1.2 and the final counts.

- [ ] **Step 1: Write the docs**
- [ ] **Step 2: Verify the counts** — routes → 122 (the command in Task 4); `php artisan tinker --execute='echo App\Modules\Auth\Models\Permission::count();'` → 48 after `db:seed --class=PermissionSeeder`; the documented test count equals `php artisan test`'s.
- [ ] **Step 3: Commit** (with the go-ahead) — `Document one-off changes and duty exchanges`
