# Attendance — the build design (work order §4.5)

> **Written 2026-10-16**, after the decision round closed. The rules are
> legacy's (`../Raqi-App`: `AttendanceService`, `AttendanceController`,
> `AttendanceRecord`, `config/attendance.php`, the scheduled commands), with
> the agreed differences in the work order's §8 entry "the decision round,
> answered". The column-by-column port is
> [`LEGACY_TO_ERP_MAPPING.md` §4](LEGACY_TO_ERP_MAPPING.md). This file says how
> the module is built: its pieces, the order, and the choices made while
> designing it.

---

## 1. Three slices, each green on its own

| Slice | What | Legacy tests ported |
|---|---|---|
| **1. Recording** | The table, the enums, `config/attendance.php`, the calendar, check-in / check-out with every legacy rule, offline sync with `client_uuid`, selfies stored privately; `/api/me/attendance` (record, sync, my records) | `AttendanceCheckInOutTest`, `AttendanceSyncErrorLeakageTest`, the calendar half of `WorkingCalendarTest` |
| **2. Oversight** | Records list, daily / monthly / per-employee reports, absent today, correct a check-out, reject and un-reject — all row-scoped by GAP-3; the selfie endpoint; the permissions | `AttendanceCheckoutAuthorizationTest`, `AttendanceEmployeeBreakdownTest`, `AttendanceFilterAndReportTest`, `AttendanceMonthlyReportTest`, `AttendanceQueryControlsTest`, `AttendanceRejectionTest` |
| **3. Schedules** | `attendance:checkout-reminder` (17:00), `attendance:auto-close` (23:59), `attendance:purge-selfies` (three months, new) | `AttendanceScheduleCommandsTest` + the purge's own |

Not ported: `AttendanceExportTest` (XLSX is a dashboard feature, later),
`attendance:cleanup` (D7), `attendance:seed-demo` (a dev tool).

The leave half of `WorkingCalendarTest` (charging, working through leave) and
the monthly report's leave days port with the Leave module (§3 below).

---

## 2. Choices made while designing

**Who may record** (Q7). The caller's account must be linked to an employee
whose status is **active**. An account with no employee (the technical
department, `super_admin`) gets **403** — it is not a person who attends. A
suspended employee is refused with **422**: Q7 says *active employees*, and a
suspension is not active. *(This reading is listed for the project owner in the
handoff.)* There is no permission and no flag: the route is self-scoped, like
the rest of `/api/me`.

**One row per event**, as legacy: a check-in row, a check-out row. The day's
state is **derived**, never stored — except `missing_checkout`, which the
auto-close job writes on the orphan check-in, exactly as legacy does. So
`status` is a nullable tinyint holding only that one value; the day states
(present, absent, on leave, missing check-out) are a separate enum the reports
compute in slice 2.

**The lock.** Legacy serialises one person's writes by locking their `users`
row, because "one check-in a day" and "check-out needs a check-in" are
read-then-write. The ERP locks the **`employees`** row: the rules are about the
person, and an account is optional.

**Idempotency** (Q12, D9). `client_uuid` is optional on a single record and on
each sync entry. With one, the service looks for `(employee_id, client_uuid)`
**inside the lock, before storing the selfie**: found, it returns that record
with success and stores nothing — whatever the retry carries, since the decision
is "return the saved record". The unique index is the backstop if two requests
race past the lock on different connections. Without a `client_uuid` the
behaviour is legacy's: a second check-in is the "already checked in" error.
**A refused (rejected) record found by its id is still returned** — the retry is
the same operation, and the refusal is HR's answer to it.

**Selfies** (Q11). The `local` disk (`storage/app/private`), at
`attendance/selfies/{employee_id}/{Y-m-d}/{uuid}.{ext}`, the extension read from
the image content as legacy does. Never a public URL: the resource returns
`has_selfie`, and slice 2 adds the authorised endpoint that streams it. A
rejected event deletes its stored selfie, as legacy does.

**The calendar** is `Support\AttendanceCalendar`: timezone, working days (all
seven, with legacy's fallback when the config is broken), the check-in window
(empty = open, as shipped), `isChargeableDay()` — **kept as the one hook a
holiday calendar would use later** (D5 deferred) — and `workingDaysBetween()`,
which the Leave module will read. The closing time is **23:59 in both the config
and the code fallback** (Q10).

**Leave, which is built after this** (dependency order). Attendance needs to
know about approved leave in two places: the check-in guard (Q10 rule 4) and the
reports. It does not import the Leave module. It owns an interface,
`Contracts\ApprovedLeave`, bound by default to `NoApprovedLeave` (nobody is on
leave). The Leave module, when built, binds its own implementation. So the
dependency runs **Leave → Attendance** only: leave reads attendance's calendar,
attendance reads leave through a contract it defines. The guard is tested now
against a fake binding.

**Errors.** Business-rule refusals are `AttendanceException` (422, Arabic, the
legacy messages). In sync, an `AttendanceException` reports its message for that
entry; anything else is logged with context and reported generically — legacy's
fix for SQL and path leaks, kept, with its tests.

**Audit.** `AttendanceRecord` uses `HasAuditLog` (C13): every check-in,
correction and refusal is in the trail. About 500 rows a day company-wide.

**Envelope.** The ERP envelope replaces legacy's bare bodies — the app is
migrated to it anyway (§4.10). Sync answers `data: {succeeded: [...], failed:
[...]}`, each failure carrying its index and message.

---

## 3. Endpoints, by slice

**Slice 1 — self-service (`app/Modules/Me/routes.php`, self-scoped):**

| Method & path | Legacy | |
|---|---|---|
| `POST /api/me/attendance` | `POST /attendance/record` | 201 created · 200 when the `client_uuid` was already saved |
| `POST /api/me/attendance/sync` | `POST /attendance/sync` | 200, `succeeded` / `failed` |
| `GET /api/me/attendance` | `GET /attendance/my-records` | Paginated; legacy's filters and sorts, allow-listed |

**Slice 2 — oversight (`app/Modules/Attendance/routes.php`):** records, report,
monthly report, employee report, absent today, correct check-out, reject,
un-reject, selfie — with the permission names from mapping §9, scoped.

---

## 4. For the project owner

- **A future `recorded_at` is accepted.** Legacy validates the time's format and
  nothing else, so a phone with a wrong clock can check in for tomorrow. Kept as
  legacy (no change without agreement).
- **A suspended employee cannot record** (422), but reads their history — the
  reading of Q7's "active employees" in §2.

---

## 5. Status

- ✅ **Slice 1 — recording — built 2026-10-16** (work order §8). One change
  from §2 above: `attendance.records.view` is seeded **now**, not in slice 2,
  because the audit trail must gate every audited type (`AuditTargetStubTest`);
  it is company-only until slice 2 enforces its row scope.
- Slice 2 — oversight — next.
- Slice 3 — schedules.
