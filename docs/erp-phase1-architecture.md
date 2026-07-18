# ERP Phase 1 — Architecture Decision Record

> **About this file.** The phase-1 audit (`docs/phase1-audit-report.md`) cites a
> spec named `erp-phase1-architecture.md` that was never committed to the
> repository. This file now holds that name and serves as the **in-repo
> authoritative record** of phase-1 architecture decisions. It currently carries
> the sections that have been **formally ratified by the project owner**; if the
> original full document resurfaces, merge it here — where the two disagree,
> the ratified sections below win, because they record decisions made *after*
> the original was written.

---

## §7.2 Notifications — RATIFIED (2026-07-17)

### Decision

The ERP uses a **custom `notifications` table keyed by `user_id`** — **not**
Laravel's stock notifications schema (UUID primary key, polymorphic
`notifiable`, JSON `data` blob, `read_at`). Any earlier text requiring the
stock schema is superseded: the custom table is the ratified design, and the
implementation in `database/migrations/Auth/2026_06_21_150000_create_notifications_table.php`
is authoritative.

### Schema (as built)

| Column | Type | Meaning |
| --- | --- | --- |
| `id` | bigint PK | Plain auto-increment id |
| `user_id` | FK → users, cascadeOnDelete | The one recipient of this row |
| `title` | string | Display title |
| `body` | text, nullable | Display body |
| `type` | string, nullable | Machine-readable category (e.g. `employee_created`) |
| `reference_type` + `reference_id` | nullableMorphs | Optional link to the subject record (employee, department, …) for deep-linking |
| `is_read` | boolean, default false, indexed | Read state |
| `created_at` / `updated_at` | timestamps | |

### Targeting model — snapshot fan-out

Notifications must be sendable to (a) an individual user, (b) an entire
department, and (c) an entire role. **Department and role sends mean the
members at the moment of sending** — a snapshot, not a living subscription.
Someone who joins the department or gains the role later does **not**
retroactively receive an earlier notification.

That semantics is why the storage stays per-user: the schema never models a
department or role as a recipient. The expansion happens **once, at dispatch**,
in the fan-out layer — `NotificationService`:

- `sendToUser(User|int, …)` — one row.
- `sendToDepartment(Department|int, …)` — **subtree-inclusive**: the unit and
  every unit beneath it (a division send reaches its sections' members; a root
  send reaches the whole company). Resolution walks the `parent_id` tree one
  query per tier, reusing the hierarchy hardened in the org-tier batch.
- `sendToRole(Role|int, …)` — the role's current holders.

**Eligibility (uniform):** a recipient is a user account that exists with
`is_active = true`; the department path additionally requires a live
(non-soft-deleted) employee row in the subtree. Employees with no login receive
nothing. Employment `status` is *not* consulted — whether a person may use the
system is the account flag, one fact in one place. Empty audiences (no members,
trashed/unknown target) are valid and **silent**: zero rows, no exception.
Delivery is duplicate-free — a user never receives the same notification twice.

There is deliberately **no combined multi-type dispatcher** (`send(recipients:
[...])`) — the three explicit methods are the ratified surface; a combined form
may be layered on later if a real case demands it.

### Caveat — Laravel's notification system is deliberately not used

**`$user->notify()` and the stock `database` notification channel are not wired
up and must not be.** The stock channel writes to a UUID/notifiable/JSON table
that does not exist in this schema; calling `notify()` will fail at runtime.
All notification creation goes through `NotificationService`'s `sendTo*`
methods. (The `Notifiable` trait remains on `User` only as inert Laravel
boilerplate; the custom `notifications()` relation shadows it.)

### Out of scope of this section

**Physical transport** — how a stored row reaches a device (FCM, mail,
broadcast, websockets, polling cadence) — is a separate, later decision. This
schema settles only *what is stored and for whom*. The document-signing
application is a **separate system**; its transport choices imply nothing here.
