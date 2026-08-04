# Merging the Legacy System into the ERP

> **Status:** plan, drafted 2026-07-30. Decision ratified; stages not started.
> **Depends on:** [phase-1.5-foundation-truth-up.md](phase-1.5-foundation-truth-up.md)
> **Supersedes:** the phase ordering in [al-raqi-erp-design.md](al-raqi-erp-design.md)
> for phases 2 and 3 — see §3.

---

## 1. The two systems

**The ERP** (this repository). Laravel 12 modular monolith, backend only.
Phase 1 complete: org tree, employees, users, permission-based RBAC, audit log,
notifications. No production data. No clients built against it yet.

**The legacy system.** Laravel 10 backend + React/Vite dashboard + Flutter app,
**in daily production use** by the company:

| Capability | Detail |
|---|---|
| Document management | Uploads, PDF generation from templates, attachments, comments, import/export numbering, audit log |
| Approval workflows | Sequential *and* parallel manager approvals, then chief approval; rejection notes; stamped final PDFs |
| Attendance | GPS + selfie check-in/out, offline sync, missing-checkout detection, manual HR correction, reminders, automatic day close |
| Attendance reporting | Daily / monthly / per-employee, department and section filters, XLSX export |
| Leave | Employee requests, manager approve/reject, HR-created excuses, annual allocations, balance calculation |
| Org admin | Users, departments, sections, roles, attendance permissions |
| Auth | Sanctum, 30-day platform-specific tokens, device/session listing, per-device and global logout |
| Notifications | In-app + FCM push, queued company-wide broadcasts |
| Dashboard | Document statistics, status breakdown, recent activity, staff distribution |
| Health | Backend + database connectivity endpoint |

---

## 2. Decision: merge

**One system, one database. The ERP is the host; the legacy system is absorbed
module by module. Production keeps running untouched until each module is cut
over.**

Not a rewrite, and not a big bang.

### Why merge rather than integrate

- **Employee identity already exists twice.** The same 242 people are rows in
  two databases with two numbering schemes. That is the ERP's own first
  principle — *one fact, one place* — violated at the level of the whole
  system, and the cost grows daily.

- **ERP Phase 2 *is* the legacy system.** The attendance and leave schema in
  [al-raqi-erp-design.md:76](al-raqi-erp-design.md) is a *weaker* description of
  software already running: no GPS, no selfie, no offline sync, no
  missing-checkout detection. Building it from that spec would be rebuilding a
  worse version of working code.

- **Payroll cannot be built across the boundary.** Phase 3 needs attendance
  (`إضافي`, `خصم غياب`), leave (unpaid days), performance evaluation
  (`التمييز`), organizational grade, and the employee's pay criteria. Four of
  the five live on the other side. Cross-database payroll is not something to
  attempt.

- **The legacy system holds the piece Phase 1 is missing.**
  [phase1-completion-report.md:352](phase1-completion-report.md) records the
  escalation chain as "structure without a walk." The legacy approval engine is
  that walk, in production, with sequential and parallel modes and rejection
  handling.

- **Its document module is a real division on the org chart.**
  `إدارة الديوان والأرشيف`, with `قسم الأرشيف العام` and `قسم البريد`. The
  import/export register they currently keep in an Excel sheet (`الأرشيف`, 220
  rows of صادر/وارد) is precisely what that module automates. It is not a
  bolt-on; it is a first-class ERP module that happens to already be built.

- **The RBAC migration only runs one way.** Legacy: four hard-coded roles
  (admin, manager, chief, employee). ERP: a permission catalogue, department
  tree, permission cache, `super_admin` bypass. There is no version of this
  where the ERP adopts the legacy model.

### The alternative, and why not

Keeping the systems separate and syncing employees over an API means permanent
bidirectional replication of the most-referenced entity in the company, across
two authentication models and two authorization models, forever. It is more
total work than the merge, and unlike the merge it never finishes.

### What is not being claimed

Merging is not free and this plan does not pretend the legacy code arrives
unchanged. Every ported module must be re-pointed at ERP employees,
departments, and RBAC, and its tests rewritten against them. The claim is
narrower: **porting is cheaper than rebuilding, and far cheaper than
synchronising.**

---

## 3. Revised sequencing

This changes the phase order in
[al-raqi-erp-design.md](al-raqi-erp-design.md), and it also revises the
ordering proposed in the discussion that produced this document. Both changes
are stated with their reasons.

| Stage | Work | Origin |
|---|---|---|
| **0** | Freeze legacy schema changes | new |
| **1** | Phase 1.5 foundation truth-up + import | new |
| **2** | **Documents + the shared approval engine** | ported |
| **3** | Attendance + Leave | ported |
| **4** | Payroll | built (design doc phase 3) |
| **5** | Decommission the legacy backend | new |
| later | Tasks, Projects, Purchasing, Warehouses, Assets, Finance | design doc phases 4–9 |

**Change from the design document:** attendance and leave are no longer built
from the phase-2 spec. They are ported. The phase-2 schema in the design
document should be read as superseded by whatever the legacy tables actually
are.

**Change from the initial proposal:** documents move *ahead* of attendance.
The reason is the approval engine. Leave ships with its own approval flow; if
Leave is ported first, the ERP gains a second approval implementation that must
later be unified with the documents one. Extracting the shared engine first and
porting Leave onto it is less total work. The counter-argument — that
attendance unblocks payroll and should therefore come first — does not hold,
because payroll is two stages away either way.

**Projects moves after payroll**, against
[phase1-completion-report.md:389](phase1-completion-report.md). The workbook is
evidence that HR and payroll are the live pain; Projects has no upstream
dependents waiting on it.

---

## 4. The stages

### Stage 0 — Freeze

Legacy schema changes stop, bug fixes excepted. Every migration added to the
legacy system after this point is a migration that has to be replayed into the
port. Announce the freeze; keep it short by moving through the stages.

### Stage 1 — Foundation truth-up

All of [phase-1.5-foundation-truth-up.md](phase-1.5-foundation-truth-up.md).

**Exit criterion:** the ERP database holds the real org tree and all 242 active
plus 223 terminated employees, HR has reviewed the import, and **the ERP is the
identity source of truth**. Everything after this stage reads people from here.

This is the load-bearing stage. Nothing downstream can start until it is done,
and it is the last moment at which the schema decisions in Phase 1.5 are free.

### Stage 2 — Documents and the approval engine

Port the legacy document module into `app/Modules/Documents`, and factor its
approval logic into a **shared** `app/Modules/Approvals` rather than leaving it
document-specific.

The approval module's surface, at minimum: a polymorphic subject, sequential
and parallel step modes, per-step approver resolution (a named employee, a
department's manager, or a role), rejection with a note, and a completion hook.
Leave, purchase requests, payroll approval, and expense approval are all
already-known consumers — design the interface against those four, not against
documents alone.

Also in this stage:

- Import/export numbering carries over as-is.
- PDF templates carry over as-is.
- Map legacy roles onto ERP permissions: `admin` → the `admin` role,
  `manager` → per-department permissions, `chief` → the approval permissions,
  `employee` → the baseline set. `super_admin` is ERP-only and stays with the
  technical department.
- The React dashboard re-points at the ERP for this module.

**Cutover shape:** a document in flight can tolerate a short freeze window, and
that is the reason this module goes first. Announce a window, drain in-flight
approvals, migrate, switch.

**Exit criterion:** documents and approvals run entirely on the ERP; the legacy
document endpoints are dark.

### Stage 3 — Attendance and leave

Port into `app/Modules/Attendance` and `app/Modules/Leave`, re-pointed at ERP
employees, departments, and RBAC. Leave's approval flow is rewritten onto the
Stage 2 engine — that is the whole reason for the ordering.

This is the **highest-risk cutover** in the plan: attendance is used every
morning by every employee, and the Flutter app holds an **offline queue** that
can contain records created before the cutover and submitted after it. The
migration must accept legacy-format submissions for a grace period — a fortnight
is a reasonable opening figure — rather than assuming every phone updates on
cutover day.

Also in this stage:

- **The notification transport decision closes here.**
  [erp-phase1-architecture.md:78](erp-phase1-architecture.md) leaves transport
  open. The legacy system has a working FCM layer; the ERP adopts it, feeding
  from the existing `notifications` table and its `sendTo*` fan-out. No new
  decision is needed — one already exists in production.
- **Auth models must be reconciled.** Legacy: 30-day platform-specific Sanctum
  tokens with device listing and per-device logout. ERP: short access tokens
  with rotating refresh tokens and replay detection. The ERP model is stronger
  and wins; the *device/session listing and per-device logout* are a genuinely
  useful legacy feature and get ported into `app/Modules/Auth` on top of it.
  Flutter must be updated for the refresh flow — plan this as app work, not as
  a backend detail.

**Exit criterion:** a full month of attendance runs on the ERP, closes cleanly,
and the monthly report reconciles against the legacy system's figure for the
same month. Do not skip the parallel-run reconciliation.

### Stage 4 — Payroll

Design-document phase 3, now buildable: attendance, leave, evaluation, org
grade, and the employee criteria are all in one database.

Two things the design document's phase-3 schema gets wrong, both flagged in
[phase-1.5-foundation-truth-up.md §5.6](phase-1.5-foundation-truth-up.md):

1. `salary_structures` with fixed housing/transport/food allowances is not how
   this company pays. Replace it with a **versioned criteria rate table** —
   effective-dated, holding the value of each criterion case plus the company
   scale factor (`النسبة المئوية للقيمة`, currently `0.75`). The monthly
   workbook snapshots are that table's version history.
2. The `إتفاق` track — a flat negotiated figure — bypasses the calculation
   entirely and must be a first-class path, not a special case.

What the design document gets **right** and should be kept exactly: the
`amount_calculated` / `amount_final` pair on `payslip_lines`. HR reviews and
adjusts a generated draft, and the original survives the adjustment. That is
precisely the workflow the workbook implements by hand.

**Exit criterion:** one month's payroll generated by the ERP reconciles against
the same month's workbook, line by line, before anyone is paid from it.

### Stage 5 — Decommission

The legacy backend goes read-only, then dark. Keep a restorable snapshot of its
database indefinitely — it is the audit trail for everything that happened
before the merge.

---

## 5. Risks

| Risk | Mitigation |
|---|---|
| Attendance cutover loses a day's records | Grace period accepting legacy-format offline submissions; parallel run; reconcile before decommissioning |
| Ported code drags legacy assumptions into the ERP | Each port is re-pointed at ERP employees/departments/RBAC and its tests rewritten — a port is not a copy |
| Approval engine under-generalised at Stage 2 | Design its interface against all four known consumers, not against documents alone |
| Import is wrong and nobody notices | Idempotent, re-runnable importer keyed on `legacy_number`; HR review pass is an explicit exit criterion of Stage 1 |
| Payroll goes live with a calculation error | Reconcile a full month against the workbook before anyone is paid from the ERP |
| One developer, live company, long freeze | Stages are independently shippable; the legacy system stays up throughout; nothing is deleted until its replacement has run a full cycle |

---

## 6. Open decisions

1. **Cutover windows and sign-off.** Who authorises each stage's cutover, and
   what notice does the company get? Stage 3 in particular needs a named date
   and a communication to every employee with the app.
2. **FCM project.** Reuse the legacy Firebase project and its registered device
   tokens, or issue a new one? Reuse avoids re-registering every phone; a new
   project cleanly separates the systems. Reuse is the lower-risk default.
3. **Legacy audit trail.** Does the legacy `audit_logs` history migrate into the
   ERP's table, or stay in the archived snapshot? Migrating it means reconciling
   two `user_id` spaces.
4. **Document numbering continuity.** Do import/export sequence numbers continue
   unbroken across the cutover, or restart? Almost certainly continue — confirm
   with `إدارة الديوان والأرشيف`.
5. **Grace period length** for legacy-format offline attendance submissions. A
   fortnight is the opening proposal; the right number depends on how quickly
   the company can push a Flutter update to every device.
