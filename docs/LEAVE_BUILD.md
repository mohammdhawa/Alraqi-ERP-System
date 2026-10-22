# Leave — the build design (work order §4.7)

> **Written 2026-10-18, for review — no leave code until it is agreed.** The
> rules are legacy's (`../Raqi-App`: `LeaveRequestController`,
> `LeaveBalanceController`, `LeaveTypeController`, the `LeaveType`,
> `LeaveBalance`, `LeaveRequest` models and `User`'s balance methods, the form
> requests, and the ten `Leave*Test` files), with the differences already
> agreed — the decision round (work order §8, "the decision round, answered")
> and the approvals engine ([`APPROVALS_ENGINE_DESIGN.md`](APPROVALS_ENGINE_DESIGN.md)).
> The column-by-column port is [`LEGACY_TO_ERP_MAPPING.md` §5](LEGACY_TO_ERP_MAPPING.md).
> This file says how the module is built: its pieces, the order, and the
> choices made while designing it.
>
> **Answered by the project owner while designing (2026-10-18):**
>
> | | Answer |
> |---|---|
> | Attachments on a request | **Up to 5 files**, PDF / JPG / PNG, 10 MB each. **Required** (at least one) where the type requires an attachment — sick leave; optional on every other type |
> | Attachments on an HR excuse | **Optional** for HR, whatever the type — the requirement binds the employee's own request only |
> | A receipt to the requester on submission | **None, as legacy.** The first approver is told; the requester is told the outcome. `leave.submitted` stays catalogued and unsent |

---

## 1. Three slices, each green on its own

| Slice | What | Legacy tests ported |
|---|---|---|
| **1. Types and balances** | `leave_types` seeded with the live nine, the types catalogue and its CRUD; `leave_balances`, `config/leave.php`, the balance calculator; HR's balances list and update (the opening adjustment, Q6); `GET /api/me/leave-balance`; the permissions | `LeaveTypeTest`, `LeaveBalanceManagementTest` (all but the one that files a request — slice 2) |
| **2. Requests on the engine** | `leave_requests`, the shared `attachments` table; filing with every legacy rule and the resolved chain; `LeaveRequest` as the engine's `Approvable`; my requests, withdraw, the request read and its files; the real `ApprovedLeave` binding, so attendance sees leave | `LeaveRequestSubmissionTest`, `LeaveRequestSequentialApprovalTest`, `LeaveApprovalReassignmentTest`, `LeaveRequestQueryControlsTest` (the own-list half), the last case of `LeaveBalanceManagementTest`, the leave half of `WorkingCalendarTest` |
| **3. HR's side** | Excuses (file, undo), return to work, the register | `LeaveExcuseTest`, `LeaveExcuseCancellationTest`, `LeaveReturnToWorkTest`, `LeaveRegisterTest` |

**Not ported:** the approver picker and everything about choosing approvers
(`GET /leave-managers`, `approver_ids`, `manager_id`, the "name yourself"
refusals — Q1); free-text `leave_type` and its fallback to deducting (no legacy
row is imported, and new clients send an id); XLSX export (a dashboard feature,
later, as attendance's); `ensureLegacyApprovalStep()` (rows that predate
approval steps do not exist here). The engine's own rules — whose turn, the
final-approval guard, a rejection closing the rest, reassignment — are already
tested against the fake subject; slice 2 ports legacy's leave cases of them
against the real one.

---

## 2. The module

`app/Modules/Leave/` — models, enums, services, policies, requests, resources,
`routes.php` (mounted at `/api/leave`); its self-service routes are in the Me
module (`/api/me/…`), as attendance's are. `database/migrations/Leave/`.

**Depends on** Attendance (the calendar's `workingDaysBetween()`, and the
`ApprovedLeave` contract it implements) and Approvals (the engine). Neither
depends on Leave — the direction attendance's contract was written for.

| Piece | What |
|---|---|
| `LeaveType` | The catalogue. Audited, soft-deleted, morph `leave_type` |
| `LeaveBalance` | One employee's allocation and adjustment for one year. Audited, morph `leave_balance` |
| `LeaveRequest` | A request or an HR excuse. Audited, soft-deleted, morph `leave_request`. Implements `Approvable` |
| `LeaveBalances` (service) | Allocated, used, remaining, over-balance, non-deducting — legacy's `balancePayload()` |
| `LeaveRequestService` | File, withdraw (slice 2) |
| `LeaveExcuseService`, `ReturnToWorkService` | Slice 3 |
| `LeaveRequestQuery` | The own list and the register, filters and sorts |
| `ApprovedLeaveFromRequests` | Attendance's `ApprovedLeave`, read from approved rows; bound in place of `NoApprovedLeave` |
| `app/Shared` attachments | Table, model, store, stream — §5 |

### Configuration — `config/leave.php`

| Key | Value | |
|---|---|---|
| `annual_days` | 15 | The default allocation when no row exists for the year (D13) |
| `hr_unit_code` | `HR` | The unit whose manager is step 2 (Q1 = B). A code, not an employee id, so a new HR manager needs no deploy |
| `attachments.max_files` / `max_kb` / `mimes` | 5 / 10240 / pdf, jpg, jpeg, png | The owner's answer above |

---

## 3. Slice 1 — types and balances

### `leave_types`

Mapping §5's columns plus `requires_attachment`, seeded with the live nine by
a **migration** (as legacy, so every environment has them), sick **not**
deducting and requiring an attachment, `attendent_execused` →
`attendance_excused`. No `sort_order`: the order is the id, legacy's argument
kept.

| Route | Who | |
|---|---|---|
| `GET /api/leave/types` | any authenticated caller | Active types, in id order. `?for=requests` / `excuses`, `?deducts_balance=`. Retired types (`?active=false`) only for a holder of `leave.types.update` — legacy showed them to admins only |
| `POST /api/leave/types` | `leave.types.create` | Legacy's rules: `code` `^[a-z0-9_]+$`, unique; `name_ar` unique; `name_en` unique when given |
| `PATCH /api/leave/types/{type}` | `leave.types.update` | Changing `deducts_balance` never rewrites a filed request — the request snapshots it |
| `DELETE /api/leave/types/{type}` | `leave.types.delete` | A type in use is **deactivated** instead, and the response says so (legacy) |

All three writes are **company-only** in `PermissionReach`: a catalogue, as
departments and job titles.

### `leave_balances` and the calculator

`unique(employee_id, year)`, `allocated_days`, `adjustment_days`, `note`,
`updated_by` → users. For an employee and a year (legacy's `User` methods):

- **allocated** = the row's `allocated + adjustment` (floored at 0), or
  `config('leave.annual_days')` when there is no row;
- **used** = approved, balance-deducting `requested_days` of leave **starting**
  in that year — a leave from 30 December to 3 January is charged wholly to
  the first year, as legacy;
- **remaining** = max(0, allocated − used); **over_balance** = max(0, used −
  allocated), non-zero only after a forced excuse; **non_deducting** = approved
  days under a non-deducting type, shown, never counted.

Computed, never stored. **Pending requests reserve nothing** — legacy checks
the balance at filing and again at the final approval, which is what stops two
pending requests from overdrawing it together.

| Route | Who | |
|---|---|---|
| `GET /api/me/leave-balance?year=` | self | The figures above; the current year by default |
| `GET /api/leave/balances?year=` | `leave.balances.view`, in reach | Every employee in reach, paginated, with `is_custom` (false = still on the default) and the employee filters attendance's list takes |
| `PUT /api/leave/balances/{employee}/{year}` | `leave.balances.update`, the employee in reach | `allocated_days` 0–365, `adjustment_days` −365…365, `note` ≤ 255. Refused below the days already used that year (legacy). One row per year, updated in place |

The **opening adjustment** of the transition procedure (Q6) is entered here:
HR sets each person's adjustment at their switch date. Nothing new is needed
for it.

---

## 4. Slice 2 — requests on the engine

### `leave_requests`

Mapping §5's columns: `employee_id`, `leave_type_id` (required), `origin`
(`request` 1 · `hr_excuse` 2), `deducts_balance` and `requested_days` as
snapshots, `reason` ≤ 2000, `start_date`, `end_date`, `status` (`pending` 1 ·
`approved` 2 · `rejected` 3 · `cancelled` 4), `decided_at`, `created_by`, the
cancellation and return-to-work columns. No `manager_id`, no unit snapshot
(D6), no free-text type.

### Filing — `POST /api/me/leave-requests` (multipart)

`leave_type_id`, `start_date`, `end_date` (≥ start), `reason`, `attachments[]`.
The checks, in legacy's order, each its own refusal:

1. The type is active and **for requests** — an official mission is HR's to
   record, not the employee's to request.
2. A reason where the type requires one; at least one file where it requires an
   attachment (sick leave); never more than five.
3. The span holds at least one working day («الفترة المحددة لا تتضمن أيام
   عمل.»). `requested_days` is the working days in it, through attendance's
   calendar — today every day is one (Q8).
4. A deducting type does not exceed the remaining balance (the remaining
   days are named in the refusal — §10, settled 4).
5. No overlap with a pending or approved request or excuse.

Then, **in one transaction** with the caller's `employees` row locked — legacy
locked the user row for the same read-then-write: the request is saved, the
files stored, and the engine submits it on the leave route
`[direct_manager], [unit_manager: HR]`, policy `ends`. A chain that cannot
resolve — a vacant HR post, an approver with no active account (§9 Q2, Q8) —
**refuses the whole filing**, nothing saved, no file left behind.

The first approver is told «طلب إجازة جديد» / «{الاسم} قدّم طلب إجازة.» — legacy's
words, through `describeForApprover()`. No receipt to the requester (the
owner's answer).

### `LeaveRequest` as the engine's subject

| Contract | Leave's answer |
|---|---|
| `approvalRequester()` | The employee |
| `canBeApproved()` | Legacy's final re-check: a deducting request still fits the remaining balance — «طلب الإجازة يتجاوز رصيد الإجازات المتبقي للموظف.» |
| `onApprovalDecided()` | Writes `status` and `decided_at` — the only writer for a request's outcome. Approved / rejected: tells the requester in legacy's words («تمت الموافقة على طلب إجازتك من … إلى …» / «تم رفض طلب إجازتك …»), `leave.approved` / `leave.rejected`. Cancelled (a withdrawal): nothing to tell — the requester did it |
| `describeForApprover()` | Submitted «طلب إجازة جديد»; your turn «دورك لمراجعة طلب إجازة» / «يرجى مراجعة طلب إجازة {الاسم}.»; reassigned «تم إسنادك لاعتماد طلب إجازة» with «… بانتظار مراجعتك الآن.» or «أصبحت ضمن مسار اعتماد طلب إجازة {الاسم}.» |
| `approvalSummary()` | The type's name, and «{n} أيام · {من} → {إلى}» |

**Withdrawing** — `POST /api/me/leave-requests/{leaveRequest}/cancel`, the
caller's own request: the engine's `withdraw()`, so only while no step has been
decided (§9 Q1 = B). The approval and the request become cancelled together.

### Reading

| Route | Who | |
|---|---|---|
| `GET /api/me/leave-requests` | self | Legacy's filters — `status` / `statuses`, `leave_type_id(s)`, `year`, `month`, `date_from` / `date_to` (overlap), `is_excuse` — and sorts; HR's excuses for the caller included. Paginated |
| `GET /api/leave/requests/{leaveRequest}` | the employee, anyone on its chain, `leave.requests.view` in reach | One request — what an approver opens from the inbox. Same refusals as the approval read |
| `GET /api/leave/requests/{leaveRequest}/attachments/{attachment}` | as the read | The file, streamed with `Cache-Control: private, no-store` — never a URL |

Every request carries its type, its files (`id`, name, type, size), and its
**approval** — the engine's live state (`APPROVALS_ENGINE_DESIGN.md` §7), so
the app needs no second call. An excuse has no approval (`null`).

### Attendance sees leave

`ApprovedLeaveFromRequests` replaces `NoApprovedLeave`: approved rows, the
span `start_date`–`end_date`, `is_excuse` from `origin`, the type, and who
approved (the last decisive step's account) or filed it. From that moment the
check-in guard, the daily board, the monthly report and the day grid count
leave — each already tested through a fake; slice 2 adds the end-to-end case.

---

## 5. Attachments — shared, for documents in November

`attachments` in `app/Shared` (`database/migrations/Shared/`): `attachable_type`,
`attachable_id` (morph), `disk`, `path`, `original_name`, `mime_type`, `size`,
`uploaded_by` → users, timestamps, soft deletes; audited, morph `attachment`.

- Files on the **private** disk under `attachments/{alias}/{id}/{uuid}.{ext}` —
  never the client's file name on disk, never a public URL (mapping finding 5).
- `AttachmentStore` writes and streams; **who may read is the subject's
  policy**, not the store's — a leave file is readable exactly by who may read
  the leave.
- Stored inside the filing's transaction; a refusal after the files were
  written deletes them, so a refused filing leaves nothing behind.
- **Not in v1:** adding or removing files after filing. A wrong file is
  withdrawn and re-filed while nobody has decided.

---

## 6. Slice 3 — HR's side

Every route row-scoped through the employee's **current** unit (D6), as
attendance; out of reach is 403 `out_of_scope`, before any body is read.

| Route | Permission | Legacy's rules, kept |
|---|---|---|
| `POST /api/leave/excuses` | `leave.excuses.create` | Approved at once, no chain, `origin: hr_excuse`, `created_by` the filer. The type must be **for excuses**; reason where required; files optional (the owner's answer). Working days ≥ 1; no overlap; **no accepted check-in on any day of it** — the refusal lists the attended dates; a refused check-in does not count as attended; a deducting type may not exceed the balance unless `force: true`. The employee is told «تم تسجيل عذر عن غيابك», saying whether it was deducted (`leave.excuse_filed`) |
| `DELETE /api/leave/excuses/{leaveRequest}` | `leave.excuses.delete` | An excuse only — a request is refused and pointed at its chain; a standing one only; twice is refused. The row is kept, cancelled, with who, when and the optional reason; the employee is told the absence is back (`leave.excuse_cancelled`) |
| `POST /api/leave/requests/{leaveRequest}/return-to-work` | `leave.requests.returnToWork` | An approved **request** only (an excuse is pointed at its undo). `returned_on` defaults to today and must fall inside the leave. The leave is cut back to the day before, `requested_days` recounted so the unused days return; returning on the first day cancels it. `original_end_date` is written once. **The approval is untouched.** The employee is told (`leave.returned_to_work`, new in the catalogue) |
| `GET /api/leave/requests` | `leave.requests.view` | The register: a window (`from` / `to`, or `month` + `year`; the current month by default; at most 366 days; never inverted), approved rows and excuses by default, `status` to widen; the employee filters; each row with `days_in_range` and `calendar_days_in_range`; a `summary` over the **whole** filtered set, not the page — totals, by type, by status |

**One deliberate reading.** A request's status is written by the engine's hook
for its outcome; **return to work and an excuse's undo** are HR acts on a row
that has already been decided, and write `cancelled` (or the shortened span)
themselves — the approval stays approved, because the decision stands for the
days it covered (legacy's argument, kept). These are the only other writers.

---

## 7. Permissions

| Permission | Reach |
|---|---|
| `leave.types.create`, `.update`, `.delete` | company-only (a catalogue) |
| `leave.balances.view`, `.update` | row-scoped |
| `leave.requests.view` | row-scoped — the register and reading others' requests |
| `leave.requests.returnToWork` | row-scoped |
| `leave.excuses.create`, `.delete` | row-scoped |

Nine, each classified in `PermissionReach` as it arrives. **Filing, reading
and withdrawing your own leave need none** — `/api/me`. **Approving needs
none** — the engine.

A manager reaches their units' leave by a scoped grant («الوحدات التي أديرها»),
not by a role name — the same as attendance. Legacy let any attendance-report
holder excuse within their department; here that is a grant HR gives.

---

## 8. Where leave differs from legacy

Each agreed, each a test that names it:

1. Approvers are resolved — direct manager, then مدير الموارد — never chosen
   (Q1), with the same-approver and requester skips (Q2).
2. Sick leave does not deduct and needs its medical report (Q3); attachments
   exist at all (the owner's answers above).
3. A pending request can be withdrawn before any decision (engine §9 Q1).
4. A decision may carry a note (engine §9 Q6).
5. No free-text leave type; no unit snapshot on the row (D6).
6. Messages are Arabic throughout — legacy's refusals were English.
7. No data imported; balances open by HR's adjustment (Q6).

---

## 9. Testing

Feature tests per slice, against the real engine, the real calendar and a tree
like attendance's, ported case by case from legacy (§1) with literal expected
values. Plus, new: the attachment rules (count, type, size, required for sick,
optional elsewhere, private, streamed only to readers, nothing left after a
refused filing); the chain refusals leaving nothing saved; the inbox summary
of a real leave; attendance's reports with real leave. Every guard is checked
by disabling it and watching its test fail, as in the earlier slices.

---

## 10. Build status

### ✅ Slice 1 — types and balances, built 2026-10-18

`app/Modules/Leave/` (types, balances, the calculator), `config/leave.php`,
`database/migrations/Leave/`; documented in `API_DOCUMENTATION.md` §16.1–16.2.

| Piece | Tests |
|---|---|
| `leave_types` seeded with the live nine; the catalogue, its filters and CRUD; a used type deactivated, not deleted | `LeaveTypeTest` (14) |
| `leave_requests` and `leave_balances` tables; `LeaveBalances` — allocation + adjustment − approved deducting days of leave starting that year | `LeaveBalanceCalculatorTest` (10) |
| `GET /api/me/leave-balance`, HR's `GET /api/leave/balances` and `PUT …/{employee}/{year}`, row-scoped | `LeaveBalanceManagementTest` (12) |
| `ApiRespond::paginated()` gains `$extra` — facts about the whole result beside `meta` | `PaginatedExtraTest` (2) |

**Settled while building** — none changes a workflow:

1. **The `leave_requests` table arrived in slice 1**, with the calculator that
   reads it; slice 2 adds the filing, the engine and the endpoints.
2. **`leave.balances.view` and `leave.requests.view` were seeded with their
   tables**, because the audit trail must gate every new morph alias on an
   existing read permission; `leave.requests.view` stays **company-only**
   until slice 2's endpoints enforce its row scope — as `approvals.view` did.
3. **A leave type's history is gated on `auth.audit.view` alone**: a
   catalogue with no personal data that every account reads, so there is no
   module read permission to gate it on, and a write permission may not gate a
   read.
4. **A refusal carries its figures in its message** — «… (5 يوم).» — because
   a domain exception has no payload (C17). Legacy returned the balance beside
   its message; the client reads the balance from its own endpoint.
5. **English names** for the three live types legacy recorded none for:
   *Excused attendance*, *External task*, *Study leave*.

### ✅ Slice 2 — requests on the engine, built 2026-10-18

Documented in `API_DOCUMENTATION.md` §16.3–16.4.

| Piece | Tests |
|---|---|
| `attachments` in `app/Shared` — private disk, a server-chosen name, streamed | `AttachmentStoreTest` (5) |
| `LeaveRequest` as the engine's `Approvable`: the leave route, the balance re-check, the outcome and legacy's words for every notification, the inbox summary | `LeaveApprovalSubjectTest` (10) |
| Filing — `POST /api/me/leave-requests`, legacy's rules, files, nothing left behind by a refused chain | `LeaveRequestSubmissionTest` (19) |
| The chain over HTTP | `LeaveRequestSequentialApprovalTest` (6) |
| My list, withdrawing | `MyLeaveRequestsTest` (9) |
| The request read and its files — `LeaveRequestPolicy`; `leave.requests.view` now row-scoped | `LeaveRequestReadTest` (8) |
| `ApprovedLeaveFromRequests` replaces `NoApprovedLeave`: attendance sees real leave | `LeaveInAttendanceTest` (6) |

**Settled while building:**

6. **The filters are one form request** (`LeaveRequestsFilterRequest`) that
   the register will reuse — legacy's own list and register share them.
7. **"Who approved"** on attendance's board is the account that took the
   **last** approved step — مدير الموارد on a full chain.
8. **A self-filed request has no `created_by`**, as legacy: only HR's excuses
   record a filer.

### ✅ Slice 3 — HR's side, built 2026-10-18 — §4.7 complete

Documented in `API_DOCUMENTATION.md` §16.5–16.8.

| Piece | Tests |
|---|---|
| `POST /api/leave/excuses` — approved at once, refused on an attended day, `force` past the balance, files optional | `LeaveExcuseTest` (17) |
| `DELETE /api/leave/excuses/{leaveRequest}` — the row kept, the absence back, the employee told | `LeaveExcuseCancellationTest` (13) |
| `POST …/return-to-work` — cut back or cancelled, the approval untouched, the days returned; `leave.returned_to_work` joins the catalogue | `LeaveReturnToWorkTest` (14) |
| `GET /api/leave/requests` — the register, clipped to its window, the summary over the whole set | `LeaveRegisterTest` (14) |

**Settled while building:**

9. **`leave.excuses.delete` arrived with `.create`**, one grant pair.
10. **The register sorts** by `start_date` (default), `end_date`,
    `requested_days`, `created_at`, `employee_name` and `id` — the own list's
    keys and the person.
11. **The register's `month` / `year` name its window**, not the own list's
    start-month filter; they are folded into `from` / `to` — a `year` alone
    is that whole year (legacy's resolveWindow()), and the window's dates are
    written in full (`YYYY-MM-DD`).
12. **The final approval's re-check takes the employee's lock** before it
    reads the balance, as legacy's review() did — so two final approvals, or
    one racing an excuse or a balance cut, cannot overdraw it together.

**Reviewed** by a fresh reviewer over all three slices: no critical finding;
the two important ones (12 above, and the year-alone window) and the
unpadded window dates were fixed test-first. Deferred, not built: a cap on a
leave's span; recording an attachment's path before its row; the register's
default month in the attendance timezone; the return-to-work notification
inside its transaction; the balance update's reach checked before its body.

**§4.7 is complete.** Next in dependency order: §4.8, the `/api/me` surface.

