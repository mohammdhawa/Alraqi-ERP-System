# Decision round — documents

> ✅ **CLOSED 2026-11-22. All 32 questions answered**, opened 2026-11-21. The
> answers are on each `Answer:` line below and in
> [`EMPLOYEE_APP_V1_WORK_ORDER.md`](EMPLOYEE_APP_V1_WORK_ORDER.md) §8 (the
> entries of 2026-11-21 and 2026-11-22). Build from those; this file is the
> record of what was asked and why. Nothing in it is built yet.
>
> **Revised along the way:** D10 (first no, then documents to employees are in
> this release), D16.3 (first read as yes, clarified as no), D20.2 (first a QR
> code, then cancelled). **Revised while designing, 2026-11-22** (the design's
> §1.4 and §19): D19.1, D25 and D30.1 (a document HR sends to an employee never
> goes through approvals and carries only the employee's signature), and I.1
> (managers sign in order only). **Where the answers left the recommendation:** D2,
> D3.2, D3.3, D7, D15, D16.3, D18.2, D19.1, D21.1, D22, D26.3, D27.2, D28.
>
> **Still awaited, not a decision:** the company's forms for the templates
> (D32.1). D13 (when the documents move) has no date yet; the build does not
> depend on it.
>
> **Why a round.** On 2026-11-21 the owner ruled that the release waits for
> document approvals (with HR and the performance evaluation;
> [`START_HERE.md`](START_HERE.md) §10 item 0), and the same day, that **the
> ERP replaces the legacy document module entirely, and the legacy documents
> are moved across.** That second answer is the first exception to the ruling
> of 2026-10-16 that no data moves from legacy (work order §8), and some of the
> questions below come from it.
>
> **The rule since 2026-10-16:** legacy (`../Raqi-App`) is the foundation.
> What it does is carried as it is unless a question below changes it. The
> things carried with no question are listed in §I, so you can object to any of
> them.
>
> **How to answer:** write the letter (or your own answer) on each `Answer:`
> line.
>
> **Evidence.** Counts from the local copy of the legacy database
> (`raqi_app`, last document 2026-10-22). Counts and names of templates only, no
> personal data. Production has moved on since that copy; the import will read a
> fresh copy at the switch.

---

## What legacy has today

**92 documents** from 2026-06-01 to 2026-10-22 (31 in June, 24 in July, 20 in
August, 7 in September, 10 in October), **all created by managers**: 11
people across 7 departments. Nobody with the employee role has ever seen one.

| | |
|---|---|
| Status | 63 approved, 3 rejected, **26 pending** (22 not deleted; the oldest from 2026-06-02) |
| Origin | 72 **uploaded** (90 PDFs, 2 photos), 20 **generated** from a template |
| Mode | 90 sequential, 2 parallel |
| Approval steps | 321 (2 to 5 per document); 15 different managers have been asked; the chief is always last |
| Rejections | 3 by the chief (final); 2 by a manager (recorded, the document went on) |
| Comments, attachments | 4 and 4 |
| Templates | 20, all active. Only one (طلب شراء) has a layout of its own; the other 19 print through a generic layout |
| Numbers | The صادر counters of 6 departments (الادارة الهندسية is at **558**, carried over from the paper register); 20 generated documents carry a reference like `RE-2026-0005`. No document has a وارد number |
| Stamps | 20 of 50 sections have a stamp image, printed on the approval cards |
| Files | **None of the files is in the local copy.** They are on the production server's public disk, which serves them to anyone with the link (`../Raqi-App/docs/STORAGE_EXPOSURE_FINDINGS.md`) |

**How a document moves in legacy.** A manager uploads a file (or fills a
template, and the server prints the PDF), names one or more managers as
approvers, sequential or parallel, and sends it. The chief (المدير العام, a
role held by one account) is added as the last approver every time. A
manager's rejection is recorded and the document goes on; only the chief's
decision is final. After each decision the server prints a card on the PDF
(name, section, date, note, the section's stamp; red for a rejection, blue for
the chief). The creator may delete their document, file and all, while it is
pending; an admin may delete any. The creator and the approvers see the
document and its comments; nobody else does.

**What the ERP already has for it.** The approvals engine (built in October,
[`APPROVALS_ENGINE_DESIGN.md`](APPROVALS_ENGINE_DESIGN.md)) was designed with
documents as its second consumer: named approvers, parallel steps, the
advisory rejection rule and a decisive last step are all in it, and leave runs
on it today. Files go through `app/Shared` attachments on a private disk.
Neither the ERP's dashboard nor its app has any document screen yet.

---

## Contents

- **A. Moving the documents across** — D1 people ✅, D2 pending documents ✅, D3 numbers ✅
- **B. Who does what** — D4 who creates ✅, D5 who approves ✅, D6 rejections ✅, D7 deleting ✅ (details: D31)
- **C. Templates** — D8 the templates ✅ (details: D32), D9 the HR and pay templates ✅
- **D. Documents addressed to employees** — D10 in scope or not ✅, D11 «قرأت ووافقت» ✅
- **E. Where it runs** — D12 the app and the dashboard ✅
- **F. The switch** — D13 when documents move (no date yet)
- **G. Sending to employees and signing** (added 2026-11-22, answered the same
  day) — D14 when the signature is drawn ✅, D15 changing it ✅, D16 what
  confirms a signature ✅, D17 disagreeing ✅, D18 who sends and to whom ✅, D19 approval before sending ✅, D20 the signed copy ✅
- **H. Managers' signatures and unit stamps** (added 2026-11-22, answered the
  same day) — the owner's rules, then D21 which unit's stamp ✅, D22
  a rejection on the PDF ✅, D23 the stamps themselves ✅, D24 a
  manager's signature ✅
- **I. Carried as legacy has it, unless you object**
- **J. Left open by the answers of 2026-11-22** — D25 a document sent without
  approval ✅, D26 announcements by notification ✅, D27 one unit per manager ✅
- **K. The last questions** (all answered 2026-11-22) — the open parts of D3,
  D6, D9, D20.3, D21 and D26 ✅, then D28 how far "no one acts for a vacant
  post" reaches ✅, D29 who in HR sends ✅, D30 the last word beyond documents
  between managers ✅, D31 deleting ✅, D32 templates that match the company's
  forms ✅ (the forms awaited)

---

## A. Moving the documents across

### D1 — The people on the old documents

**Today.** Every legacy document points at legacy **accounts**: who made it,
who approved, who commented. The ERP points at **employees**, and since
2026-10-16 nothing links the two: the link table of
`LEGACY_TO_ERP_MAPPING.md` §2 was dropped when "no data moves" was ruled.
20 people are involved: the 11 creators, the 15 managers asked, the chief
and the commenters, many of them more than one of these.

| | What it means |
|---|---|
| **A** | **Match them by hand.** The import prints the list of legacy accounts on documents; HR matches each to an ERP employee. Each person then sees their old documents in the ERP as their own. Someone who has left the company is kept by name only |
| **B** | **Names only.** The old documents move read-only, with names as they were printed. Nobody's "sent" or "received" list shows the old ones; only an archive search does |

**Recommended: A.** The list is short, and B would leave every manager's own
correspondence history out of their own lists.

`Answer:` **A** (2026-11-22): **the 20 people on legacy documents are matched by
hand to their ERP employees.**

### D2 — Documents still pending at the switch

**Today.** 22 documents are pending, some since June; some may be forgotten.
A document half-way through its approvals has some decisions recorded and some
still to make.

| | What it means |
|---|---|
| **A** | **Drain first.** Announce a date; until then the chief and managers decide what is pending in legacy. Whatever is still pending on the day moves closed as «لم يُستكمل», with its decisions so far, and its creator can send it again in the ERP |
| **B** | **Carry them live.** Each moves as an open approval: its decisions so far recorded, the remaining approvers asked again in the ERP |

**Recommended: A.** It is the switch the merge plan chose for documents
(`legacy-system-merge-plan.md`, Stage 2: drain, migrate, switch), and the stale
ones get a clear end. B needs the engine to accept decisions made elsewhere,
for a handful of documents.

`Answer:` **Neither** (2026-11-22): **the owner deletes the pending documents
in legacy; none moves to the ERP.** Only decided documents (approved or
rejected) move. Documents already deleted in legacy (4 in the copy) do not
move either.

### D3 — Numbers: صادر, وارد and the reference

Three questions, all about the paper register that `إدارة الديوان والأرشيف`
keeps:

1. **Continue or restart?** The صادر counters carry on from where legacy left
   them (the next in الادارة الهندسية would be 559), and the references
   (`RE-2026-0006` next) too? Recommended: **continue.** Restarting would
   repeat numbers already on paper.
2. **The units.** Legacy counts صادر per legacy department; the ERP's units
   come from the org chart and differ. Each legacy counter goes to the ERP unit
   that HR names. Recommended: **yes, HR maps the 6 counters.**
3. **Uploaded documents get no صادر number in legacy**, only generated ones do
   (72 of 92 documents have none). Intended, or a gap? Recommended: **every
   outgoing document takes a صادر number**, uploaded or generated, with the
   creator able to type one when the register is ahead, as legacy allows for
   generated documents.

And **وارد** (incoming): legacy has a field, typed by hand, with no counter,
and no document has ever used it. Keep it as a typed field, drop it, or give
it a counter?

`Answer:` 1 — **continue** (2026-11-22): the صادر counters and the reference
sequences carry on from legacy. · 2 — **no** (2026-11-22): legacy's six
counters are not mapped onto ERP units. Each ERP unit's صادر counter starts
empty; the first document of each unit has its number typed by hand from the
paper register (legacy's own first-number rule), and the counter carries on
from it. The reference sequences (`RE-2026-0006` next) belong to no unit and
carry on as they are. **Settled while designing (2026-11-22):** the register is
the creator's **إدارة**, as legacy. A section's or an office's manager numbers
from their إدارة's register, and المدير العام from الإدارة العامة's own. · 3 — **no** (2026-11-22): as legacy, **only documents
made from a template take a صادر number**; uploaded documents do not. · وارد —
**typed by hand** (2026-11-22), as legacy: an optional field with no
counter.

---

## B. Who does what

### D4 — Who may create a document

**Today.** Legacy: the managers and the chief. The ERP has no "manager role":
it has unit managers (posts on the org chart) and permissions given by role.

| | What it means |
|---|---|
| **A** | **Managers only**: whoever holds a unit's manager post, and المدير العام, as legacy |
| **B** | **A permission**, given to managers by default, and to anyone else HR chooses (a secretary of إدارة الديوان, for instance) |
| **C** | **Every employee** |

**Recommended: B.** It starts as legacy, and lets the archive's staff register
documents without being made managers.

`Answer:` **A** (2026-11-22): **every manager creates documents**, at every
level: the manager of a section, an office, a department, an إدارة and above.
Read as managers only; the one case that may need more, HR's staff sending to
employees, is D29.

### D5 — Who approves, and who has the last word

1. **Who can be named as an approver?** Legacy: only managers and the chief.
   **A** — the same: unit managers only. **B** — any active employee with an
   account. Recommended: **A**, as legacy, unless documents are sent to
   specialists (a finance officer checking an invoice): then B.
2. **The last word.** Legacy adds the chief at the end of every document.
   Recommended: **always المدير العام, as the manager of the top unit**, so it
   follows the post when the person changes.
3. **Routes per template?** Legacy lets the creator choose every approver, even
   for a purchase request. **A** — keep the creator's choice. **B** — some
   templates bring a fixed route (طلب شراء: the direct manager, then إدارة
   المالية, then المدير العام), and the creator only adds names. Recommended:
   **A** now; B per template later, on request.

`Answer:` (2026-11-22) 1 — **A, unit managers only**: the creator names the
managers who must sign; someone who manages no unit cannot be named (D21). ·
2 — **المدير العام's decision is final**: always the last approver of a
document between managers (not of a document to an employee, D19). · 3 —
**A**: the creator chooses the approvers, for every template.

### D6 — Rejections

1. **A manager's rejection.** Legacy records it and the document goes on to the
   chief, who decides. **A** — keep. **B** — any rejection ends the document,
   as leave does. Recommended: **A**, as legacy (the engine already does it).
2. **When المدير العام creates a document**, there is nobody above to decide.
   It has not happened in legacy (0 of 92). **A** — it is approved once every
   approver they named has approved; a rejection by any of them ends it.
   **B** — their documents need no approval: they are issued. Recommended:
   **A**, so a document of theirs that asks others still asks them.

`Answer:` 1 — **A** (2026-11-22): **المدير العام has the final decision**; a
manager's rejection is recorded and the document goes on to him. · 2 —
**A** (2026-11-22, with D30.2): a document he creates is approved once every
manager he named has signed; their rejections are recorded and change
nothing.

### D7 — Deleting

**Today.** The creator deletes their pending document, file and all; an admin
deletes any document. The register then has a gap, and nothing says why.

| | What it means |
|---|---|
| **A** | **Withdraw, not delete.** The creator withdraws a pending document: it stays, marked «مسحوب», with its number. An admin can hide a document, never erase it |
| **B** | **As legacy**: erased, file and all |

**Recommended: A.** A numbered register should not lose numbers, and the ERP
keeps an audit trail of every record.

`Answer:` **Neither** (2026-11-22): **a document can be deleted as long as
nobody has signed it.** Once one approver has signed (an approval or a
rejection, both of which print a signature, D22), or the employee has signed or
refused, it can no longer be deleted. Who deletes and what happens to its
number: D31.

---

## C. Templates

### D8 — The templates

**Today.** 20 templates, built by the admin on the dashboard (fields: text,
long text, number, date, a list to choose from, a table). The server prints
the filled form as a PDF with the company's header; only طلب شراء has its own
layout. Generation has an off switch in legacy, and 20 documents were made with
it.

| | What it means |
|---|---|
| **A** | **Carry the templates and the builder as they are**, all 20 |
| **B** | **Uploads only first**, templates in a second step |

**Recommended: A.** The templates are most of what makes the module more than
a file share, and D9 decides which ones belong elsewhere.

`Answer:` **A, and more** (2026-11-22): **an advanced creation mechanism that
matches the company's existing templates**, and **the templates can be
filtered** when one is chosen. What "matches" needs, and what to filter by:
D32.

### D9 — The HR and pay templates

Most templates are HR or pay requests. In legacy they are paper: approving a
resignation changes no record. In the ERP the employee's record is HR's.

| Group | Templates |
|---|---|
| **On an employee's record** | طلب إنهاء عمل · طلب استقالة · طلب نقل داخلي · طلب تعديل مسمى وظيفي · طلب مباشرة عمل · طلب مهمة عمل · كتاب تمييز موظف · كتاب تحديد الرتبة الهندسية · شهادة لا مانع |
| **On the organisation** | مقترح إنشاء مسمى وظيفي جديد · طلب احتياج موارد بشرية |
| **Pay** (outside HR, 2026-11-21) | طلب تعديل راتب · طلب تثبيت موظف في نظام الرواتب · طلب إضافة مولود · طلب مكافأة |
| **Other** | طلب شراء · فاتورة · طلب أصل · مذكرة داخلية · طلب إجازة |

1. **The first group.** **A** — they stay documents; HR updates the record by
   hand after the approval, as today. **B** — approving one changes the record
   (a transfer moves the employee, a termination ends the employment). **C** —
   documents now; the HR round decides which become actions. Recommended:
   **C.** Which acts change the record is HR's scope, still to be written down,
   and كتاب تمييز موظف depends on the evaluation you will describe.
2. **طلب إجازة** duplicates the ERP's leave requests, which have balances and
   their own approvals. Recommended: **retire it**: the old documents move, no
   new ones.
3. **Pay templates stay documents**, as payroll is outside HR? Recommended:
   **yes.**

`Answer:` (2026-11-22, as recommended) 1 — **C**: they stay documents now;
the HR round decides which of them change the employee's record when
approved. · 2 — **طلب إجازة is retired**: its old documents move, no new ones
are made. · 3 — **yes**: the pay templates stay documents.

---

## D. Documents addressed to employees

### D10 — Documents for an employee: now or later?

**Today.** In legacy an employee never sees a document. The app's specification
(`EMPLOYEE_APP_REQUIREMENTS.md` area 7) asks for one: the employee's contract,
promotion decisions, work certificates and the circulars sent to them, with
«قرأت ووافقت». It is not an approval: a document is sent **to** people, and
they read it.

| | What it means |
|---|---|
| **A** | **Now, with the documents.** An approved document can be sent to one employee, a unit, or everyone; they see it in the app |
| **B** | **Later**, after the release |

**Recommended: A**, if the release is meant to carry area 7. Payslips are not
part of it (payroll).

`Answer:` **A** (revised 2026-11-22): **documents sent directly to employees
are in this release.** The first answer, the day before, was no. What sending
needs settled is in §G.

### D11 — «قرأت ووافقت»: a receipt or a signature?

This is open question 2 of `EMPLOYEE_APP_REQUIREMENTS.md` §8. The options
were a receipt (a tap records who and when), a signature (confirmed with the
password and printed on the PDF), or the sender's choice per document.

`Answer:` **A drawn signature** (2026-11-22, the owner's rules):

1. The app asks **each employee to draw their personal signature.**
2. The signature is **stored securely with the employee's account**, for every
   later use.
3. When an employee reads a document or a decision and taps «قرأت ووافقت»,
   **their stored signature is printed at the bottom of the PDF.**

When it is drawn, how it is changed and what confirms a tap are D14–D16;
managers' signatures and the units' stamps are §H.

---

## E. Where it runs

### D12 — The app and the dashboard

**Today.** Legacy has both: the old app (الواردة and الصادرة, create by upload
or template, the approval steps, comments, approve and reject) and the
dashboard (the same, plus the admin's list of all documents, the template
builder and the statistics). Neither the ERP app nor the ERP dashboard has a
document screen.

| | What it means |
|---|---|
| **A** | **Both, as legacy**: the ERP app gets the document screens, and the dashboard gets them and the admin pages |
| **B** | **The dashboard for everything; the app only approves** (documents join «بانتظار قراري», which leave uses) |

**Recommended: A.** The old app is retired with the module, and the managers
who use it today would lose creating from their phones under B.

Whatever the answer, D10 (2026-11-22) gives the ERP app the employees' side:
every employee receives documents, reads them, draws a signature and signs in
the app. D12 decides only the managers' side.

`Answer:` **A** (2026-11-22): **document screens in both the employee app and
the dashboard.**

---

## F. The switch

### D13 — When the documents move

**Today.** The release waits for HR, documents and the evaluation (2026-11-21).

| | What it means |
|---|---|
| **A** | **With the release**: legacy keeps documents until then, and they all move in one switch |
| **B** | **Before the release**, as soon as documents are built and tested: the managers move first; the old app keeps attendance and leave until the release |

**Recommended: A.** One switch for everyone, and no time where a manager uses
two apps.

`Answer:` **No date yet** (2026-11-22). Whether documents move with the
release or before it stays open; the build does not depend on it, since the
import is the same either way.

---

## G. Sending to employees and signing — added 2026-11-22

D10 (documents to employees, now) and D11 (a drawn signature) raise these.

### D14 — When the employee draws their signature

**Today.** Nobody has a signature in either system. Every active employee has
an account (`EMPLOYEE_APP_REQUIREMENTS.md` §7.1), so every one of them can be
asked in the app.

| | What it means |
|---|---|
| **A** | **Up front, required.** After the first sign-in following the release, the app shows the signature pad and nothing else until it is drawn |
| **B** | **When first needed.** The first «قرأت ووافقت» (or a manager's first approval) opens the pad; the signature is drawn there, then printed |
| **C** | **Up front, can be put off.** The app asks after the first sign-in; the employee may put it off, and it becomes required at their first signing |

**Recommended: C.** Everyone is asked, as your rule says, without the pad
standing between an employee and their check-in on the first morning.

`Answer:` **B** (2026-11-22): **the signature is drawn the first time the
employee signs a document**, and a manager's at their first approval (D24).

### D15 — Changing a signature

A signature printed on a PDF stays as it was printed: a new signature never
changes a document already signed (§I).

| | What it means |
|---|---|
| **A** | **The employee redraws it themselves**, after entering their password. Every earlier version is kept, with its dates |
| **B** | **Only through HR.** HR clears it on the dashboard (a scribble, a lost phone), and the employee draws a new one |
| **C** | **Both** |

**Recommended: C.** The owner of a signature can correct it, and HR can clear
one that should no longer be used.

`Answer:` **Neither** (2026-11-22): **an employee cannot delete their own
signature**, nor so redraw it. **A permission of its own deletes a signature,
and it is never given to HR.** Once one is deleted, its owner draws a new one at
their next signing (D14). Every deleted version is kept, with its dates.
Confirmed 2026-11-22: employees cannot delete, replace or redraw their
signature; **the permission belongs to the system administrator** and is never
given to HR.

### D16 — What confirms a signature

A stored signature is printed by a tap, so a phone left unlocked on a desk
could sign for its owner.

1. **«قرأت ووافقت».** **A** — the tap alone. **B** — the tap, then the
   phone's fingerprint or face, or the password on a phone with neither.
   Recommended: **B.**
2. **A manager's approval**, which now prints their signature (§H). The same
   choice. Recommended: **B**, one rule for every signature.
3. **Reading first.** Is «قرأت ووافقت» offered only once the employee has
   opened the document to its last page? Recommended: **yes**, since the words
   say «قرأت».

`Answer:` **Yes** (2026-11-22): every signature is confirmed by the
fingerprint, the face, or the password. 1 — **B** · 2 — **B** · 3 — **no**
(clarified 2026-11-22; first read as yes): **the app does not make the
employee scroll to the end.** Reading is the employee's responsibility; one
who signs without reading bears it.

### D17 — Disagreeing

| | What it means |
|---|---|
| **A** | **Only «قرأت ووافقت».** An employee who does not sign stays on the sender's list of those who have not |
| **B** | **Also a refusal, with a reason.** The employee says they do not agree, in words; nothing is printed, and the sender sees the refusal and its reason |

**Recommended: B.** An employee who disagrees with a contract or a decision
about them can say so on the record, instead of only staying silent.

`Answer:` **B** (2026-11-22): **an employee can refuse to sign, and must give
a reason.** Nothing is printed; the sender sees the refusal and its reason.

### D18 — Who sends documents to employees, and to whom

1. **Who sends.** **A** — HR only. **B** — anyone who may create documents
   (D4), to the employees of their own units. **C** — HR to anyone; a manager
   to their own units. Recommended: **C.**
2. **To whom.** One employee, several by name, a unit (with the units below
   it), or everyone. Recommended: **all four.**
3. **Newcomers.** Does someone who joins later, or moves into the unit, get a
   document sent to a unit or to everyone? **A** — no: the recipients are fixed
   when it is sent, and the sender can send it again to newcomers. **B** —
   yes, until the sender closes it. Recommended: **A.**

`Answer:` 1 — **A, HR only** (2026-11-22): a permission given to HR's roles.
· 2 — **one employee per document** (2026-11-22): sending the same document to
several employees at once is not needed. · 3 — **A** (2026-11-22): **a
newcomer does not receive what was sent before they joined** (moot with one
named recipient per document).

### D19 — Approval before a document reaches employees

1. **Is every document sent to employees approved first?** **A** — yes: it
   goes through its approvals and reaches the employees approved, with its
   stamps and signatures. **B** — the sender chooses: through approvals, or
   straight to the employees. Recommended: **A**, so whatever an employee signs
   already carries the official stamp.
2. **المدير العام's last word.** D5.2 makes him the last approver of every
   document. Documents to employees too, so every work certificate waits for
   him? Or may they end with another manager the sender names (مدير الموارد
   البشرية for a work certificate)? Recommended: **he stays the last word on
   documents between managers; a document to employees ends with whoever the
   sender names**, at least one manager.

`Answer:` 1 — **B** (2026-11-22): **a document does not have to be approved
before it reaches employees**; HR sends it straight, or through approvals.
What it then carries is D25. · 2 — **المدير العام is not required** on
documents to employees, work certificates included. Whether he stays the last
word on documents between managers is still D5.2.

**Revised later on 2026-11-22**, while designing (the owner's clarification of
four separate workflows, `docs/superpowers/specs/2026-11-22-documents-design.md`
§1.4): **a document HR sends to an employee never goes through approvals.** HR
sends it directly; the employee signs and that completes it. The "through
approvals" path of part 1 is gone; part 2 has no case left.

### D20 — The signed copy

*Rewritten 2026-11-22, with examples, at the owner's request. Part 1 (a
circular to many) is settled: there are no circulars, and a document goes to
one employee (D18.2).*

**Part 1.** `Answer:` **no circulars** (2026-11-22): no document is sent
company-wide; **general announcements go out as notifications** (D26).

#### D20.2 — A check code on a signed PDF

**The problem.** A signed PDF leaves the ERP. Ahmed downloads his signed work
certificate, prints it and takes it to a bank. The signature and the stamp on
it are pictures, and anyone with a computer can copy pictures onto another
PDF: a forged certificate with a higher salary looks exactly like a real one.

**What a check code is.** When a document is signed, the ERP makes a short,
random code that cannot be guessed, for example `RQ-7K4P-92XD`, and stores it
with the signed copy. The code is printed on the PDF, at the bottom of each
page, beside the document's number, as text, a QR square, or both.

**How it is used.** Whoever holds the paper checks it against the ERP:

- **Inside the company**: an HR officer types the code on the dashboard and
  sees the real document: its title, number, who signed it and when, and the
  PDF itself. If the paper says something else, it is forged.
- **Outside** (only with option C): the bank scans the QR square with a phone.
  It opens a page of the ERP, reachable without signing in, that shows only:
  «وثيقة صحيحة — شهادة عمل، رقم RE-2026-0012، صادرة في 22/11/2026، موقّعة من
  …». Nothing else of the document or the employee.

| | What it means |
|---|---|
| **A** | **No code.** The document's number, already printed (D3), lets HR find the document and compare. Enough as long as only the company checks its own papers |
| **B** | **A code checked inside the ERP**, by staff with a permission, on the dashboard |
| **C** | **A QR code anyone can scan**, opening the public check page described above; B as well |

**Recommended: A** for this release. Inside the company, the printed number
already does the code's work. A code earns its build when outsiders (banks,
embassies) check the company's papers, and then it is C. C can be added later,
but papers signed before it carry no code: if work certificates are often
taken to banks today, choose **C** now.

`Answer:` **A** (revised 2026-11-22): **no check code and no QR code.** The
document's printed number is how HR finds a paper and compares it. The first
answer the same day was C (a QR code and a public check page); the owner
cancelled it.

#### D20.3 — Filing a signed document in the employee's record

**What it is about.** HR already keeps each employee's own files in the ERP,
on their record (الملف الوظيفي), under types: صورة الهوية - الوجه, صورة الهوية
- الظهر, الشهادة العلمية, صورة حساب الشام كاش, الصورة الشخصية, السيرة الذاتية,
عقد العمل, أخرى. Documents sent to an employee are about that employee by
definition (one recipient, D18.2): a contract, an appointment decision, a work
certificate, a warning letter.

**Example.** HR sends Ahmed his new employment contract. Ahmed reads it and
taps «قرأت ووافقت»; his signature is printed on it.

- **Filed**: the signed contract appears on Ahmed's record, under «عقد العمل»,
  dated, beside his ID and CV. HR opening Ahmed's record sees it without going
  to the documents screens.
- **Not filed**: it stays in the documents module only. HR finds it by
  searching the documents sent to Ahmed.

**Which documents.** Only those **sent to an employee** (contracts,
appointment and promotion decisions, work certificates, warnings). **Not** the
documents between managers (a purchase request, an invoice, a memo), even
those written about an employee, such as a manager's طلب نقل داخلي: those
belong to the managers who sent them. **Not** announcements (D26), which are
notifications.

| | What it means |
|---|---|
| **A** | **Every signed document sent to an employee is filed** on their record, and HR picks its type when sending (عقد العمل, or a new type: قرار, شهادة, …) |
| **B** | **HR chooses per document** whether it is filed |
| **C** | **Not filed**; the documents module lists them on its own |

**Recommended: A.** Everything sent to an employee is about them, so one place
holds their whole file. A refused document (D17) is filed too, marked refused,
with its reason.

`Answer:` **A** (2026-11-22): **every document sent to an employee is filed on
their record automatically, under the type HR picks when sending.** A refused
document is filed too, **clearly marked refused, with its reason.** The types
HR picks from (2026-11-22, as proposed): today's eight, plus **قرار تعيين، قرار
ترقية، شهادة عمل، إنذار**.

---

## H. Managers' signatures and unit stamps — added 2026-11-22

**The owner's rules (2026-11-22), decided:**

1. **Every organisational unit has its own official stamp**: الإدارة العامة,
   each إدارة, each قسم, and any tier below them.
2. **Every manager has a personal signature** stored in the system.
3. When a manager approves a document **as the manager of a unit**, the PDF
   shows **that unit's stamp, with the manager's signature beside it.**
4. **The stamp belongs to the unit, not to the person.** When the manager
   changes, the stamp stays the same; the signature is that of whoever
   approves.

This replaces legacy's card (§I item 2), which prints the stamp of the
approver's **section** and no signature. 20 of legacy's 50 sections have a
stamp; no ERP unit has one yet.

### D21 — Which unit's stamp

1. **A manager of more than one unit** (the head of an إدارة who also heads
   one of its sections while its post is vacant). **A** — the highest unit
   they manage. **B** — the creator names the capacity when choosing approvers.
   **C** — the manager chooses when approving; with one unit, there is nothing
   to choose. Recommended: **C.**
2. **A unit with no stamp yet.** **A** — the approval is refused until an admin
   uploads it. **B** — the approval goes through with the signature alone, and
   the dashboard lists the units still without a stamp. Recommended: **B**,
   with every stamp uploaded before the release.
3. **An approver who manages no unit** (possible only if D5.1 is B): their
   signature alone, with no stamp? Recommended: **yes.**

`Answer:` 1 — **neither: a manager manages one unit only** (2026-11-22). The
case cannot arise, so a manager's stamp is always their one unit's; what the
rule asks of the org chart is D27. · 2 — **a message tells the manager their
official stamp is missing** (2026-11-22), and **the approval waits until the
admin uploads the stamp** (2026-11-22, §K). · 3 — **no**: someone who manages no unit cannot be named
as an approver (2026-11-22), so the case never arises.

### D22 — A rejection on the PDF

Legacy prints a red card for a rejection, with its note.

| | What it means |
|---|---|
| **A** | **Name, date and the reason, with no stamp or signature.** A stamp and a signature mean approval |
| **B** | **The stamp and signature as well**, on the red card |

**Recommended: A.**

`Answer:` **B** (2026-11-22): **a rejection carries the unit's stamp and the
manager's signature too**, on the red card, as legacy prints its stamp on a
rejection.

### D23 — The stamps themselves

1. **Who uploads a unit's stamp.** **A** — an admin, on the dashboard. **B** —
   HR. **C** — the unit's own manager. Recommended: **A**, behind a permission
   of its own, so it can be given to HR as well. Changing a stamp never
   changes a document already stamped.
2. **The company's seal.** Is it the stamp of الإدارة العامة, printed when
   المدير العام approves, or a separate seal printed on every document that is
   finally approved? Recommended: **the stamp of الإدارة العامة**, by rule 1.

`Answer:` 1 — **A, the admin only** (2026-11-22): no other role, HR
included, uploads a stamp. · 2 — **yes** (2026-11-22): **the company's
official seal is the stamp of الإدارة العامة.**

### D24 — A manager's signature: drawn or scanned

A manager is an employee, so D14's signature is theirs too: one person, one
signature.

| | What it means |
|---|---|
| **A** | **Drawn**, in the app or on the dashboard, like every employee's |
| **B** | **A scan** of their ink signature, uploaded by an admin |
| **C** | **Either** |

**Recommended: A.** One way for everyone, and no file of anyone's ink
signature passes through other hands.

`Answer:` **A** (2026-11-22): **managers draw their signature like every
employee.**

---

## I. Carried as legacy has it, unless you object

These are ported unchanged. Say so on this line if one should change:
`Objections:`

1. **Sequential or parallel** among the named approvers, chosen per document.
   **Revised 2026-11-22** while designing: **in order only.** The named
   managers sign one after another, then المدير العام. Legacy's 2 parallel
   documents move as they were.
2. **The approval cards printed on the PDF** after each decision, an approval
   or a rejection (D22): name, unit, date and note, **now with the unit's stamp
   and the manager's signature** (§H); the original file is kept beside the
   stamped one. Legacy's section
   stamps are offered to the ERP units HR names, as a start.
3. **Comments** by anyone who can see the document, visible to all of them.
4. **Supporting attachments**, up to 10 per document.
5. **Who sees a document**: its creator, its approvers, its recipients (§G),
   and an admin with the right permission. Others do not.
6. **The notifications**, in legacy's words: a new document awaiting the
   approver, «دورك للمراجعة», the chief's turn, the final approval or rejection
   to the creator, a manager's rejection to the creator.
7. **The files move to the ERP's private disk** and are served only to those
   who may see the document, closing the public link legacy has.
8. **The admin's statistics**: by status, activity, the latest documents.

New with the signatures (2026-11-22), the builder's calls:

9. **Signatures and stamps are kept on the private disk, encrypted**, and are
   never sent out as files: the server prints them into the PDF. An employee
   can see their own signature; nobody can download anyone else's.
10. **What is printed stays printed.** A signature or a stamp goes into the PDF
    at the moment of the approval or the «قرأت ووافقت». A later change of
    signature, stamp or manager never alters a PDF already signed.
11. **The old documents move with the PDFs legacy printed** (its section
    stamps, no signatures), and are never printed again.
12. **Only PDFs and photos can be signed.** Legacy also accepts Word files, on
    which nothing can be printed; no legacy document is one (90 PDFs, 2
    photos). Photos become a PDF when uploaded; a Word file is refused, with a
    message asking for a PDF.
13. **A refused document is closed** (D17), with the employee's reason. HR
    sends a new document if one is needed; the refused one is not sent again.

---

## J. Left open by the answers of 2026-11-22

Three questions the answers to D14–D24 raise. The parts of earlier questions
still open were marked **open** on their own `Answer:` lines (all answered
since, §K): D18.2, D20.2,
D20.3, D21.2, D21.3 and D23.2.

### D25 — What a document sent without approval carries

**Today.** By D19, HR may send a document straight to employees, and by §H a
stamp and a signature are printed when a manager approves. A document sent
straight has no approval, so nothing but the employee's own signature would be
printed on it.

| | What it means |
|---|---|
| **A** | **Only the employee's signature**, on the PDF as HR uploaded it. A document that must carry a stamp (a work certificate) goes through a manager's approval first: the HR manager's, for instance |
| **B** | **HR's stamp and the sender's signature**, printed when it is sent, even when the sender is not HR's manager |
| **C** | **HR's stamp alone**, printed when it is sent |

**Recommended: A.** It keeps §H's rule whole (a stamp comes with a manager's
approval), and D19 already lets HR route a certificate through its own manager
without المدير العام.

`Answer:` **A** (2026-11-22): **a document that must carry an official stamp is
approved by a manager first; the HR manager's approval is enough.** A document
sent straight carries the employee's signature only.

**Revised later on 2026-11-22**, with D19.1: a document HR sends to an employee
has no approval, so it **always carries the employee's signature only**: no
stamp, and no signature of the sender (asked and answered while designing).

### D26 — General announcements by notification

**Today.** Legacy has a broadcast: the admin writes a title and a text, sent to
everyone or to the managers only, as a push and in the notifications list. The
ERP has none: it was left on legacy for v1 (`LEGACY_TO_ERP_MAPPING.md` §9), and
legacy is retired at the release, so D20's announcements are built now.

1. **Who sends.** **A** — the admin, as legacy. **B** — HR. **C** — both:
   a permission given to the admin and to HR. Recommended: **C.**
2. **To whom.** Everyone, the managers only (as legacy), a unit with the units
   below it. Recommended: **all three.**
3. **What.** **A** — a title and a text, as legacy. **B** — also a file to
   open. Recommended: **A.** Anything to read and sign is a document.

`Answer:` (2026-11-22) 1 — **C: the system administrator and the HR
manager.** · 2 — **all three**: every employee with an account, the managers
only, or the employees of one unit. · 3 — **B: a title and a text, with files
and images attached.** An announcement to one unit **also reaches the units
below it** (2026-11-22, §K).

### D27 — One unit per manager, on the org chart

**Today.** Nothing in the ERP stops one person being the manager of two units,
on the dashboard or in the import, and the real org chart has not been checked
for it (the local copy has no such case, but it holds test data).

1. **Naming a manager.** The dashboard refuses to name someone manager of a
   second unit, and says which unit they already manage. Recommended: **yes**,
   it is what D21 asks.
2. **The import.** When HR's workbook names one person manager of two units:
   **A** — the import refuses it until HR corrects the workbook. **B** — the
   import keeps the first unit and leaves the other's post vacant, for HR to
   fill. Recommended: **A**: who heads which unit is HR's to say.
3. **A vacant post.** Today the manager of the unit above acts for a unit whose
   post is vacant (decision 15, used by leave). That is acting, not managing:
   they sign with their own unit's stamp. Recommended: **keep it.**

`Answer:` (2026-11-22) **A strict rule: one person manages one unit at most.**
1 — **yes**: the dashboard refuses to name someone manager of a second unit. ·
2 — **B**: the import keeps the person on the first unit (the first row of
the workbook) and leaves the second unit's post vacant. · 3 — **no one acts
for a vacant post**, not even the manager of the unit above. **Narrowed by
D28** (2026-11-22): leave, shift swaps and the direct manager keep decision 15,
so the rule holds for documents, where it means a manager never signs or
stamps for a unit they do not manage.

---

## K. The last questions — answered 2026-11-22

✅ **All answered on 2026-11-22**: "as recommended" for every part below and
for D29–D32, except D3 (counters not mapped, no صادر number on uploads, وارد
typed by hand). The answers are on each question's own `Answer:` line; the
table is kept as the record of what was open.

| Was open | Where | In short |
|---|---|---|
| D3.2 | §A | HR names the ERP unit each of legacy's 6 صادر counters continues in |
| D3.3 | §A | Every outgoing document takes a صادر number, uploaded ones and documents to employees included |
| D3 وارد | §A | The incoming number: a typed field, dropped, or a counter |
| D6.2 | §B | A document المدير العام creates himself: below, with D30 |
| D9.1–3 | §C | The HR templates (documents now, the HR round decides), retiring طلب إجازة, pay templates staying documents |
| D20.3 types | §G | The types HR picks from when a document is filed. Today's eight: عقد العمل, أخرى, and six personal ones (both sides of the ID, the degree certificate, the Sham Cash account, the photo, the CV). Proposed additions: قرار تعيين, قرار ترقية, شهادة عمل, إنذار. Say which, or add your own |
| D21.2 | §H | After the message that their stamp is missing, can the manager still approve with their signature alone, or is the approval held until the admin uploads the stamp? Recommended: **held**: an approval without the official stamp is incomplete, and the message tells them why |
| D26 | §J | Does "one unit" include the units below it? Recommended: **yes** |

D13 (when the documents move) has no date yet and does not hold up the build.

### D28 — No one acts for a vacant post: how far does it reach?

**Today.** Decision 15 (ratified 2026-09-23, `START_HERE.md` §9) is built: when
a unit's manager post is vacant, the manager of the nearest unit above stands
in. Three built things use it:

- **Leave**: the first approval goes to the direct manager, and for a vacant
  post, to the manager above.
- **Shift swaps**: approved by the direct manager, the same way.
- **المدير المباشر** shown on the employee's record and the app's home screen.

Documents never meet it (their approvers are named managers, D5, D21), so
D27.3 is about the rest of the organisation. Not affected either way: a
manager's **own** request goes to the manager of the unit above, as their
direct manager; that is the chain, not standing in for a vacant post.

| | What happens to a request whose direct manager's post is vacant |
|---|---|
| **A** | **Refused when filed**: «لا يوجد مدير لوحدتك حالياً؛ راجع الموارد البشرية». The employee files it once HR names a manager |
| **B** | **The vacant step is skipped**, recorded with its reason (المنصب شاغر), and the request goes on: a leave to مدير الموارد البشرية alone. A request with no step left (a swap) is refused as in A |
| **C** | **It waits**, with nobody to act, and reaches the new manager once HR names one |
| **D** | **Documents only**: leave and swaps keep decision 15 as built |

And the home screen: under A, B and C, المدير المباشر reads «شاغر» for an
employee whose unit's post is vacant.

**Recommended: B.** Nobody stands in for the vacant post, as you ruled, and a
leave is still decided by HR instead of blocked. A blocks the employee for
HR's delay; C leaves requests with nobody to act.

`Answer:` **D** (2026-11-22): **decision 15 stays as ratified on 2026-09-23 and
built.** When a unit has no manager, the manager of the unit above approves,
for leave requests and shift swaps, and is the direct manager the app shows. A
manager's own requests go to their superior, as now. **D28 is an exception to
D27.3**, and **no built code changes**: the approval logic stays as it is.

### D29 — Who in HR sends documents to employees

D18: only HR sends documents to employees. D4: only managers create documents.
D26 names **the HR manager** for announcements.

| | What it means |
|---|---|
| **A** | **The HR manager only** |
| **B** | **Every manager inside HR**: the HR manager and the managers of HR's sections |
| **C** | **HR staff with a permission**, managers or not (a clerk prepares and sends the contracts) |

**Recommended: B.** It keeps D4 (managers create) and spreads the work across
HR's own managers. C is the choice if contracts are prepared by staff who
manage nobody.

`Answer:` **B** (2026-11-22): **every manager inside HR** sends documents to
employees: the HR manager and the managers of HR's sections.

### D30 — The last word, beyond documents between managers

1. **A document to an employee that goes through approvals** (D19.1). Which
   approver decides when the approvers disagree? **A** — the last approver HR
   names, as المدير العام does between managers: earlier rejections are
   recorded and it goes on. **B** — any rejection ends it, and it never reaches
   the employee. Recommended: **A**, one rule for all documents.
2. **A document المدير العام creates himself** (D6.2). His decision is final,
   and nobody is above him. **A** — it is approved once every manager he named
   has signed; their rejections are recorded and change nothing. **B** — one
   rejection ends it. **C** — it needs no approvers: issued when he sends it,
   with الإدارة العامة's stamp and his signature. Recommended: **A**: his
   decision stays final, and the managers he asks still sign.

`Answer:` (2026-11-22, as recommended) 1 — **A**: the last approver HR names
decides; earlier rejections are recorded and the document goes on. · 2 —
**A**: approved once every manager he named has signed; their rejections are
recorded and change nothing.

**Part 1 moot, later on 2026-11-22**: documents to employees have no approvers
(D19.1 revised). Part 2 stands.

### D31 — Deleting before the first signature

D7: a document can be deleted as long as nobody has signed it.

1. **Who.** **A** — its creator only (HR's sender, for a document to an
   employee). **B** — its creator and the system administrator. Recommended:
   **B**, as legacy lets the admin delete.
2. **What is left.** **A** — nothing in any list; the audit trail keeps who
   deleted it and when. **B** — it stays visible as «محذوف» to its creator.
   Recommended: **A.**
3. **Its صادر number.** **A** — never used again: the register shows the
   number as deleted. **B** — reused by the next document. Recommended: **A.**
   The paper register cannot take a number back either.

`Answer:` (2026-11-22, as recommended) 1 — **B**: its creator and the system
administrator. · 2 — **A**: gone from every list; the audit trail keeps who
deleted it and when. · 3 — **A**: its صادر number, when it has one (D3.3), is
never used again, and the register shows it as deleted.

### D32 — Templates that match the company's forms

D8: an advanced creation mechanism matching the existing templates, which can
be filtered.

1. **The real forms.** Legacy prints 19 of its 20 templates through one generic
   layout. To match the company's forms, each needs its own layout drawn from
   the form as it is used today. **Will you send the forms** (Word or PDF, one
   per template, and any form in use that legacy lacks)? Without them, the
   layouts are drawn from legacy's fields and the generic layout.
2. **What to filter by.** **A** — a category (HR, finance, purchasing,
   administration, …) and a search by name. **B** — A, and each template can be
   limited to some units, so a manager sees only the templates for their unit.
   Recommended: **A**; B if units use very different forms.
3. **Who builds templates.** Legacy: the admin, on the dashboard. Keep it?
   Recommended: **yes.**

`Answer:` (2026-11-22) 1 — **awaited**: the forms themselves are still to be
sent; until they are, a layout follows legacy's fields and generic layout. ·
2 — **A**: filtered by category and searched by name. · 3 — **yes**: the admin
builds templates on the dashboard.
