# Frontend — سجل التدقيق, backend changes of 2026-09-20

The audit log screen shipped and works. This is a **delta**, not a rebuild: a
code review of the backend found eleven defects, all are fixed, and five of them
are visible on the wire. Three of those need a change in `erp-dashboard`.

**Nothing here is a breaking change to a field you already read.** No key was
renamed, removed or re-typed. What changed is (a) which callers get the values
blobs, (b) two new shapes on `auditable_type` that used to be a 500 instead, and
(c) two request-side rules.

---

## Before you start

> ### 📌 If you only read one thing, read this
>
> **§1 now has a Timestamps section, and it corrects seventeen wrong examples.**
> Every JSON example below §1 used to show `+00:00`. Every one of them was
> wrong: the API has always returned `+03:00` (Asia/Damascus) on every endpoint,
> because every resource serialises through Carbon's `toIso8601String()`, which
> preserves the instance zone. The rule was stated only in §9.6, where it read
> as a quirk of the audit log.
>
> **A client that implemented those examples renders the entire system three
> hours early** — not just the audit screen. It is a plausible three hours: the
> dates stay right, nothing throws, an evening event just reads as afternoon.
> If any screen in the dashboard parses a timestamp as UTC or re-zones it, that
> is the bug, and it was our documentation that caused it.
>
> This was flagged from the dashboard side and hand-patched into the mirror
> copy. That patch is gone now, overwritten by the re-copy below — which is
> correct for a mirror file, and is exactly why the fix had to land upstream.
> It has. It is now also pinned by `TimestampContractTest`, which sweeps every
> `_at` key of every shipped endpoint at any depth, so an example and the wire
> cannot drift apart again without failing the build.
>
> One genuine exception, newly documented: timestamps **inside** the audit
> log's `old_values` / `new_values` are the raw stored columns —
> `"2026-09-20 11:24:23"`, no `T`, no offset, Damascus local. They are a
> verbatim copy of the audited row, not a rendered field. `auditFormat.js`
> already handles this correctly: `parseServerTimestamp` accepts the space
> separator and `toEpochMs` defaults a missing offset to Damascus. That was the
> right call and it is now the documented contract rather than a lucky guess.

**Re-copy the API doc.** `docs/API_DOCUMENTATION.md` in the backend repo is now
**1542 lines**; the copy at `erp-dashboard/API_DOCUMENTATION.md` is the stale
**1316-line** revision. §1 and §9 are both what changed.

```powershell
Copy-Item "C:\Users\M.Hawa\Desktop\work\alraqi-erp-system\docs\API_DOCUMENTATION.md" `
          "C:\Users\M.Hawa\Desktop\work\erp-dashboard\API_DOCUMENTATION.md" -Force
```

Check §9.2 contains the heading text "Two permissions are needed for the values,
not one" before reading further. If it does not, you have the old copy.

---

## Part 1 — what changed on the wire

### 1.1 The values blobs need a second permission ⚠️ *this one changes behaviour you can see*

`old_values` / `new_values` used to appear for anyone holding
`auth.audit.viewValues`. They now **also** require the view permission of the
module the row is about:

| `auditable_type` | also requires |
| --- | --- |
| `employee` | `hr.employees.view` |
| `employee_assignment` | `hr.assignments.view` |
| `employee_document` | `hr.employees.view` |
| `employee_warning` | `hr.warnings.view` |
| `job_title` | `hr.job_titles.view` |
| `department` | `departments.view` |
| `user` | `auth.users.view` |
| `role` | `auth.roles.view` |
| `permission` | `auth.roles.view` |

A row with **no target** (`user_logged_in`, `api_request`, the rest of the auth
vocabulary) needs only `auth.audit.viewValues`, because its blobs hold request
and auth metadata rather than a copy of someone's record.

**Why**: `HasAuditLog` copies the whole model into `new_values`. Without this,
`auth.audit.view` + `viewValues` and nothing else could page
`?event=created,updated,deleted&auditable_type=employee` and rebuild the entire
employees table — and departments, job_titles, users and roles the same way —
with no HR or Auth read permission ever granted. An audit row is a copy of
someone else's record, and permission to read audit rows is not permission to
read that record.

**The shape is unchanged**: the keys are still **absent**, never `null`, when
you may not read them. Absent still means "you may not read this" and `null`
still means "there is nothing to read" (`created` has no old values).

**What this means for the UI**: the answer is now **per row, not per session**.
The same signed-in user can get the values on a login row and not on the
employee row directly beneath it. Keep deciding from `hasOwnProperty` on the
payload — which is what `AuditDiff.jsx` already does correctly — and never cache
a "may I see values" boolean.

### 1.2 `auditable_type` can now be `null` with a non-null `auditable_id`

A row written before the morph map was enforced (2026-09-08) whose model class
has since been renamed or removed. There is no honest public name left for it
and a PHP class name must never reach you, so the type arrives as `null` while
`auditable_id` is still set.

```json
{ "auditable_type": null, "auditable_label": null, "auditable_id": 11, "auditable": null }
```

This is **not** "no target". An action-level row has `auditable_id: null` too;
that is the one to render as a dash.

### 1.3 A retired morph alias arrives as itself

A type whose model has been retired keeps its alias, because the alias is a
public name, it leaks nothing, and it is the one fact the broken row still
carries:

```json
{ "auditable_type": "contract", "auditable_label": null, "auditable_id": 12, "auditable": null }
```

Render the raw key, unlinked. This is the case `AUDITABLE_META`'s fallback was
always written for — it now actually occurs.

> Both 1.2 and 1.3 **used to be a 500 on the whole page**, not a row you could
> render: eager-loading the target instantiated one class per distinct type on
> the page, and a type with no live class behind it raised an uncatchable PHP
> Error. Because the table is append-only the offending row could never be
> corrected, so any page containing it would have been broken forever. If you
> ever saw an unexplained 500 from this endpoint, that was it.

### 1.4 New filter: `actor_type`

| Param | Values | Meaning |
| --- | --- | --- |
| `actor_type` | `user` \| `system` | `system` selects rows with no authenticated actor — a seeder, console command or queued job |

Composes with `user_id` rather than replacing it. An unknown value is a **422**
with `errors.actor_type` and the message
`نوع المنفّذ المحدد غير معرّف. القيم المتاحة: user أو system.`

**Why it was added**: the payload has always published three distinct actor
cases and the list renders all three, but `user_id` matches an id and no value
of it expresses "no actor". "What happened outside any user session" — the case
where nobody is accountable — was the only one of the three you could not ask
for, and reaching it meant paging the whole unbounded table.

### 1.5 An empty `event` / `event_group` is now a 422

`?event=`, `?event=,,,` and `?event_group=` used to be accepted and then
silently ignored — a full page of unfiltered rows, 200, with no way to tell the
filter had been discarded. On `event_group` that was worse than generic: it is
the parameter that hides the `api_request` firehose, so asking for real changes
silently returned the firehose.

They are now a **422** with `errors.event` / `errors.event_group`, message
`قائمة الأحداث فارغة. احذف المُرشّح بدلاً من إرساله فارغاً.`

**You almost certainly do not need to do anything for this** — see Part 3.

### 1.6 `search` now treats wildcards as literal characters

`%`, `_`, `\` and `!` in the search term are escaped correctly. Previously
`?search=%` matched every row on MySQL, and a term containing a backslash
corrupted the escaping around it. `?search=%` now returns only rows whose
`description` contains a percent sign.

No request-side change. Mentioned because a tester who learned the old
behaviour will see different results.

---

## Part 2 — what to change

### 2.1 Fix the lock message in `src/sections/system/AuditDiff.jsx` — **required**

Lines 144–157 currently tell the reader they need `auth.audit.viewValues`. After
1.1, a caller who **holds** viewValues but lacks the target module's read
permission lands in exactly that branch and is told to obtain a permission they
already have. On the one screen whose entire job is telling people the truth
about who can see what, that is the worst possible copy.

The detection is already correct — `canSeeValues` reads `hasKey(detail, …)` off
the payload, per row. Only the message is wrong. Name both conditions, and be
specific where you can: the row carries `auditable_label`, so
«يتطلب أيضاً صلاحية عرض الموظفين» reads far better than a generic sentence. For
a row with no target, the old message is still exactly right.

### 2.2 Add `employee_document` to `AUDITABLE_META` — **required**

`src/data/auditEvents.js:160` has eight keys. The server has nine.
`EmployeeDocument` uses `HasAuditLog`, so those rows exist.

```js
employee_document: { label: "وثيقة موظف", route: null },
```

`route: null` for the same reason as `employee_assignment` and
`employee_warning`: documents live inside an employee profile tab and the audit
row carries only the document's own id, so there is nowhere truthful to send the
reader.

**Consequences today**: وثيقة موظف is missing from the type filter
(`AUDITABLE_OPTIONS` maps over `AUDITABLE_TYPES`, which is derived from this
map), and `isKnownAuditableType("employee_document")` is `false`. Labels
survive, because `auditableTypeLabel` falls through to the server's
`auditable_label` — that fallback is what has been covering for this.

This is not a new server value; `?auditable_type=employee_document` has always
been accepted. The key was simply never added here, inherited from a parameter
table in the original work order that omitted it. That table is now corrected,
and the backend has a test (`AuditTargetStubTest`) that fails the build if the
server's own two maps drift from the morph map. **Nothing guards this copy**, so
it is worth a line in `auditFormat.test.js` asserting all nine keys.

### 2.3 Add the `actor_type` filter — **recommended**

Three touch points:

1. `src/redux/actions/auditParams.js` — forward it in `buildListParams`,
   alongside `user_id`, using the same omit-when-empty rule as everything else.
   Drop any value outside `["user", "system"]` rather than forwarding it, the
   way `sort` is already handled.
2. `src/sections/system/AuditLogFilters.jsx` — a three-state control
   (الكل / مستخدم / النظام) beside the user picker.
3. The URL sync, so a finding stays shareable as a link — which is most of what
   this screen is for.

`auditColumns.jsx:104-134` already renders the three actor cases distinctly, so
the filter and the rendering will agree with no further work.

### 2.4 `auditable_type: null` rendering — **optional polish**

`TargetCell` (`auditColumns.jsx:176`) already survives 1.2: its guard needs
**both** `auditable_type` falsy *and* `auditable_id == null` before it returns a
dash, which is correct. But `auditableTypeLabel` bottoms out at `"—"`, so the
row renders «— #11 / غير متاح». «سجل غير معروف #11» would read better. Purely
cosmetic; nothing is wrong.

---

## Part 3 — what NOT to change

**`buildListParams` is already correct for 1.5. Do not "fix" it.** It omits
every empty value, and `csvParam` returns `""` for an all-empty list, which then
fails the `if`. A URL seeded with `?event=` produces no `event` parameter. Every
request on the screen goes through that one builder, `AuditTrail.jsx` included,
so there is no second path that could send an empty filter. It was written that
way from the start and is the reason 1.5 is safe to ship.

**The sort handling is unchanged.** `created_at` / `-created_at` are still the
only accepted values and anything else is still a 422. The backend fixed a
direction bug in its own fallback, but no HTTP caller could reach it.

**No change for**: the pagination envelope, `event_label` / `event_group` null
semantics, the `+03:00` offset rule, inclusive `date_to`, the three actor cases,
`***REDACTED***`, `changed_fields`, the append-only rule, or the no-polling
rule. All still exactly as documented.

---

## Part 4 — verify

- A caller with `auth.audit.view` + `auth.audit.viewValues` and **no**
  `hr.employees.view`: an employee row's values are absent and a `user_logged_in`
  row's values are present, **in the same session**. The panel must decide per
  row, and the message on the blocked row must not tell them to get viewValues.
- The same caller after granting `hr.employees.view`: the employee row's values
  appear, and a `user` row's values are still absent.
- A row with `auditable_type: null` and `auditable_id: 11` — renders, does not
  crash, is not shown as "no target".
- A row with an unrecognised `auditable_type` — raw key, no link, no crash.
- `?actor_type=system` returns only system rows; `?actor_type=user` only
  user rows; the filter survives a URL copy-paste.
- Clear every filter box, then refetch: no 422. (This is the 1.5 regression
  check.)
- Filter by وثيقة موظف and get a page rather than an empty dropdown.
- `search` with `%`, with `_`, and with a Windows path containing `\`: each
  returns rows that literally contain that text, not everything and not nothing.

---

## Appendix — fixed on the backend with no frontend impact

Listed so nobody goes looking for a UI change that does not exist.

| Fix | Why it does not reach you |
| --- | --- |
| Sort fallback took the wrong direction for an unresolved key | Only reachable from a console command; HTTP callers are 422'd first |
| `event` filter threw a TypeError on a scalar | Same — the HTTP layer always sends an array |
| The `auditable_type` filter allow-list now reads the morph map instead of a hand-maintained label map | Same nine values today; the change is that it will track a newly audited model automatically instead of 422-ing its rows |
| `(event, created_at)` and `ip_address` indexes added; the redundant `event` index dropped | The default `event_group=model,auth` screen was reading ~1500 rows per 15-row page. Pure latency |
| Permission-cache flush moved from the seeder onto the `Permission` model | Stale permission sets after a catalogue change from any door but the seeder |
| LIKE escaping extracted to one shared helper, used by employees and job titles too | See 1.6; the HR search screens get the same correctness |
