# Work shifts — one-off changes and duty exchanges: design

> **Status:** design, agreed section by section on 2026-11-10. Not built.
> **Piece 2 of 4.** Work shifts is built as four pieces, each with its own spec,
> plan and build: (1) the backend core — built on `work-shifts-core`, spec
> [`2026-11-09-work-shifts-backend-core-design.md`](2026-11-09-work-shifts-backend-core-design.md);
> **(2) this one, one-off changes and duty exchanges**; (3) the dashboard's
> screens; (4) the app.
> **The decisions it builds on:** S13 of
> [`DECISION_ROUND_WORK_SHIFTS.md`](../../DECISION_ROUND_WORK_SHIFTS.md), and the
> answers given while designing (§1.2). Section numbers "core §n" refer to the
> piece 1 spec.

---

## 1. Intent

**What it is for.** Piece 1 gives each person a schedule. Real weeks depart from
it: someone works nights on the 14th instead of days, a guard has the 20th off,
two guards trade duties. Today the only lever is the assignment, which starts
today or later and runs open-ended. This piece adds a person's shift **on a
date**, set two ways: **recorded by HR**, and **exchanged by two guards** with
their manager's approval.

**Who it is for.** HR, who correct and adjust days; the guards, who exchange
duties; their direct manager, who approves.

**What success looks like.**

- HR records "nights on the 14th" and every reader — check-in rules, the shift
  clock, the reports, `/api/me`, leave counting — sees the night shift, with no
  change in any reader.
- A past day HR corrects is re-judged: a check-in flagged outside the schedule
  under the wrong shift becomes the right shift's presence.
- A guard files an exchange from the app, the manager decides it in the same
  inbox as leave, and once approved both guards' check-ins, reports and leave
  follow it.
- HR can undo an approved exchange before it starts.

### 1.1 S13, as answered (2026-11-09)

> **B** — both now. One-off changes are recorded by HR. A swap is requested by a
> guard naming the other guard; **the other guard does not have to accept
> first**; it is approved in **one step by the direct manager** (the org tree's
> chain, as leave's first step). The approver decides for both.

### 1.2 Decided while designing (2026-11-10)

| | Decided |
|---|---|
| What an exchange is | **Two duties exchanged**: guard A names one of his duties and one of guard B's; A works B's, B works A's |
| Who may exchange | **Guards with the same direct manager**: both on a cycle schedule on the dates involved, and `ManagementChain` resolves both to the same manager |
| A past day with a check-in | **May be changed by HR**; each check-in the change touches is **matched again** by the check-in rule, as if it had happened under the new shift. Refused if something already recorded would have been refused |
| Undoing an approved exchange | **HR cancels it** before the earlier of its two duties opens. Both guards are told. After that, HR uses one-off changes |
| 144 hours in a row | **Allowed, as two duties** — an exchange between opposite teams gives each guard six days on; each 72-hour duty keeps its own check-in and check-out (S8) |
| Storage | **One table of per-date changes** (§3.1), written by HR and by approved exchanges; the resolver reads only it |
| Telling the employee of HR's change | **For today or later**, on recording and on removal. A correction of a past day is silent |
| A run's length | **At most six back-to-back shifts** (two duties). A change or exchange making a longer run is refused (§4.2) |

### 1.3 Not in this piece

The dashboard's screens (piece 3) and the app's (piece 4). A cover — one guard
taking another's duty with nothing in return. Exchanges for anyone not on a
cycle schedule. Custom hours on a change: a change uses a shift template (S2).
Bulk changes across many people. An exchange across managers. Any pay effect
(Stage 4).

---

## 2. Concepts

- **Change** — a person's shift on one date, overriding their schedule: a shift
  template, or a day off. Its **origin** is HR or an exchange.
- **Exchange** (code: *swap*) — a request by one guard (the **requester**) to
  trade one of his duties for one of another guard's (the **partner**). Named by
  the start date of each duty.
- **Taken duty** — in an exchange, the other person's duty that each guard will
  work. **Given duty** — his own, which becomes days off.
- **Run** — consecutive expected shifts, each starting exactly when the previous
  ends. The resolver cuts a run into duties of at most three (core §4.3).

---

## 3. Data model

### 3.1 `shift_changes` (module `Scheduling`)

| Column | |
|---|---|
| `employee_id` | restricts on delete |
| `date` | unique with `employee_id`: one change per person per date |
| `shift_id` | nullable: **null is a day off**. An active shift when written |
| `origin` | `ShiftChangeOrigin`: Hr = 1, Swap = 2 |
| `shift_swap_id` | nullable FK; set exactly when `origin` is Swap |
| `reason` | text; required for Hr, null for Swap |
| `created_by` | the account |

- **No soft delete.** Removing a change deletes the row; `HasAuditLog` records
  it. HR replacing a change on a date updates the row, so the log holds the old
  and the new values.
- **"In use" grows.** A shift a change references is in use, as one a schedule
  references is (core §3.1): its `starts_at` and `duration_minutes` are locked
  and deleting it is refused.

### 3.2 `shift_swaps` (module `Scheduling`)

| Column | |
|---|---|
| `requester_id`, `partner_id` | employees, restrict on delete |
| `requester_duty_date`, `partner_duty_date` | the start date of each one's duty |
| `reason` | optional, from the requester |
| `status` | `ShiftSwapStatus`: Pending = 1, Approved = 2, Rejected = 3, Withdrawn = 4, Cancelled = 5 |
| `decided_at` | when the approval ended |
| `cancelled_by`, `cancelled_at`, `cancel_reason` | HR's cancellation (§6.4) |
| `created_by` | the requester's account |

It implements `Approvable` (morph alias `shift_swap`); its status is written only
from `onApprovalDecided()`, and by HR's cancellation, which happens after the
approval has ended. Both models use `HasAuditLog`.

---

## 4. The resolver

### 4.1 A change comes first

Core §4.2 gains a step 0: **the person's change on the date, if any.** A shift
gives an expected shift from that template with `source` = `change` (origin Hr)
or `swap`; null gives a day off. Otherwise steps 1–3 as before.

`shiftsFor()` loads the changes for every id in the range in one more query,
with their shifts, so the batch read stays the same size (core §4.4). The
calendar's `source` gains `change` and `swap`.

### 4.2 Runs of up to six

Duties stay at most three shifts. A run of six — the opposite-team exchange of
§1.2 — is two duties back to back: the guard checks out at 08:00 on day four and
checks in for the next duty, and core §5.1's "earlier-starting duty without a
check-in" rule picks it.

Every reader finds a duty's start by looking back six days (the resolver, the
day grid, the shift clock, the assigner). That places the cuts correctly for any
run of at most six shifts, since a shift is one per date. So **a change or an
exchange that would leave anyone with more than six back-to-back shifts is
refused**: «يتجاوز هذا التعديل ست ورديات متتالية للموظف.» Checked on the person's
resolved shifts with the change applied, from six days before to six days after.
Piece 1's schedule rule (no run of more than three within a schedule) is
unchanged.

---

## 5. HR's one-off changes

### 5.1 Routes

| Method | Path | Permission |
|---|---|---|
| GET | `/api/hr/employees/{employee}/shift-changes?from&to` | `scheduling.view` |
| POST | `/api/hr/employees/{employee}/shift-changes` | `scheduling.manage` |
| DELETE | `/api/hr/employees/{employee}/shift-changes/{change}` | `scheduling.manage` |

- **GET** lists the person's changes of both origins in the range (at most 92
  days), with the shift, origin, reason, the exchange's id if any, and who
  recorded it.
- **POST** takes `from`, `to` (inclusive, at most 31 days), `shift_id` (or null
  for a day off) and `reason` (required). One row per date; a date with an HR
  change has it replaced.
- **DELETE** removes an HR change; the person returns to their schedule on that
  date. A change of origin Swap is refused: «هذا التعديل ناتج عن تبديل مناوبة؛
  يُلغى بإلغاء التبديل.»

### 5.2 Rules

- The shift is active.
- Dates may be past or future.
- **A date held by an exchange** — a change of origin Swap — is refused:
  «هذا اليوم ضمن تبديل مناوبة معتمد؛ يجب إلغاء التبديل أولاً.»
- No run longer than six (§4.2).
- **All or nothing.** One request is one transaction; a refused date refuses the
  request, and the message names the date.

### 5.3 Re-matching the check-ins a change touches

In the same transaction, after the rows are written (and after a removal):

1. Take the person's **accepted check-ins** whose `shift_date` falls from six
   days before `from` to one day after `to`, in order of `recorded_at`.
2. Match each by core §5.1 rule 3 under the new shifts: the first duty of
   `dutiesHolding(employee, recorded_at)` that no earlier check-in in this pass
   has claimed. It takes that duty's start date as `shift_date`, and
   `outside_schedule` = false. No duty holds the moment: `outside_schedule` =
   true and `shift_date` = its calendar date. Its check-out row, if any, copies
   the `shift_date`.
3. **Refuse the whole change** — nothing written — where the new shifts would
   have refused what is already recorded:
   - a check-in whose holding duties are all claimed, or a second
     outside-schedule check-in on one date: «يتعارض هذا التعديل مع تسجيل حضور
     في {date}.»
   - a check-out after its check-in's new close (core §5.2): «يقع تسجيل انصراف
     {date} بعد إغلاق الوردية الجديدة.»
4. **Flags follow the new shift.** A check-in with no check-out whose `status`
   is missing check-out and whose new close is still ahead has the flag cleared;
   one whose new end is still ahead has `checkout_reminded_at` cleared, so the
   reminder is sent at the new end. The shift clock (core §8) does the rest on
   its next run.

Refused check-ins keep their `shift_date` (core §5.4). Leave is not re-charged:
`requested_days` is the snapshot taken at filing (core §7); a change does not
lift the rule against checking in during approved leave.

### 5.4 Telling the employee

When a change for **today or later** is recorded or removed, the employee's
account is sent, for example, «تم تعديل ورديتك ليوم 2026-11-14: الوردية الليلية»
or «… يوم راحة», and on removal «عادت ورديتك ليوم 2026-11-14 إلى جدولك». One
notification per request, naming the range when it spans more than a day. Events
`shift.change_recorded` and `shift.change_removed` in
`NotificationEventCatalogue`. A change wholly in the past sends nothing.

---

## 6. Duty exchanges

### 6.1 The guard's routes (no permission: the person's own, as leave's)

| Method | Path | |
|---|---|---|
| GET | `/api/me/shift-swaps/options` | my duties starting in the next 31 days that have not opened, and each eligible partner with theirs |
| POST | `/api/me/shift-swaps` | `my_duty_date`, `partner_id`, `partner_duty_date`, `reason` (optional) |
| GET | `/api/me/shift-swaps` | exchanges where I am the requester or the partner, newest first, paginated |
| GET | `/api/me/shift-swaps/{swap}` | one of them — what a notification opens. Anyone else: 404 |
| POST | `/api/me/shift-swaps/{swap}/cancel` | withdraws it: the requester only, while pending, through the engine's `withdraw()` |

An eligible partner (for `options`) is another employee on a cycle schedule
today with the same direct manager. Each duty is shown with its start, its end
and its shifts.

### 6.2 The check (`ShiftSwapCheck`)

One service, run at filing and again from `canBeApproved()`. Each failure has
its own Arabic message, returned as a 422 at filing and as the engine's refusal
at approval.

1. The partner is someone else.
2. **Both are guards:** on every date of both duties, each person's assignment
   in force is a cycle schedule.
3. **The same direct manager:** `ManagementChain` resolves both to the same
   person. *Filing only* — the approver is fixed at submission.
4. Each date is the start of a duty of that person (`dutyOn`), the two duties
   differ, and **neither has opened**: each duty's `opensAt` is after now.
5. Neither duty is part of another pending or approved exchange (excluding this
   one), and neither person has a change on any date the exchange would write.
6. Neither person has pending or approved leave (`blocking`) on any date of
   either duty.
7. After the exchange, neither person has more than six back-to-back shifts
   (§4.2).

### 6.3 Approval

- **The chain:** `ApprovalRoute::sequential(RejectionPolicy::Ends,
  StepDefinition::directManager())`, built in `ShiftSwapApprovalRoute`. The
  requester's direct manager decides, in `/api/me/approvals`, through the
  existing `/api/approvals` routes; the engine's skips and refusals apply as for
  leave.
- **The subject's summary** for the inbox row: both names and both duties' spans.
- **On approval** (`onApprovalDecided`, inside the engine's transaction): read
  both duties' shifts as they stand, then write `shift_changes` rows of origin
  Swap:
  - for the requester — his given duty's dates → day off; the partner's duty's
    dates → the partner's shifts;
  - for the partner — the same, the other way round;
  - where the two duties overlap, a date takes the **taken** shift.
  No re-match is needed: neither duty has opened (§6.2, rule 4), so no check-in
  belongs to them.
- **Rejection and withdrawal** write nothing; the status mirrors the approval.

### 6.4 HR's routes

| Method | Path | Permission |
|---|---|---|
| GET | `/api/scheduling/swaps` | `scheduling.view` |
| GET | `/api/scheduling/swaps/{swap}` | `scheduling.view` |
| POST | `/api/scheduling/swaps/{swap}/cancel` | `scheduling.manage` |

- **The list** filters by `status`, `employee_id` (requester or partner), and
  `from`/`to` on either duty's date; paginated.
- **Cancel** takes `reason` (required). Only an approved exchange, and only
  before the earlier of its two duties opens: «لا يمكن إلغاء تبديل بدأت إحدى
  مناوبتيه.» It deletes the exchange's change rows, sets the status to Cancelled
  with `cancelled_by`, `cancelled_at` and `cancel_reason`, in one transaction.

### 6.5 Who is told

| Moment | Requester | Partner |
|---|---|---|
| Filed | a receipt | «طلب {name} تبديل مناوبة معك» |
| Approved | yes | yes |
| Rejected | yes | yes |
| Withdrawn | — his own act | yes |
| Cancelled by HR | yes | yes |

The manager gets the engine's own notices, in the subject's words: «طلب تبديل
مناوبة جديد», «{name} طلب تبديل مناوبة مع {partner}.» Events
`shift_swap.submitted`, `shift_swap.requested`, `shift_swap.approved`,
`shift_swap.rejected`, `shift_swap.withdrawn` and `shift_swap.cancelled`, with
the exchange as the reference so the tap opens §6.1's show route.

---

## 7. Totals

**11 new routes (122 in all)**: three for HR's changes, five for the guard,
three for HR's exchanges. **No new permission (48)**: HR's routes reuse
`scheduling.view` and `scheduling.manage`, company-only (core §9.1); managers
decide through the approvals engine.

---

## 8. Build order

Each step lands with the suite green.

1. **`shift_changes` and the resolver:** the table, `ShiftChange` and its rules
   (an active shift; origin and swap id agree; the run limit), "in use"
   extended, the resolver's step 0 and the calendar's sources.
2. **HR's one-off changes:** the three routes, re-matching (§5.3) and the
   notification (§5.4).
3. **The exchange subject:** `shift_swaps`, `ShiftSwap` as `Approvable`,
   `ShiftSwapCheck`, the chain, the five `/api/me` routes.
4. **The outcome:** rows on approval, HR's three routes and the cancellation,
   every notification of §6.5.
5. **Documentation:** `API_DOCUMENTATION.md`, `postman/`, `START_HERE.md` and
   the work order's §8.

---

## 9. Testing

Every case pins the clock (`Carbon::setTestNow`) in the attendance timezone.

- **Resolver:** a change wins over an assignment and over the default; a null
  change is a day off; the calendar shows `change` and `swap`; a run of six is
  two duties of three whatever window a reader asks for; a seventh back-to-back
  shift is refused.
- **HR's changes:**
  - a 21:30 check-in flagged outside under `DAY`, then a night change on that
    date → re-matched, not outside, the day present;
  - a change that leaves a recorded check-out after its new close → refused,
    nothing written;
  - a change over a date held by an exchange → refused; deleting a Swap-origin
    change → refused;
  - a replacement and a removal, each audited;
  - a missing-check-out flag cleared when the new close is ahead;
  - a past change sends nothing; a future one notifies, once per request.
- **Exchanges:**
  - opposite teams → each takes the other's three shifts, his own dates become
    days off, and each works six days as two duties with two check-ins;
  - duties offset by one day → the taken shift wins on the shared dates;
  - each rule of §6.2 refused at filing with its own message;
  - leave or an HR change added after filing → approval refused (422), nothing
    changes;
  - withdrawal while pending; by the partner → refused;
  - HR's cancellation before the duty opens deletes the rows; after → refused;
  - who is told at each moment of §6.5.
- **Leave:** a leave filed after an approved exchange charges the new days.
- **Approvals:** the exchange shows in the manager's inbox with its summary and
  is decided like leave; another manager cannot decide it.
