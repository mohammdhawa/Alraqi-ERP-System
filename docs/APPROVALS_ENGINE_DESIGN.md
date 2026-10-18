# The approvals engine — design for review (work order §4.6)

> **Written 2026-10-16. Status: AGREED 2026-10-16** — the project owner
> answered all seven questions of §9 (each took the recommended option). The
> sections below are amended where an answer settled them.
> The work order (§8, "the order from here") asked for this design to be
> reviewed before the engine is built, because four modules will sit on it:
> leave now; documents in November; purchase requests and payroll approval
> later.
>
> **Read with:** [`LEGACY_TO_ERP_MAPPING.md` §6](LEGACY_TO_ERP_MAPPING.md) (the
> first sketch of the tables), the work order's §8 entries "the decision round,
> answered" (Q1, Q2) and "managers approve in the dashboard too",
> [`EMPLOYEE_APP_REQUIREMENTS.md` §4.2 and §5.3](EMPLOYEE_APP_REQUIREMENTS.md),
> and the merge plan's Stage 2.
>
> **§9 holds the questions only the project owner can answer.** Everything else
> follows legacy, an agreed decision, or a house rule, and says which.

---

## 1. What legacy has today — two engines, not one

Legacy approves things in two unrelated places. The ERP builds one engine that
can run both, with each consumer keeping its own rules.

| | Leave (`leave_request_approvals`) | Documents (`document_workflows`) |
|---|---|---|
| Who the approvers are | Chosen by the employee: 1–10 managers or chiefs, in order | Chosen by the creator: one or more managers, **plus the chief, always last** |
| Order | Sequential only | Sequential **or parallel** among the managers; the chief after all of them |
| Who may act | Only the first pending step's approver | Sequential: the next manager; parallel: any pending manager; the chief only when every manager has acted |
| A rejection | **Ends the request** at once; the steps behind it are closed as `skipped` | A manager's rejection is **recorded and the document goes on**; only the **chief's** decision is final |
| A note on a decision | None | Optional |
| Before the final approval | The balance is checked again (a deducting type may not exceed it) | — |
| Notified | First approver on submit; the next approver on each advance; the requester on the outcome | First approver(s) on create; the next on each advance; the creator on a rejection and on the outcome |
| Reassignment | Admin only: a pending step to another manager or chief, with a reason (3–500 characters), logged, the new approver notified | None |
| Concurrency | Lock the request row, then the step | Lock the document row |
| Use, June–September 2026 | 189 requests: 178 with one approver, 10 with two, 1 with four; 0 reassignments | — |

**Kept in the ERP:** every row above, per consumer, except who the approvers are
— the decision round changed that for leave (§2).

---

## 2. Already decided — not reopened here

| Decision | Where | Means for the engine |
|---|---|---|
| Leave's chain is **resolved, not chosen**: the direct manager, then مدير الموارد | Q1 = B, 2026-10-16 | The engine resolves approvers from rules; the request names none |
| A step resolving to the **same person as the step before it**, or to the **requester**, is recorded `skipped` with its reason | Q2 = A / A | A rule of the engine, for every consumer |
| The direct manager is `ManagementChain`: the unit's manager; past a vacant post or past the requester, to the nearest unit above with one; ending at المدير العام | Decision 15, 2026-09-23 | Step 1's resolver, as built in `app/Shared` |
| **Approving is not a permission**: you act on a step because you are its approver | Mapping §6 | No `approvals.approve`; the check is `step.approver_id === caller's employee` |
| Managers approve in the **app and the dashboard** | 2026-10-14 | One API, two clients; the request screen shows the **live** state |
| A notification opens where its recipient acts; the server sends `type` + `reference`, never a URL | 2026-10-14 | `approval.step_pending` references the subject |
| No legacy data moves | 2026-10-16 | Nothing in flight in legacy is carried; employees re-file (Q6) |

---

## 3. The tables

All in a new `app/Modules/Approvals`, `database/migrations/Approvals/`. Soft
deletes on all three (C23); morph aliases `approval`, `approval_step`,
`approval_reassignment`; `HasAuditLog` on all three (C13).

**`approvals`** — one per subject that needs approving.

| Column | |
|---|---|
| `id` | |
| `approvable_type`, `approvable_id` | The subject, by morph alias: `leave_request` now, `document` in November. `unique(approvable_type, approvable_id)` — one approval per subject |
| `requested_by` → `employees` | Whose request it is. The chain resolves from here |
| `status` tinyint | `ApprovalStatus`: pending · approved · rejected · cancelled |
| `rejection_policy` tinyint | `RejectionPolicy`: **`ends`** (leave — any rejection is final) · **`advisory`** (documents — recorded, the approval goes on; only a `decisive` step's decision ends it). Fixed at submission by the consumer |
| `decided_at` | When it reached approved / rejected / cancelled |
| timestamps, soft deletes | |

**`approval_steps`** — one per approver.

| Column | |
|---|---|
| `approval_id` | |
| `step_order` smallint | Steps sharing an order are **parallel** — each must act; ascending orders are **sequential**. One column serves leave (1, 2) and documents (managers 1…n or all 1, the chief last) |
| `resolver` tinyint | `StepResolver`: `direct_manager` · `unit_manager` (a named post, by department code) · `named_employee` (chosen by the requester — documents). Kept so a skip or a vacancy is explainable later |
| `resolver_ref` string, nullable | The resolver's argument: the department code (`HR`) for `unit_manager` |
| `approver_id` → `employees` | The person who must act. A **person**, not an account: units name employees as managers. **Never null** — a step that resolves to nobody refuses the submission (§9 Q2 = A) |
| `decisive` bool | Under the `advisory` policy, the step whose decision ends the approval (the chief). Ignored under `ends` |
| `status` tinyint | `StepStatus`: pending · approved · rejected · skipped |
| `skip_reason` tinyint, nullable | `SkipReason`: same approver as the step before · the requester · closed by a rejection · closed by a cancellation |
| `acted_by` → `users`, `acted_at`, `note` (text, nullable) | Who actually pressed the button and when. A note is **optional on approve and on reject, for every consumer** (§9 Q6 = A) |
| `unique(approval_id, step_order, approver_id)` | Legacy's `unique(request, user)` is too strict across orders |

**`approval_reassignments`** — legacy's log, generalised: `approval_step_id`,
`step_order` (copied, as legacy argues: the log must read without a join),
`from_employee_id`, `to_employee_id`, `performed_by` → `users`, `reason`
(3–500), timestamps.

**Not stored:** "the current step". It is derived — the lowest `step_order`
with a pending step — so it can never disagree with the steps (P1). Legacy's
`leave_requests.manager_id` mirror is not ported (mapping §5).

---

## 4. Building the chain — submission

A consumer submits a subject with a **route**: an ordered list of step
definitions, each a resolver and its argument.

```
leave:     [ direct_manager ], [ unit_manager: HR ]                       — policy: ends
document:  [ named_employee: m1 ], [ named_employee: m2 ], …,  [ the chief, decisive ]
           (all managers at order 1 when parallel)                         — policy: advisory
```

Legacy's "chief" is whoever holds that role, not necessarily a unit's
manager; how the ERP names that step (a named post, or a named employee) is
decided with the Documents module in November. The engine needs nothing more
than a decisive last step.

`submit()` resolves every step **at submission**, in order, then applies the
skip rules (Q2) before anything is saved:

1. A step whose approver is the **requester** → `skipped` (`the requester`).
   مدير الموارد's own leave: step 2 would be them; it is skipped, and the
   general manager's step 1 approval is enough.
2. A step whose approver is **the same person as the step before it** →
   `skipped` (`same approver`). An HR staff member whose direct manager is
   مدير الموارد: step 2 repeats step 1; one approval.
3. If no step is left pending, the submission is refused — no one is left to
   approve. *(This cannot happen for leave today: step 1 never resolves to the
   requester, by decision 15.)*

Before the skip rules, a step that **resolves to nobody** — a vacant post at
the top of the chain, a unit code with no manager — **refuses the whole
submission** with a message naming the missing post (§9 Q2 = A). So does a
step that resolves to a person with **no active account** (§9 Q8 = A): only an
account can act. Nothing is saved; HR fills the post or fixes the account and
the employee submits again.

Resolved at submission and **frozen**: if the org chart changes afterwards,
the steps stand. Legacy froze its chain the same way (the employee picked it).
A changed chart is fixed by reassignment (§6), never by silently moving a
pending request (§9 Q3 = A).

---

## 5. Acting — approve, reject

`act(step, user, approve | reject, ?note)`:

- **Whose turn.** The step must be pending, at the **current** order, and the
  caller's account must be linked to the step's `approver_id`. Otherwise 422
  «ليس دورك في اعتماد هذا الطلب.» (legacy: "It is not your turn"). Being an
  administrator does not make it your turn — legacy's rule.
- **Approve.** The step is approved. If every step at that order is now
  decided, the next order becomes current and its approvers are notified. If
  none remain, the approval is **approved** and the subject's completion hook
  runs.
- **Reject, policy `ends` (leave).** The step is rejected, every other pending
  step is `skipped` (`closed by a rejection`), the approval is **rejected**, the
  hook runs. Legacy's rule.
- **Reject, policy `advisory` (documents).** The step is recorded rejected and
  the approval goes on, exactly as an approve would advance it — unless the
  step is `decisive`, whose rejection ends it. Legacy's document rule.
- **The final-approval guard.** Before the last approval is recorded, the
  engine asks the subject `canBeApproved()`; a refusal is a 422 with the
  subject's own message and nothing changes. Leave answers it with legacy's
  balance re-check (legacy's message was English; the port says it in Arabic).
- **Locks.** The approval row first, then its steps — legacy's order — so two
  approvers acting at once, or an approve racing a reassignment, serialise.
- **After commit, the consumer is told.** The engine owns the steps and the
  approval's status; the subject owns its own status. The completion hook is the
  only writer of the subject's status (`leave_requests.status` mirrors the
  approval and is written nowhere else — mapping §5).

### Withdrawing — new, agreed (§9 Q1 = B)

`withdraw(approval, user)`: the **requester** may withdraw a pending approval
**while no step has been decided** — no step approved or rejected. A step
skipped at submission is not a decision and does not block it. Once any
approver has acted, the request can no longer be withdrawn (422).

The approval becomes **cancelled**, every pending step is `skipped` (`closed by
a cancellation`), and the completion hook runs with the cancelled approval —
the same path as a decision, so the subject's status is still written in one
place. Legacy has no withdrawal; this is a deliberate difference.

### The contract a subject implements

```php
interface Approvable
{
    public function approvalRequester(): Employee;                 // whose request
    public function canBeApproved(): ?string;                      // null, or why not (Arabic)
    public function onApprovalDecided(Approval $approval): void;   // approved / rejected / cancelled
    public function describeForApprover(): string;                 // the notification's body, the consumer's wording
}
```

`describeForApprover()` keeps each consumer's own words in the notifications —
legacy's «طلب إجازة جديد», «دورك لمراجعة طلب إجازة» for leave; «مستند جديد
بانتظار موافقتك» for documents — without the engine knowing what a leave is.

---

## 6. Reassigning a step

Legacy's escape hatch for an approver who can no longer act (left, on leave,
wrong person after a chart change), kept:

- Permission **`approvals.reassign`**, **row-scoped** through the requester's
  current unit (GAP-3, as every other scoped permission) — legacy's was
  admin-only and company-wide (§9 Q4 = A; granted to HR company-wide at first).
- Only a **pending** step of a **pending** approval; not to the same person; not
  to someone already on the chain; a reason of 3–500 characters. Legacy's rules
  and messages.
- The new approver is **any active employee with an active account**, not the
  requester and not already on the chain (§9 Q7 = A) — legacy required a
  manager or chief role, which the ERP does not have.
- Logged in `approval_reassignments`; the new approver is notified
  (`approval.step_pending` if the step is current; legacy's «أصبحت ضمن مسار
  اعتماد…» otherwise).
- It moves the assignment and never decides: legacy's "not an approval bypass".

---

## 7. What clients see

| Method & path | Who | |
|---|---|---|
| `GET /api/me/approvals` | the caller as an **approver** | Steps waiting on the caller **now** (current, pending), newest first, paginated; `?status=decided` for what they decided. The app's and the dashboard's inbox (2026-10-14) |
| `GET /api/approvals/{approval}` | the requester · anyone on its chain · `approvals.view` in reach | The **live** state: every step, its approver (stub), status, who acted and when, the note, skips with their reasons, reassignments, and `can_act` / `current_step_id` for the caller. Read fresh on every call — the request screen never acts on a cached state (2026-10-14) |
| `POST /api/approvals/{approval}/steps/{step}/approve` | the step's approver | `{ note? }` |
| `POST /api/approvals/{approval}/steps/{step}/reject` | the step's approver | `{ note? }` |
| `PATCH /api/approvals/{approval}/steps/{step}/assignee` | `approvals.reassign`, in reach | `{ employee_id, reason }` |
| `POST /api/approvals/{approval}/withdraw` | the requester, while no step is decided | §5 "Withdrawing" (Q1 = B). A consumer may expose it under its own route instead (a leave request's cancel) — the engine call is the same |

The consumer embeds the approval in its own resource (a leave request carries
its `approval`), so the requester never needs a second call.

**Permissions added:** `approvals.view` (row-scoped: HR reading the chains in
its reach — legacy's admin-only read view, §9 Q5 = A) and `approvals.reassign`
(row-scoped). Both classified in `PermissionReach` on arrival.

**Notifications:** `approval.step_pending` to each approver whose step becomes
current — referencing the **subject**, so the tap opens the approval screen
for that subject. The consumer sends its own receipts and outcomes
(`leave.submitted`, `leave.approved`, `leave.rejected`) from its hook, in
legacy's words.

---

## 8. Building it — in order, each step green

1. **The engine** — tables, enums, models, `submit` / `act` / `reassign`,
   `pendingFor`, the resolvers, the skip rules — tested against a **fake
   subject**, so it is proven before leave exists. Every legacy rule in §1 is
   a test.
2. **The endpoints** in §7, scoped, with the live-state read.
3. **Leave on top** (§4.7) — its routes, the `Approvable` implementation and
   legacy's leave tests: `LeaveRequestSequentialApprovalTest`,
   `LeaveApprovalReassignmentTest` and the rest named in §4.7.
4. Documents join in November, on the `advisory` policy — the engine already
   has it, tested with the fake subject in step 1.

---

## 9. Questions for the project owner — answered 2026-10-16

Each had a recommendation. None was defaulted (2026-10-16 rule); the project
owner answered all seven on 2026-10-16:

| Q | Answer | Built as |
|---|---|---|
| 1 | **B** — withdraw while no step is decided | `withdraw()` by the requester; cancelled, pending steps skipped (§5). **New vs legacy** |
| 2 | **A** — refuse the submission | A step resolving to nobody refuses it, naming the post (§4); `approver_id` is never null |
| 3 | **A** — frozen at submission | Steps never re-resolve; reassignment is the fix (§4, §6). As legacy |
| 4 | **A** — `approvals.reassign`, row-scoped | HR company-wide at first (§6). **Differs from legacy** (admin-only) |
| 5 | **A** — `approvals.view`, in reach | Besides the requester and the chain (§7) |
| 6 | **A** — optional note, every consumer | On approve and reject (§3). **Differs from legacy leave** (no note) |
| 7 | **A** — any active employee with an active account | Not the requester, not already on the chain (§6). **Differs from legacy** (manager/chief role) |
| 8 | **A** — refuse, as Q2 | *Asked while building, 2026-10-16:* a step that resolves to a person **with no active account** (never issued, or disabled) refuses the submission, naming that approver — only an account can act, so the step would be stuck exactly like a vacant post |

The questions as they were asked (Q8 is above):

**Q1 — Can an employee withdraw a pending request?** Legacy has **no** such
action: once filed, a leave request is decided or reassigned, never withdrawn
(only an HR excuse is cancelled).

| | |
|---|---|
| **A** | No, as legacy |
| **B** | Yes, while **no step has been decided** |
| **C** | Yes, at any time before the final decision |

*Recommended: **B*** — it covers the employee who filed the wrong dates
without letting them undo a decision already made. But it is a new workflow,
so only on your word.

**Q2 — A step that resolves to nobody.** For example, the HR division has no
manager set, or the chain climbs to an empty general manager's post.

| | |
|---|---|
| **A** | The submission is refused, with a message naming the missing post, until HR fills it |
| **B** | The step is created with no approver and appears on HR's list to reassign |

*Recommended: **A*** — a request nobody can act on is worse than a clear
refusal; with the tree seeded as it is, it should not occur.

**Q3 — The org chart changes while a request is pending.** A manager is
replaced after an employee files.

| | |
|---|---|
| **A** | The step keeps the approver it was given at submission; HR reassigns if needed — as legacy, where the chain never moved |
| **B** | The current step is re-resolved when it becomes current, so the new manager gets it |

*Recommended: **A*** — predictable, and reassignment already exists for it.

**Q4 — Who may reassign a step?** Legacy: administrators only, company-wide.

| | |
|---|---|
| **A** | A grant of `approvals.reassign`, scoped like every other ERP permission (HR company-wide, for instance) |
| **B** | Super administrators only, as legacy |

*Recommended: **A***, granted to HR company-wide at first.

**Q5 — Who may read a request's approval chain besides the requester and its
approvers?** Legacy let administrators see every queue, read-only.

| | |
|---|---|
| **A** | Holders of `approvals.view`, in their reach |
| **B** | Nobody else |

*Recommended: **A*** — HR needs to see where a request is stuck to know whom
to reassign it to.

**Q6 — A note on a decision.** Documents take one; legacy leave never did.

| | |
|---|---|
| **A** | Optional on approve and reject, for every consumer |
| **B** | Required when rejecting, optional when approving |
| **C** | Leave as legacy: no note |

*Recommended: **A*** — it costs the approver nothing and tells the requester
why.

**Q7 — Who may a step be reassigned to?** Legacy: any manager or chief (a
role). The ERP has no manager role; managers are whoever the tree names.

| | |
|---|---|
| **A** | Any active employee with an active account — not the requester, not already on the chain |
| **B** | Only employees who manage a unit |

*Recommended: **A*** — the deputy who stands in is often not a unit manager.

---

## 10. Build status

### ✅ Step 1 — the engine, built 2026-10-16

`app/Modules/Approvals/` and `database/migrations/Approvals/`, tested against
a fake subject (`tests/Feature/Approvals/Fakes/FakeApprovable.php`) — no
routes yet.

| Piece | What | Tests |
|---|---|---|
| The three tables, five enums, three models | As §3; audited, soft-deleted, morph aliases `approval`, `approval_step`, `approval_reassignment` | `MorphMapTest`, `AuditTargetStubTest` |
| `ApprovalRoute` + `StepDefinition` | A consumer's route: the policy and ordered stages of parallel steps | `ApprovalSubmissionTest` |
| `ApproverResolver` | `direct_manager` (ManagementChain), `unit_manager` (by code, never climbs), `named_employee`; nobody → refused (Q2); no active account → refused (Q8) | `ApprovalSubmissionTest` (19) |
| `ApprovalService::submit` | Resolve all, apply the skip rules, save, notify the first approver(s); frozen (Q3) | same |
| `ApprovalService::act` | Whose turn, the final-approval guard, `ends` and `advisory`, parallel orders, the next approvers notified, the optional note (Q6) | `ApprovalActingTest` (16) |
| `ApprovalService::withdraw` | The requester, before any decision (Q1) | `ApprovalWithdrawalTest` (5) |
| `ApprovalService::reassign` | Legacy's rules and log; to any active employee with an active account (Q7) | `ApprovalReassignmentTest` (7) |
| `ApprovalInbox` | `pendingFor` (current steps only) and `decidedBy` | `ApprovalInboxTest` (5) |
| `approvals.view` | Seeded now, because the audit trail must gate the new types; **company-only** until step 2 enforces its row scope. `approvals.reassign` arrives with its endpoint | `PermissionSeederTest`, `PermissionReachTest` |

**Settled while building** — none changes a workflow; each is written here so
it is not rediscovered:

1. **The completion hook runs inside the engine's transaction**, not after
   commit as §5 first said. The hook is the only writer of the subject's
   status; inside the transaction, "the approval says approved, the leave says
   pending" cannot happen, and a failing hook rolls the decision back
   (tested). Notification rows are written in the same transaction, as the
   attendance module does.
2. **`describeForApprover()` takes the moment** (`ApproverNotice`: submitted,
   your turn, reassigned now, reassigned ahead) and returns a title and a
   body (`ApproverMessage`) — legacy uses a different sentence for each.
3. **A later step reassigned** is notified as `approval.step_assigned`
   («أصبحت ضمن مسار اعتماد»), new in the catalogue; a current one as
   `approval.step_pending`.
4. **"The step before it"** (Q2) is the nearest earlier stage that still has
   someone to ask: in `[x], [requester], [x]` the third step is skipped as a
   repeat of the first.
5. **Two parallel steps naming the same person** are refused at submission.
6. **An advisory route must end with exactly one decisive step, alone in its
   stage** — legacy's chief. Anything else is a programming error in the
   consumer (`InvalidArgumentException`), not a user's 422.
7. **`approval_reassignments` carries `approval_id`** beside the step, as
   legacy's log carried the request id — the log reads without a join.
8. **Withdrawal by anyone but the requester is 403**; every other engine
   refusal is 422, «ليس دورك في اعتماد هذا الطلب.» included. Step 2's
   policies decide what an off-chain caller sees at the route.

**For November, with Documents — not decided here:** under the advisory
policy, if the decisive step is **skipped** at submission (the chief files a
document themselves), nothing is left to decide the outcome; the engine
currently approves once the other steps have acted. Documents must say what
legacy's chief-files-own-document case should do before it relies on this.

### ✅ Step 2 — the endpoints, built 2026-10-18

The six routes of §7, in `app/Modules/Approvals/` (`routes.php`, `Controllers/`,
`Policies/`, `Requests/`, `Resources/`); the inbox under `/api/me/approvals`.
Documented in `API_DOCUMENTATION.md` §15.

| Piece | What | Tests |
|---|---|---|
| `GET /api/me/approvals` | `pendingFor` / `?status=decided`, paginated; each row carries the subject's summary and the requester | `MyApprovalsEndpointTest` (7) |
| `GET /api/approvals/{approval}` | The live state: every step, skips with reasons, decisions with notes, reassignments, and `can_act` / `current_step_id` / `can_withdraw` / `can_reassign` for the caller | `ApprovalReadEndpointTest` (10) |
| `…/steps/{step}/approve`, `/reject` | `act()`, `{ note? }` ≤ 500 | `ApprovalDecisionEndpointTest` (11) |
| `…/steps/{step}/assignee` | `reassign()`, `{ employee_id, reason }` | `ApprovalReassignEndpointTest` (8) |
| `…/withdraw` | `withdraw()` | `ApprovalWithdrawEndpointTest` (5) |
| `ApprovalPolicy` | `view`: the requester, anyone holding a step, or `approvals.view` in reach; `reassign`: `approvals.reassign` in reach — both through the requester's **current** unit (D6) | the read and reassign tests |
| `approvals.reassign` | Seeded; it and `approvals.view` are now row-scoped in `PermissionReach` | `PermissionSeederTest`, `PermissionReachTest` |

**Decided by the project owner (2026-10-18):** an inbox row carries the
subject's own summary, as legacy's queue listed whole leave requests — not a
bare reference the client must open row by row. `Approvable` gains
`approvalSummary()` (a title and a line, in the consumer's words) and a
static `approvalSummaryRelations()`, the relations it reads; every list
eager-loads them per subject type through `morphWith` (`ApprovalRelations`),
so a page costs the same queries whatever its length (tested).

**Settled while building** — none changes a workflow:

1. **Every route sits behind `can:view`.** Someone who may not read an
   approval gets a 403 before their body is read, on every verb — never the
   engine's 422 «ليس دورك…» or «… ليس قيد الاعتماد», which would tell an
   outsider that the request exists and where it stands. A reader whose turn
   it is not (HR with `approvals.view`) gets the engine's 422: reading does not
   make it your turn.
2. **"On the chain" is holding a step now** — decided and skipped steps
   included, so a manager passed over by Q2 still reads the outcome. Someone
   whose step was reassigned away no longer holds it, and reads it only
   through `approvals.view`.
3. **Two refusals, as everywhere since decision 19:** no `approvals.view` at
   all is the plain 403; a grant that stops short of the requester's unit is
   `out_of_scope` «طلب الاعتماد هذا لموظف خارج نطاق صلاحياتك.».
4. **A step is bound scoped to its approval**: a step id from another request
   is a 404.
5. **A reassignment target is validated as an employee who has not left**
   (422 on `employee_id`); whether they may take the step stays the engine's
   rule (Q7).
6. **Every response is the live state read after the action**, so a client
   redraws from the server, never from what it sent (2026-10-14).
7. **`Actor`** — the `{ id, name }` of an account that acted — moved from
   Attendance to `app/Shared/Support`, now that two modules embed it.

**For the reassign screen:** the employee picker reads `GET /api/hr/employees`,
so whoever holds `approvals.reassign` needs `hr.employees.view` beside it.

**Next: step 3** — leave on the engine (work order §4.7).
