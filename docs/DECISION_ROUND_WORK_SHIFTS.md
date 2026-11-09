# Decision round — work shifts

> ✅ **CLOSED 2026-11-09 — all 13 answered the day the round opened.** The
> answers are on each `Answer:` line below and are recorded, with what they
> change, in [`EMPLOYEE_APP_V1_WORK_ORDER.md`](EMPLOYEE_APP_V1_WORK_ORDER.md) §8
> (entry "2026-11-09 — work shifts: the decision round, answered"). Build from
> the work order; this file is the record of what was asked and why. Nothing in
> it is built yet.
>
> **Where the answers left the recommendation:** S2 (no rotating, flexible or
> dated hours), S3 (the default is a day schedule with Friday off, not today's
> rules) and S13 (swaps now, approved by the direct manager).
>
> **Why a round.** Work shifts reopen three answers the owner ratified on
> 2026-10-16 in [`DECISION_ROUND_ATTENDANCE_LEAVE.md`](DECISION_ROUND_ATTENDANCE_LEAVE.md):
> **Q8** (no weekly day off, no holiday calendar), **Q9** (no "late") and
> **Q10** (one reminder at 17:00, one close at 23:59 for everyone). Q9's
> option C — "a start time per unit or per shift — a bigger build" — is what
> shifts are. The code is built on those answers, so they are put back rather
> than changed silently.
>
> **The rule since 2026-10-16:** legacy (`../Raqi-App`) is the foundation, and
> nothing is defaulted by date. Legacy has **no shifts**: its own code calls it a
> "single-shift model: no overnight, no multiple shifts"
> (`app/Services/AttendanceService.php`), exactly as the ERP has it today.
>
> **How to answer:** write the letter (or your own answer) on each `Answer:`
> line. Answered questions move into
> [`EMPLOYEE_APP_V1_WORK_ORDER.md`](EMPLOYEE_APP_V1_WORK_ORDER.md) §8, and this
> file is closed.
>
> **Evidence.** Usage counts from the local copy of the legacy database:
> `attendance_records`, check-ins from 2026-07-03 to 2026-10-22. Counts only —
> no personal data.

---

## What the legacy data shows about the guards

إدارة الأمن والحماية is the only unit that works at night, and the single-shift
model does not fit how it works:

| | Guards (20 people) |
|---|---|
| Check-ins | 542 |
| … ending as a **missing check-out** | **220 (41%)** — the company-wide rate is 20% (1,429 of 7,047) |
| Check-ins between **00:00 and 01:59** | 229 — the minute after midnight is the most common (00:00–00:04) |
| Check-outs between **22:00 and 23:59** | 183 |
| Days recorded at 20–24 hours | 112 of 326 completed days |
| Runs of consecutive days present | 4 days (44 times) and 3 days (35) are the most common |
| Rest between runs | 2 days (53 times) and 3 days (36) are the most common |

Read together: the guards work **continuous duties of about three days**, and
because the system's day ends at midnight they **cut each duty at midnight** —
a check-out before 23:59, a new check-in after 00:00 — or leave the check-in
open and get a missing check-out. A 72-hour duty that starts mid-morning touches
four calendar days and leaves two whole days free, which is the 4-and-2 pattern
above; one that starts at midnight gives 3 and 3.

The other 54 night check-ins are from إدارة المشاريع الانتاجية, which is not
imported into the ERP (work order §8, 2026-10-14).

**The ERP today repeats legacy exactly.** A check-out must fall on the same date
as its check-in, one check-in per date, and the 23:59 close flags anything still
open. A guard moving to the ERP on today's rules would produce the same 41%.

---

## Contents

**A. Whether, and when** — S1 before or after the pilot · S2 which working patterns exist
**B. The answers of 2026-10-16, reopened** — S3 days off, and people with no schedule · S4 late and early departure · S5 the reminder and the close · S6 public holidays
**C. Guards** — S7 the cycle · S8 how a guard records a duty
**D. Counting** — S9 leave on a schedule · S10 which shift a check-in belongs to · S11 the hours a shift counts
**E. Running it** — S12 who maintains schedules · S13 one-off changes and swaps

Not asked, because they are fixes rather than choices:

- **The attendance day becomes the shift, not the calendar date.** It is the
  precondition for any overnight or 24-hour shift, whatever is answered below.
- **One timezone stays.** The company works in one; Syria has kept UTC+3 all
  year since 2022, so there is no daylight saving to handle.
- **Schedules keep their history.** Editing a schedule makes a new version from
  a date; a past day is judged by the version in force on it.
- **Records stay an event log.** Nothing about a day is stored that can be
  derived from the records and the schedule, as today.

---

## A. Whether, and when

### S1 — Shifts before the pilot, or after it?

**Today.** The pilot group is the technical department and HR, about 15–25
people (D12, 2026-10-16): **no guards, and nobody on night hours.** Today's
rules fit them. Shifts touch all three repositories — the ERP, the dashboard's
daily board and grid, the app's check-in, home card, "my days" and offline
queue — and the app's slice 8 would be redone after them.

| | What it means |
|---|---|
| **A** | **Shifts first.** The pilot waits for them; slice 8 comes after |
| **B** | **The pilot first**, with the D12 group on today's rules. Shifts are built straight after, and the guards join the ERP once they land. Until then the guards stay on legacy |
| **C** | **The pilot first, guards included** on today's rules — they repeat legacy's midnight cutting and missing check-outs |

**Recommended: B**, unless the pilot group is changed to include guards — then
A. Nothing the D12 group does needs a shift, and B tests the app sooner without
building anything twice. C is listed only to be ruled out.

`Answer:` **A** (2026-11-09) — the app's release is delayed; shifts are built first, and slice 8 comes after them.

### S2 — Which working patterns exist in the company today?

The plan names five. Each costs building and testing, so only the ones people
actually work are built. For each, say **who** (a unit, or a number of people),
or "none":

| Pattern | Example | Who works it? |
|---|---|---|
| **Fixed day hours** | 08:00–16:00 on given weekdays | |
| **Fixed night hours** (past midnight) | 22:00–06:00 | |
| **The guards' cycle** | 3 × 24 h on, 3 days off, regardless of weekday | إدارة الأمن والحماية (see above) — confirm |
| **Rotating** | a week of mornings, then evenings, then nights | |
| **Flexible start** | start between 08:00 and 10:00, work 8 hours | |
| **Dated** | different hours on dates HR lists one by one | |

**Recommended:** build fixed hours (day and night) and the guards' cycle first,
and the others only when this table names someone who works them. Rotating and
dated are cheap once the cycle exists; flexible is a different matching rule.

`Answer:` **Fixed day hours, fixed night hours, and the guards' cycle** (confirmed in S7: one check-in and one check-out per 72-hour duty). **No rotating, flexible or dated hours** — not built.

---

## B. The answers of 2026-10-16, reopened

### S3 — Days off, and people with no schedule — reopens *Q8.1*

**Today.** Every day of the week is a working day for everyone (Q8: "no weekly
day off"): a day with no check-in is absent, and a leave over a Friday charges
the Friday.

With schedules, a day off is whatever a person's schedule says it is — a guard's
three rest days, or a Friday if HR gives an office schedule one. The question is
what everyone **without** a schedule gets — which on the first day is everyone
the import brings in.

| | What it means |
|---|---|
| **A** | **No schedule = today's rules**: all seven days working, check-in open all day, the day closes at 23:59. Days off exist only inside schedules HR assigns |
| **B** | A company-wide weekly day off for everyone without a schedule (say which day) |

**Recommended: A.** It changes nothing for anyone until HR assigns them a
schedule, so shifts can land without a migration of people.

`Answer:` **Neither as written: the default is a fixed day schedule, 09:00–16:00, Saturday to Thursday.** Friday is a day off for everyone without another schedule — no absence, no leave charged. This reverses Q8.1 (no weekly day off) for everyone, and with S4 it means everyone unassigned can be late from 09:15.

### S4 — "Late" and "early departure" — reopens *Q9*

**Today.** Nobody is ever late (Q9 = A): check-in is open all day.

| | What it means |
|---|---|
| **A** | Still no late. Shifts are used only to pair the guards' and night workers' records correctly |
| **B** | **Recorded, no penalty:** a check-in after the shift's start plus a grace period is late by so many minutes, a check-out before its end is early by so many minutes. Shown on the reports and the employee's own days; nothing is deducted until payroll (Stage 4) has rules for it |
| **C** | B, with a deduction rule now (say which) |

If B or C: **how many grace minutes**, one value for every shift or per shift?

**Recommended: B**, with one grace value. Late and early are minutes, not
states — someone can be both on one day — so they sit beside the day's state
(present, absent, on leave…) rather than replacing it. A deduction is a pay rule
and belongs with the calculator.

`Answer:` **B** — recorded, no penalty, with **one grace of 15 minutes** for every shift, applied to arriving late and to leaving early.

### S5 — The reminder and the close — reopens *Q10*

**Today.** At 17:00 everyone still checked in gets a push to check out; at 23:59
every open check-in becomes a missing check-out with no hours. One time for the
whole company. A night shift cannot survive 23:59.

| | What it means |
|---|---|
| **A** | **Per shift:** the reminder at the shift's scheduled end, the close at the end plus a margin (say how many hours). People with no schedule keep 17:00 and 23:59 |
| **B** | Per shift only for shifts that cross midnight; everyone else keeps 17:00 and 23:59 |

**Recommended: A** — one rule for every shift, and S3's default keeps today's
times for everyone else. The 60-minute minimum between a check-in and its
check-out, and no check-in during approved leave, stay as they are.

`Answer:` **A** — per shift, **the close 4 hours after the shift's end**. Since S3 gives everyone a schedule, 17:00 and 23:59 go entirely: the default shift reminds at 16:00 and closes at 20:00. The 60-minute minimum and no check-in during approved leave stay.

### S6 — Public holidays — reopens *Q8.2*

**Today.** No holiday calendar (Q8: "not at this stage"); the work order defers
one to before payroll. Shifts make the question sharper: guards work through
Eid, an office schedule may not.

| | What it means |
|---|---|
| **A** | Still none. A holiday is handled as leave or an HR excuse, as today |
| **B** | A table HR fills. On a holiday a **weekly** schedule is off (no absence, no leave charged); the guards' cycle **ignores** holidays |

**Recommended: A for now**, and B before payroll as already planned — unless HR
needs Eid not to count against office staff's leave during the pilot. The code
keeps one hook for it (`AttendanceCalendar::isChargeableDay()`), so B is a
contained addition later.

`Answer:` **A** — still no holiday calendar; one comes before payroll, as planned.

---

## C. Guards

### S7 — The guards' cycle

The legacy data suggests about three days on duty and two to three days off.
Please confirm, for إدارة الأمن والحماية:

1. **The cycle:** three 24-hour shifts, then three days off, repeating regardless
   of the weekday? Or something else (say what)?
2. **When a duty starts:** at what time does the first 24-hour shift begin — the
   same time for every guard (say which), or does it differ by team or site?
3. **Teams:** how many groups take turns, and does each guard stay in one group?
   The ERP needs, per guard, the date their current cycle started.
4. **Does anyone else** work a cycle like this (drivers, site staff)?

`Answer:` 1 — **72 hours on duty, then 3 days off**: a 6-day cycle, regardless of the weekday · 2 — **every duty starts at 08:00** and ends at 08:00 three days later · 3 — teams differ only in the day their cycle starts, so the ERP keeps a cycle start date per guard · 4 — nobody else (S2).

### S8 — How a guard records a duty

**Legacy and the ERP today** force a check-out before midnight and a new
check-in after it (see the evidence above). With shifts, a duty can be recorded
the way it is worked:

| | What it means |
|---|---|
| **A** | **One check-in at the start of the three-day duty, one check-out at the end.** The ERP splits the time across the three 24-hour shifts. One selfie per duty |
| **B** | **A check-in and a check-out every 24 hours**, at the handover time of S7. One selfie a day |
| **C** | Both, chosen per team |

**Recommended: A**, if guards stay on site for the whole duty — it matches the
work, and the midnight cutting in the data is the system's doing, not theirs. If
HR wants a daily selfie as proof that the guard is still there, **B**. **Not C**:
two matching rules for 20 people doubles what has to be built and tested.

Either way: if the check-out is missing, which shifts of the duty count?
Recommended: **the ones the evidence covers** — with A, a missing check-out
leaves all three as missing check-outs for HR to correct, as a day is today.

`Answer:` **A** — one check-in at the start of the 72-hour duty and one check-out at its end; the ERP splits the time across the three 24-hour shifts. A missing check-out leaves **all three** as missing check-outs, with no hours, until HR corrects it.

---

## D. Counting

### S9 — Leave on a schedule

**Today.** Leave is charged on working days, and every day is one (Q8), so a
five-day leave charges five days, whatever the person was due to work.

With schedules, a guard on leave over a week was due on duty for some days and
off for the others.

| | What it means |
|---|---|
| **A** | **Charge only the days the person was scheduled to work.** A guard's rest days are never charged; nor is a Friday off, for someone whose schedule has one |
| **B** | Keep charging calendar days for everyone |

**Recommended: A.** It is what a day off means; B would make a guard pay leave
for days they were never due at work. People with no schedule are unaffected by
either (S3 = A).

And a guard's 24-hour shift on leave: **one day of leave**, or more?
Recommended: one — the balance counts shifts missed.

`Answer:` **A** — only scheduled working days are charged; Fridays off and a guard's rest days never are. A guard's whole 72-hour duty on leave charges **3 days** — one per 24-hour shift.

### S10 — Which shift a check-in belongs to

Three numbers, and one rule:

1. **The date of a shift past midnight.** A 22:00 Monday → 06:00 Tuesday shift
   belongs to **Monday** (the day it starts)? Recommended: yes — it is how the
   reports and the employee's own days will show it.
2. **How early** before a shift's start may someone check in for it (say how
   many minutes; recommended 60)?
3. **How late** after a shift's end does a check-out still count for it (say how
   many hours; the same margin as S5)?
4. **Outside every window** — a check-in that fits no scheduled shift, on a day
   off for instance: refused, or accepted and shown to HR as outside the
   schedule? Recommended: **accepted and shown**, as a refused day is today, so
   no real work goes unrecorded.

`Answer:` 1 — **the start date** (a guard's duty from Saturday 08:00 is Saturday's, Sunday's and Monday's shifts) · 2 — **60 minutes** before the start · 3 — **4 hours** after the end, the same as S5's close · 4 — **accepted and flagged** to HR as outside the schedule.

### S11 — The hours a shift counts

**Today.** A day's hours are its check-out minus its check-in.

| | What it means |
|---|---|
| **A** | Keep that: the hours are the time between the two |
| **B** | Show both: the time **within** the scheduled shift, and the time **outside** it (early arrival, staying late). Nothing is approved or paid as overtime until payroll (Stage 4) |

**Recommended: B.** Both come from the same two records, so nothing extra is
stored, and payroll gets what it needs to decide overtime later. Approving
overtime now would be a pay decision with no calculator behind it.

`Answer:` **B** — both the time within the shift and the time outside it; nothing approved or paid as overtime until payroll.

---

## E. Running it

### S12 — Who maintains schedules

| | What it means |
|---|---|
| **A** | **HR only**, company-wide: defines shifts and cycles, assigns them to people |
| **B** | HR defines; **unit managers assign** within their own unit |

**Recommended: A** to start. Assigning many people at once (a whole unit) is a
convenience inside A, not a rule: each person gets their own dated assignment,
so a transfer does not silently change anyone's schedule.

`Answer:` **A** — HR only, company-wide.

### S13 — One-off changes and swaps

Beyond a person's regular schedule:

- **A one-off change** — this person works nights on the 14th instead of days.
- **A swap** — two guards exchange a duty.

| | What it means |
|---|---|
| **A** | **One-off changes now, by HR**, recorded and audited. Swaps later |
| **B** | Both now. A swap is requested by a guard and **approved** — by whom? (Approvals run on the same engine as leave) |
| **C** | Neither now: HR edits the assignment |

**Recommended: A.** One-off changes are needed from the first week; swaps need a
request, an approval chain and screens in the app, and can follow once the
guards are on the ERP.

`Answer:` **B** — both now. One-off changes are recorded by HR. A swap is requested by a guard naming the other guard; **the other guard does not have to accept first**; it is approved in **one step by the direct manager** (the org tree's chain, as leave's first step). The approver decides for both.
