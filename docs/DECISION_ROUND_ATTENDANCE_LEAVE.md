   # Decision round — attendance, leave and approvals
   
   > ✅ **CLOSED 2026-10-16 — all 14 answered by the project owner.** The answers
   > are on each `Answer:` line below and are recorded, with what they change, in
   > [`EMPLOYEE_APP_V1_WORK_ORDER.md`](EMPLOYEE_APP_V1_WORK_ORDER.md) §3 and §8
   > (entry "2026-10-16 — the decision round, answered"). Build from the work order;
   > this file is the record of what was asked and why.
   
   > **Opened 2026-10-16** for the project owner, before §4.5–§4.7 of
   > [`EMPLOYEE_APP_V1_WORK_ORDER.md`](EMPLOYEE_APP_V1_WORK_ORDER.md) are built.
   > The rule since 2026-10-16: **legacy (`../Raqi-App`) is the foundation** — its
   > logic and workflows are kept unless a change is agreed here — and **nothing is
   > defaulted by date**.
   >
   > **How to answer:** write the letter (or your own answer) on each `Answer:`
   > line. Answered questions move into the work order's §3/§8 and this file is
   > closed.
   >
   >
   > ⚙️ **No data moves from legacy** (project owner, 2026-10-16). Legacy is the
   > foundation for how things *work*, not a source of records: no leave history,
   > balances, attendance or approvals are imported. The ERP starts empty and HR
   > enters what it needs. Q6 asks what that means at the switch.
   >
   > **Evidence.** Each question cites the legacy code, and usage counts from the
   > local copy of the legacy database: 236 active users, leave from 2026-06-24 to
   > 2026-09-24, attendance up to 2026-09-20. Counts only — no personal data. The
   > legacy code has not changed since 2026-09-21.
   
   ---
   
   ## Contents
   
   **A. The approval workflow** — Q1 who chooses the approvers · Q2 when the HR step collides
   **B. Leave rules** — Q3 leave types · Q4 default allocation · Q5 HR excuses · Q6 balances and leave at the switch
   **C. Attendance** — Q7 who records · Q8 working days and holidays · Q9 late · Q10 the daily times · Q11 retention, and legacy's cleanup · Q12 offline retries
   **D. Reach and rollout** — Q13 whose unit a record belongs to · Q14 the pilot
   
   Not asked, because they are fixes rather than choices: selfies move from the
   public disk to a private one (anyone with a legacy selfie URL can open it), and
   attendance records are never deleted (payroll reads them).
   
   ---
   
   ## A. The approval workflow
   
   ### Q1 — Who chooses a leave request's approvers? ⚠️ the biggest change from legacy
   
   **Legacy.** The employee picks them. `StoreLeaveRequest` takes an ordered list
   of 1–10 approvers, each any `manager` or `chief` in the company (26 people),
   never the employee themselves; only the first pending one may act. In practice:
   **178 of 189 requests had one approver**, 10 had two, 1 had four. 16 different
   people approved leave. The app ships an approver picker for this.
   
   **The ERP plan** (`EMPLOYEE_APP_REQUIREMENTS.md` §4.2, listed in the work
   order's §2 as already decided): the system resolves the chain — **the direct
   manager, then مدير الموارد** — and the picker is removed. The direct manager
   comes from the org tree (`ManagementChain`: skip a vacant post, never the
   requester, stop at المدير العام).
   
   This is listed as decided, but it changes the workflow people use today, so it
   is put back to you rather than built silently.
   
   | | What it means |
   |---|---|
   | **A** | Keep legacy: the employee picks ordered approvers from the managers |
   | **B** | The plan: two resolved steps, direct manager → مدير الموارد. Every leave request reaches the HR manager (about 60 a month at legacy's rate) |
   | **C** | One resolved step: the direct manager only — legacy's one-approver habit, but the system picks the right person. HR sees every request and keeps its excuse and cancel powers |
   
   **Recommended: C**, unless HR wants to approve every request — then B. C keeps
   the workflow people use (one approver) and removes the weakness: today an
   employee can send their request to any manager in the company, including one
   who has nothing to do with them. Manual reassignment (legacy has it, never used)
   stays as an HR action in every option.
   
   `Answer:` **B** — two resolved steps: the direct manager, then مدير الموارد. Employees no longer choose approvers.
   
   ### Q2 — When step 2 (مدير الموارد) collides with step 1 or with the requester (only if Q1 = B) — *D2*
   
   **Legacy.** Cannot happen: the employee picks the approvers, they must be
   distinct, and the requester cannot be one of them.
   
   **How the plan's chain resolves.** Step 1 is the direct manager from the org
   tree: the manager of the employee's own unit, or — when that post is vacant or
   the requester holds it — the nearest unit above with a manager. Step 2 is always
   the manager of إدارة الموارد البشرية. That gives two collisions, both only in
   HR's own division:
   
   **Case 1 — an HR staff member whose direct manager is مدير الموارد.** Someone
   working directly in the HR division, or in an HR section with no section
   manager: step 1 resolves to مدير الموارد, and step 2 is مدير الموارد again —
   the same person asked twice.
   
   | | |
   |---|---|
   | **A** | One step: مدير الموارد approves once; the duplicate is recorded as skipped with the reason |
   | **B** | Step 1 moves up to المدير العام instead, so the request still gets two different approvers |
   
   **Case 2 — مدير الموارد's own leave.** Step 1 is the HR manager's direct
   manager, المدير العام (as you said). But step 2 is "the manager of HR" — the
   requester themselves, approving their own leave, which legacy forbids.
   
   | | |
   |---|---|
   | **A** | Step 2 is skipped (recorded with the reason): المدير العام's approval is enough |
   | **B** | Step 2 goes to a named deputy of the HR manager (say who, or which post) |
   
   **Recommended: A in both cases** — nobody approves their own request, nobody
   approves twice, and no extra person is pulled in.
   
   `Answer:` Case 1 — **A** (one step; the duplicate recorded as skipped with the reason) · Case 2 — **A** (المدير العام's approval is enough; step 2 skipped with the reason). Nobody approves their own request.
   
   ---
   
   ## B. Leave rules
   
   ### Q3 — Which leave types, with which rules?
   
   **Legacy's code seeds six types; its database holds nine** — three added
   through the admin screen, and two of the six changed there. The live table is
   what the company actually uses:
   
   | Code | Arabic name | Deducts balance | Employee may request | HR may file as excuse | Reason required | Used (requests / excuses) |
   |---|---|---|---|---|---|---|
   | `annual` | إجازة سنوية | yes | yes | yes | no | 16 / **164** |
   | `emergency` | إجازة طارئة | yes | yes | yes | no | 42 / 1 |
   | `sick` | إجازة مرضية | **yes** | yes | yes | yes | 10 / 6 |
   | `unpaid` | إجازة بدون راتب | no | yes | yes | yes | 3 / 0 |
   | `official_mission` | مأمورية رسمية | no | no | yes | yes | 0 / 3 |
   | `bereavement` | إجازة وفاة | no | no | yes | yes | 0 / 1 |
   | `attendent_execused` *(added by HR)* | حضور مبرر | no | no | yes | yes | 0 / 66 |
   | `external_task` *(added by HR)* | مهمة خارجية | no | no | yes | yes | 0 / 13 |
   | `study_leave` *(added by HR)* | إجازة دراسة | no | no | yes | no | 3 / 2 |
   
   Three things to settle:
   
   1. **Port the live nine, not the seeded six?** Recommended: **yes**, with the
      code `attendent_execused` corrected to `attendance_excused` (the name HR sees
      is unchanged).
   2. **Does sick leave deduct from the annual balance?** Legacy seeded it as
      **not** deducting; the live table says it **does** — someone changed it
      through the admin screen (bereavement was changed the same way, from
      "employee may request" to HR-only). The mapping followed the seeder.
      Recommended: **keep the live behaviour**, and confirm with HR that the change
      was deliberate.
   3. **New: a sick leave needs a medical report attached** (requirements §5.6).
      Recommended: **yes, for `sick` only**.
   
   `Answer:` 1 — **yes**, the live nine, `attendent_execused` → `attendance_excused`, Arabic name unchanged · 2 — **no: sick leave does not deduct** (overrides the live table) · 3 — **yes**, a medical report is mandatory for sick leave
   
   ### Q4 — The default annual allocation — *D13*
   
   **Legacy.** `config/leave.php` gives **15 days** to anyone without a balance row
   for the year. HR set a different number for 8 people in 2026: 5 days (1), 10
   days (4), 16 days (3).
   
   | | |
   |---|---|
   | **A** | 15 days by default; HR sets a different allocation per person in the ERP where needed (as it did for those 8) |
   | **B** | Another default (say which) |
   
   **Recommended: A.** Nothing carries over from legacy, so HR re-enters the
   exceptions it still wants.
   
   `Answer:` **A** — 15 days by default; HR sets a per-person allocation where needed.
   
   ### Q5 — HR excuses: keep them as legacy has them?
   
   **Legacy.** HR files leave on an employee's behalf as an **excuse**: approved at
   once, no approval chain, any type with "HR may file" set. Excuses are the
   **main** path today — **258 excuses against 148 approved requests** — and HR can
   undo one filed by mistake.
   
   **Recommended: keep exactly this.** An excuse is an HR record, not a request,
   so it never enters the approvals engine.
   
   `Answer:` **Keep exactly as legacy**: HR records an excuse for an employee, approved at once, no approval chain, cancellable when filed in error, only for types that allow excuses.
   
   ### Q6 — Balances and leave at the switch, since no data moves
   
   **The consequence.** With no history imported, on the day an employee moves to
   the ERP it does not know how many days they already took this year, nor about
   leave legacy already approved for dates after the switch. Two things to decide:
   
   **1. The opening balance for the year of the switch.**
   
   | | |
   |---|---|
   | **A** | Switch at the turn of a year (1 January): the leave year starts fresh in the ERP, nothing to enter |
   | **B** | Switch mid-year, and HR enters each person's **remaining** days as an opening adjustment in the ERP |
   | **C** | Switch mid-year, and everyone starts with the full allocation (days already taken in legacy are forgiven) |
   
   **Recommended: A** if the switch can be on 1 January — it needs no hand entry
   and no one gains or loses days. **B** otherwise. C hands out days.
   
   **2. Leave approved in legacy for dates after the switch, and requests still
   pending there.**
   
   | | |
   |---|---|
   | **A** | Pending requests are decided in legacy before the switch (announce a cut-off date); HR records each already-approved future leave in the ERP as an **excuse** (Q5), so it counts and needs no second approval |
   | **B** | Employees re-file their future leave in the ERP, and it goes through approval again |
   
   **Recommended: A.**
   
   `Answer:` 1 — **B**: the switch may be mid-year; HR enters each person's remaining days as an opening adjustment · 2 — **B**: employees re-file future leave in the ERP and it is approved again
   
   ---
   
   ## C. Attendance
   
   ### Q7 — Who records attendance? — *D3*
   
   **Legacy.** A per-account flag, `attendance_check`. Without it a user cannot
   check in, and is not counted absent. **All 236 active users have it on.**
   
   | | |
   |---|---|
   | **A** | Keep the flag, as a fact about the **employee** (on by default), so HR can exempt someone |
   | **B** | Drop it: every active employee with an account records attendance |
   
   **Recommended: A** — it is legacy's logic, costs one column, and the first
   exemption HR needs would otherwise mean a migration.
   
   `Answer:` **B** — no flag; every active employee with an account records attendance.
   
   ### Q8 — Working days and public holidays — *D5*
   
   **Legacy.** Every day of the week is a working day — **there is no weekly day
   off** — and there is no holiday calendar. So a Friday, or an Eid, inside a leave
   is charged against the balance, and a Friday without a check-in counts as
   absent.
   
   Two questions:
   
   1. **Is there really no weekly day off?** If there is one (Friday?), say which.
   2. **A public holiday table HR fills?** Holidays would then not be charged as
      leave and not counted as absent. Recommended: **yes**.
   
   `Answer:` 1 — **no weekly day off**; all seven days are working days · 2 — **no holiday calendar at this stage**
   
   ### Q9 — "Late" (متأخر) — *D4*
   
   **Legacy.** Nobody is ever late: check-in is open 24 hours, and there is no
   shift start time.
   
   | | |
   |---|---|
   | **A** | No "late" in the ERP either, for now |
   | **B** | One start time for everyone (say which), and a check-in after it is late |
   | **C** | A start time per unit or per shift — a bigger build; say if it is needed |
   
   **Recommended: A**, unless HR already tracks lateness on paper.
   
   `Answer:` **A** — no "late" at this stage.
   
   ### Q10 — The daily times
   
   **Legacy.**
   
   - **Checkout reminder at 17:00**: a push to everyone still checked in.
   - **The day closes at 23:59**: an open check-in becomes a "missing checkout"
     with no hours. (Legacy's code falls back to 22:00 when the setting is
     missing; its config says 23:59. 1,428 records ended as missing checkouts.)
   - **A check-out within 60 minutes of the check-in is refused** (a mis-tap
     guard).
   - **No check-in during an approved leave.**
   
   **Recommended: keep all four as they are**, with 23:59 as the one closing time.
   
   `Answer:` **Keep all four**: reminder 17:00, the day closes at **23:59** everywhere, check-out refused within 60 minutes, no check-in during approved leave.
   
   ### Q11 — How long are selfies kept, and legacy's monthly cleanup — *D7*
   
   **Legacy.** On the 1st of every month, `attendance:cleanup` **deletes every
   attendance record older than a month, with its selfie**. The ERP keeps the
   records for good: payroll and the cutover reconciliation read them.
   
   Two questions:
   
   1. **Selfie files in the ERP**: kept for how long? The record itself is kept
      regardless. Recommended: **6 months**, then the file is deleted and the
      record stays.
   2. **Stop legacy's cleanup now?** Nothing is imported, so this is not about
      keeping history for the ERP. It matters only if, before switching legacy
      off, you want to **compare one month** recorded in both systems (the plan's
      check that the ERP counts days the way legacy does): the cleanup deletes
      legacy's records a month later. It is one line in legacy's schedule, on the
      production server — a production change, so it needs your go-ahead.
      Recommended: **yes, if you want that comparison**; otherwise leave legacy
      alone.
   
   `Answer:` 1 — **three months** for selfie files (private storage); the record is kept permanently · 2 — **do not touch legacy's cleanup**
   
   ### Q12 — An offline check-in sent twice — *D9*
   
   **Legacy.** When the app retries a sync whose first attempt did save (the
   network dropped on the reply), the server answers "you already checked in
   today" — an error for a record that exists.
   
   **Recommended:** each record carries an id generated on the phone; a retry
   answers success with the record already saved. Old app versions without the id
   keep working as today.
   
   `Answer:` **Approved** — a phone-generated id per record; a retry returns the saved record; old app versions keep working.
   
   ---
   
   ## D. Reach and rollout
   
   ### Q13 — Whose unit does an attendance or leave record belong to? — *D6*
   
   **Legacy.** Each record stores the employee's department and section **at the
   time**, so after a transfer the old manager keeps seeing the old records and the
   new one does not.
   
   | | |
   |---|---|
   | **A** | The employee's **current** unit: after a transfer the new manager sees the whole history, the old one none of it. As the ERP already does for employee records |
   | **B** | The unit **at the time**, as legacy does |
   
   **Recommended: A.** "Where was this person on date X" is already answered by
   the posting history, so nothing is lost.
   
   `Answer:` **A** — the employee's current unit.
   
   ### Q14 — The pilot — *D12*
   
   The plan: a pilot group (technical department and HR, 15–25 people) uses the
   ERP app first. During the pilot they **check in on both apps**, file leave
   **only in the ERP**, and HR copies each approved ERP leave into legacy as an
   excuse, so legacy's pay figures stay complete.
   
   **Recommended: as planned.** Say if the group should be different.
   
   `Answer:` **As planned** — 15–25 people from the technical department and HR; attendance in both apps, leave only in the ERP, HR mirrors approved ERP leave into legacy as excuses.
   
   ---
   
   ## Later, not in this round
   
   D8 (reuse legacy's Firebase project), D10 (whether the app shows the pay
   block), D11 (a separate app id) — none changes how attendance, leave or
   approvals work. They are asked before the app work (§4.10).
