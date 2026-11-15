# Work shifts — per-shift rules: design

> **Status:** design, agreed section by section on 2026-11-11. Not built.
> **Where it sits.** Work shifts so far: (1) the backend core, (2) one-off
> changes and duty exchanges — both merged (`6493015`); (3) the dashboard's
> screens — built on `work-shifts-dashboard`, uncommitted; (4) the app — not
> built. **This piece comes before the app**, since the app will show its
> fields.
> **It reopens** decisions S4, S5, S10 and S11 of
> [`DECISION_ROUND_WORK_SHIFTS.md`](../../DECISION_ROUND_WORK_SHIFTS.md)
> (2026-11-09), which made these rules one company-wide set.
> **Repositories:** the backend (this one) and `../erp-dashboard`.

---

## 1. Intent

**What it is for.** Today every shift is judged by one company-wide set of
rules in `config/attendance.php`: a 15-minute grace for late arrival and early
departure (S4), check-in opening 60 minutes before a shift (S10), the day
closing 4 hours after it (S5/S10), and no overtime (S11). Shifts in the company
differ — guards and office staff are not judged alike — so HR sets these rules
**per shift**, on the shift form, and adds overtime and required hours.

**Who it is for.** HR, who define shifts; everyone whose day is judged by one.

**What success looks like.**

- HR gives the guards' shift and the office shift different graces, windows,
  overtime and required hours on the shift form.
- Check-ins, the reminder and the close, every report and `/api/me` follow
  each shift's own rules — and the rules **in force on that day**, so editing
  them never changes a past day.
- Nothing changes for anyone until HR edits a shift: the existing shifts carry
  today's values.

### 1.1 Decided while designing (2026-11-11)

| # | Question | Answer |
|---|---|---|
| R1 | Should these be per shift? (reopens S4, S5, S10, S11) | **Yes.** |
| R2 | What is the "late check-out allowance"? | **The acceptance window**: how long after a shift's end a check-out is still accepted (today's 4-hour close), per shift. Staying late is the overtime rule's business. |
| R3 | How is overtime counted? | **After the shift's end, past a minimum**: once the minutes after the end reach the shift's minimum, all of them count. Arriving early is not overtime. |
| R4 | What happens to counted overtime? | **Counted and shown only** — on the daily board, the monthly report and the employee's days, with totals. No approval and no pay until payroll (Stage 4). |
| R5 | What do required hours do? | **Flag a short day**: minutes worked within the shift below its required minutes mark the day short by the difference. No penalty. |
| R6 | One grace or two? | **Two**: a late-arrival grace and an early-departure grace. |
| R7 | Editing a used shift's rules | **Dated rules**: an edit is a new version from a date HR picks (today or later); past days keep the rules they had. |
| R8 | Approach | **Dated rule versions in a table of their own** (not columns plus a history log; not rules on the schedule, which a one-off change or an exchange bypasses). |

### 1.2 Not in this piece

- Pay or deduction of any kind (Stage 4), and any approval of overtime (R4).
- Changing a used shift's **start or length**: still fixed once used — a new
  time is a new shift, as today. Only the rules are dated.
- Rules per person or per schedule.
- An end time stored by the API: the dashboard turns the entered end into a
  length (§5.2), so the shift keeps one source of truth.
- The app's screens (piece 4) — it will show the new fields when built.

---

## 2. Data

### 2.1 `shift_rules` (module `Scheduling`)

| Column | |
|---|---|
| `id` | |
| `shift_id` | FK `shifts`, cascade on force delete |
| `effective_from` | date; **unique with `shift_id`** |
| `late_grace_minutes` | 0–120 — a check-in later than start + this is late (today 15) |
| `early_leave_grace_minutes` | 0–120 — a check-out earlier than end − this is early (today 15) |
| `check_in_opens_minutes` | 0–240 — check-in opens this long before the start (today 60) |
| `check_out_closes_minutes` | 0–480 — a check-out is accepted until this long after the end; then an open check-in becomes a missing check-out (today 240) |
| `overtime_enabled` | boolean (today false) |
| `overtime_min_minutes` | 0–240 — the minimum that makes minutes after the end overtime |
| `required_minutes` | 1 … the shift's `duration_minutes` — below it, a completed day is short (today = the length) |
| `created_by` | FK `users`, nullable |
| timestamps | |

**In force on a date:** the version with the latest `effective_from` on or
before that date. Every shift has a version from its first day, so there is
always one.

### 2.2 Seeding and defaults

- A **data migration** gives every existing shift (`DAY`, `GUARD`, and any
  other) one version from **`2000-01-01`** with today's values: 15 / 15 / 60 /
  240, overtime off, minimum 0, required = its length. Behaviour is unchanged
  until HR edits.
- `config/attendance.php`'s `grace_minutes`, `check_in_opens_minutes` and
  `close_after_minutes` stay, **only** as the defaults of a new shift's first
  version (and of the migration). No reader judges a day by them any more.
- A new shift's first version is effective from `2000-01-01` too — the shift
  did not exist before, so nothing earlier can be affected.

### 2.3 Versions

- **Adding:** `effective_from` today or later (Damascus). A version on a date
  that already has one is refused.
- **Deleting:** only a version that has not started (`effective_from` after
  today) — never the first, never one in force.
- **Required minutes and a shift's length:** the length cannot change once the
  shift is used, so a version's `required_minutes` is validated against the
  length it has.

---

## 3. Reading the rules

### 3.1 One value object, carried on the expected shift

`ShiftRules` (readonly): the seven values above. The resolver builds each
`ExpectedShift` with the `ShiftRules` in force on its date
(`ExpectedShift::$rules`), loading the versions of the shifts it touches once
per call — no query per day.

### 3.2 The six readers switch to it

Each of these reads `config('attendance.…')` today and reads the shift's rules
instead:

| Reader | Uses |
|---|---|
| `Duty::of` / `Duty::runs` | opening window, close |
| `AttendanceDays` (reports) | opening window, close, graces |
| `AttendanceShiftClock` (reminder, close) | opening window, close |
| `ScheduleResolver` (matching a check-in to a shift) | opening window, close |
| `ShiftSwapPlan`, `ShiftSwapService` (an exchange's "opened" checks) | opening window, close |

`Duty::of`/`runs` stop taking minutes as arguments: they read their shifts.

### 3.3 A duty of several shifts

A guard's duty is up to three back-to-back shifts with one check-in and one
check-out:

- **opening window and late grace** — from its **first** shift;
- **close, early-departure grace and overtime** — from its **last** shift;
- **required minutes** — each shift on its own share of the hours (§4.4).

### 3.4 Overlapping windows

Wider windows can make one duty's close reach into the next duty's opening
window. Matching a check-in then keeps today's rule (the resolver's existing
precedence); the limits in §2.1 keep the overlap bounded.

---

## 4. What is computed

All at read time, from the rules in force on the day; nothing is deducted.

### 4.1 Late

A check-in later than start + `late_grace_minutes` is late by **the whole
minutes since the start** (as today: 09:16 under a 15-minute grace is 16
minutes late). On a duty, on its first shift with presence.

### 4.2 Early departure

A check-out earlier than end − `early_leave_grace_minutes` is early by **the
whole minutes before the end**. On a duty, on its last shift.

### 4.3 Overtime

Only when the shift's `overtime_enabled`. Let *after* be the minutes between
the end (a duty's last shift) and the check-out:

- *after* ≥ `overtime_min_minutes` → `overtime_minutes` = *after* (all of it);
- *after* < the minimum → 0;
- a missing check-out → 0.

With a minimum of 30: out 25 minutes late → 0; 40 minutes late → 40. Arriving
early is never overtime; it stays in `minutes_outside_shift`.

### 4.4 Short day

On a day with both a check-in and a check-out: if the minutes worked **within
the shift** (`minutes_within_shift`) are below `required_minutes`, the day is
short by the difference (`short_minutes`). Overtime and early arrival do not
make up for it. With required 7 h on an 8 h shift, 30 minutes late is not
short. A missing check-out, leave, an excuse or an absence is never short.

### 4.5 Report fields (additive; nothing removed)

- A day row (daily board, the employee's days, `/api/me` days) gains
  `overtime_minutes` and `short_minutes` (0 when none).
- A monthly row and `totals` gain `overtime_count`, `overtime_minutes`,
  `short_count`, `short_minutes`.
- Neither is a monthly sort key (as late and early, API §14.2).

---

## 5. API and the dashboard

### 5.1 API (`scheduling.view` reads, `scheduling.manage` writes)

- `POST /api/scheduling/shifts` additionally takes the rule fields (§2.1
  names, without `effective_from`); each one omitted takes the default
  (§2.2). They become the first version.
- The shift object gains `rules` (in force today). `GET
  /api/scheduling/shifts/{shift}` adds `rule_versions`, newest first, each with
  `id`, `effective_from`, the values, `created_by {id, name}` (the account's
  employee's name).
- `POST /api/scheduling/shifts/{shift}/rules` — `effective_from` plus every
  rule field (all required). **201** «تم حفظ قواعد الوردية.».
- `DELETE /api/scheduling/shifts/{shift}/rules/{rule}` — **200** «تم حذف
  قواعد الوردية.».
- **422s:** «لا يمكن أن تبدأ القواعد قبل اليوم.» · «توجد قواعد لهذه الوردية من
  التاريخ نفسه.» · «لا يمكن حذف قواعد بدأ العمل بها.» · «الساعات المطلوبة لا
  تتجاوز طول الوردية.» · the range of each field.

### 5.2 The shift form

- **Times:** «البداية» and «النهاية». An end at or before the start reads
  «ينتهي في اليوم التالي»; equal start and end is 24 hours. The length is shown
  and is what the API receives (a pure, tested helper). Locked once the shift
  is used, as today (the server's refusal shows).
- **Rules:** «مهلة التأخر», «مهلة الانصراف المبكر», «فتح التسجيل قبل البداية»,
  «قبول الانصراف بعد النهاية», «احتساب العمل الإضافي» (switch) with «الحد
  الأدنى للعمل الإضافي», «الساعات المطلوبة» — minutes, or hours and minutes for
  the required hours; prefilled with the defaults on a new shift.
- **Editing a shift:** its rule versions as a list (from, the values, who),
  «قواعد جديدة من تاريخ» (a date today or later and every field, prefilled with
  the rules in force), and «حذف» on a version not yet started.
- **The shifts list** gains an «عمل إضافي» badge on shifts whose rules in force
  enable it.

### 5.3 The attendance screens

«عمل إضافي» and «نقص ساعات» columns next to «تأخر» and «انصراف مبكر» on the
daily board, the monthly report (counts, minutes, totals) and the employee's
days, through the existing minutes formatter.

---

## 6. Build order

1. **Backend — rules in, behaviour unchanged.** The table, the model, the data
   migration, `ShiftRules` on `ExpectedShift`, and the six readers switched.
   **Every existing test passes unchanged** — the proof that nothing moved.
2. **Backend — the rules API** (§5.1) and the shift resource.
3. **Backend — the two graces, overtime and short days** (§4) and the report
   and `/api/me` fields.
4. **Dashboard** — the shift form (§5.2) and the attendance columns (§5.3).
5. **Docs** — a dated entry in `DECISION_ROUND_WORK_SHIFTS.md` recording that
   S4, S5, S10 and S11 were reopened on 2026-11-11 and answered as R1–R7; API
   docs §12, §14, §19; `START_HERE.md`; the work order §8.

**Branch:** `work-shifts-rules` in both repositories, from piece 3 once it is
committed. Nothing is committed or pushed without the owner's say.

**Deploy:** `php artisan migrate` (the table and its seed). No new permission.

---

## 7. Testing

- **Step 1:** the whole existing suite unchanged; a migration test that every
  shift has a version from 2000-01-01 with today's values.
- **Rules in force:** a version from a future date leaves earlier days exactly
  as they were; the latest version on or before a date wins.
- **Graces:** late at 15 vs 16 minutes; the two graces set apart (late 5,
  early 20) judged separately.
- **Windows:** a per-shift opening window and close change which check-in
  matches, when the reminder fires and when the day closes.
- **Overtime:** off → 0; on with minimum 30 → 29 is 0, 30 is 30, 40 is 40; a
  missing check-out → 0.
- **Short:** required below the length; late arrival within it not short;
  early arrival not offsetting.
- **A duty:** first shift's opening and late grace, last shift's close, early
  grace and overtime.
- **API:** every 422 in §5.1; deleting a started or the first version refused.
- **Dashboard:** `node --test` on the end-time ⇄ length helper (overnight,
  24 h), the rules form body, the version-delete rule; the build and eslint
  gates of piece 3.
