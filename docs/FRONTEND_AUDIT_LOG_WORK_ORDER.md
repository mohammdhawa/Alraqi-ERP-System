# Frontend — Audit Log (سجل التدقيق): the prompt

> ✅ **The backend shipped on 2026-09-17. The blocker below is closed.**
>
> `GET /api/auth/audit-logs` and `GET /api/auth/audit-logs/{id}` exist, guarded
> by `auth.audit.view`; `auth.audit.view` and `auth.audit.viewValues` are seeded
> (the catalogue is now 30 rows, up from 28). **`API_DOCUMENTATION.md` §9 is now
> the contract and supersedes the proposal block below** — re-copy that file to
> the dashboard repo before sending the prompt, per §What to give the agent.
>
> **Three amendments the backend made to the proposal. All are already applied
> to the parameter table and the prompt body below — read them, do not re-apply
> them:**
>
> 1. **`auditable_type` uses the full morph aliases** — `employee_assignment`
>    and `employee_warning`, **not** the proposed `assignment` and `warning`.
>    Those are the strings the enforced morph map stores in the column and
>    already publishes as `reference.type` on notifications; a second spelling
>    would mean one type with two public names. `employee_document` exists too
>    and the original table omitted it. The AUDITABLE_META spec in §2 of the
>    prompt now carries all nine keys verbatim.
>
> 2. **`event_label` and `event_group` are `null` for an unrecognised event**,
>    rather than absent or guessed. The prompt's fallback behaviour is already
>    right for this; no change needed, but the shape is now explicit.
>
> 3. **`actor_type` is a filter, not only a response field** (added 2026-09-20).
>    `?actor_type=system` is how you ask for what ran with nobody signed in —
>    `user_id` matches an id and no value of it expresses "no actor". See §6 of
>    the prompt.
>
> Everything else in the contract was ratified as proposed, including the
> absent-vs-null rule on the values blobs, the list item's key set, the
> `-created_at`-only sort, and the inclusive `date_to`.
>
> The rest of the prompt — the table columns, the event vocabulary, the
> redaction behaviour, the volume problem — was read off the shipped code and is
> still true.

---

## What to give the agent

**One file: the current `docs/API_DOCUMENTATION.md` from this repo** (1296
lines). It must replace `erp-dashboard/API_DOCUMENTATION.md`, which is **a stale
1236-line copy** — the Batch 2-era revision, missing the entire "Embedded
objects" section of §1 and still saying `/auth/users` "returns the full
collection". The missing section is the one that states *absent key ≠ null*,
which is the rule the whole values-gating and actor-rendering design rests on.

```powershell
Copy-Item "C:\Users\M.Hawa\Desktop\work\alraqi-erp-system\docs\API_DOCUMENTATION.md" `
          "C:\Users\M.Hawa\Desktop\work\erp-dashboard\API_DOCUMENTATION.md" -Force
```

**Nothing else.** Do not hand it `AuditLogService.php`, the migration or the
middleware — the prompt already carries every fact it needs from them (the full
event vocabulary, the redaction behaviour, the column shapes), and an agent
reading the backend source will code against implementation details instead of
the contract. It reads the dashboard's own code for conventions by itself.

Re-copy this file again once the backend ships the audit section into
`API_DOCUMENTATION.md`; at that point the document supersedes the contract
block below.

---

## The contract to ratify (hand this to the backend too)

**Permissions** (new; the catalogue grows from 28 to 29 or 30)

| Name | Arabic label | Kind |
| --- | --- | --- |
| `auth.audit.view` | عرض سجل التدقيق | route-level |
| `auth.audit.viewValues` | عرض تفاصيل التغييرات | field-level — gates `old_values`/`new_values` (recommended, see §Flagged 2) |

**Endpoints**, mounted in the Auth module beside users and roles:

```
GET /api/auth/audit-logs          → paginated list   (auth.audit.view)
GET /api/auth/audit-logs/{id}     → one row, in full (auth.audit.view)
```

**Query parameters** — `page`, `per_page` (default 15, clamped 1–100, per §1 of
the API doc), plus:

| Param | Type | Notes |
| --- | --- | --- |
| `user_id` | int | the actor |
| `actor_type` | `user` \| `system` | `system` is a seeder, console command or queued job. Composes with `user_id` |
| `event` | CSV of event keys | `created,updated,deleted`. **Omit the parameter rather than sending it empty** — `?event=` is a 422, deliberately, so a discarded filter can never look like an answer |
| `event_group` | CSV of `model` \| `auth` \| `request` | `request` is the `api_request` firehose (see §Flagged 3). Same empty-value rule as `event` |
| `auditable_type` | short key | `employee` · `employee_assignment` · `employee_document` · `employee_warning` · `job_title` · `department` · `user` · `role` · `permission`. **Never the PHP FQN** — a class name does not belong in a URL |
| `auditable_id` | int | requires `auditable_type`; 422 without it |
| `request_id` | string (≤64) | the correlation drill-down; the column is indexed, so this is cheap |
| `ip_address` | string | |
| `date_from` / `date_to` | `YYYY-MM-DD` | inclusive **to the end of** `date_to`'s day, in Asia/Damascus |
| `search` | string | matches `description` |
| `sort` | `created_at` \| `-created_at` | default `-created_at`. **Nothing else is sortable** — only `created_at` is indexed and this table is the one that grows without bound |

**List item** (deliberately does not carry the values blobs — a `deleted`
employee row holds ~50 keys, and 100 of those per page is a fat response):

```json
{
  "id": 88412,
  "event": "updated",
  "event_label": "تعديل",
  "event_group": "model",
  "description": null,
  "actor_type": "user",
  "user_id": 7,
  "user": { "id": 7, "name": "سامر خليل", "email": "samer@…" },
  "auditable_type": "employee",
  "auditable_label": "موظف",
  "auditable_id": 42,
  "auditable": { "id": 42, "label": "أحمد خالد الحمصي (EMP-00042)" },
  "changed_fields": ["department_id", "organizational_grade"],
  "ip_address": "10.0.0.14",
  "request_id": "8f3c…",
  "created_at": "2026-09-16T14:03:11+03:00"
}
```

**Detail** adds `old_values`, `new_values` (objects, or `null` — `created` has no
old, `deleted` has no new) and `user_agent`.

Three shape rules the frontend depends on, all of them already house rules:

- `created_at` is **ISO-8601 carrying the `+03:00` offset**. The app timezone is
  `Asia/Damascus` and `now()` writes local time; serialising it without an
  offset would move every event three hours the moment a browser parses it.
- `user: null` with `actor_type: "system"` means a seeder / console command /
  queued job. `user: null` with `actor_type: "user"` means the account is gone.
  These are different sentences and the UI says so.
- An **absent** `old_values`/`new_values` key means "you may not read this",
  never "there is nothing here" (API doc §1, embedded objects).

---

## The prompt

```text
Act as a senior React frontend engineer working directly in:
C:\Users\M.Hawa\Desktop\work\erp-dashboard

Build سجل التدقيق — the audit log: a screen for reading who changed what, when, and from where, plus a per-record change history embedded in the employee profile. Implement it in the repository — do not merely describe it, and do not paste whole files or a large diff into chat.

## Authoritative contract

API_DOCUMENTATION.md at the repo root for the conventions — §1 Pagination, the standard envelope, the "Embedded objects" rule, and §2 RBAC. Confirm it is the 1296-line revision and that §1 contains a heading "Embedded objects (an FK and the thing it points at)" before reading further; if it is the older 1236-line file with no such section, STOP and tell me, because that copy is stale and does not state the rule this whole screen turns on — that an ABSENT key means "you may not read this" while a `null` means "there is nothing to name". The audit endpoints themselves are specified below and are NOT yet in that document; when they land there, the document wins. Never invent an endpoint, query parameter, response field or permission beyond what is written here.

GET /api/auth/audit-logs       — paginated list   (permission auth.audit.view)
GET /api/auth/audit-logs/{id}  — one row in full  (permission auth.audit.view)

List params: page, per_page, user_id, actor_type (user|system), event (CSV), event_group (CSV of model|auth|request), auditable_type (short key, never a PHP class name), auditable_id (requires auditable_type), request_id, ip_address, date_from, date_to (YYYY-MM-DD, inclusive of the whole end day, Asia/Damascus), search (matches description), sort (created_at or -created_at ONLY, default -created_at). Sending any other sort key is a 422, so never send one — only created_at is indexed, and this is the one table in the system that grows without bound. OMIT A PARAMETER YOU ARE NOT FILTERING ON; do not send it empty. `?event=` and `?event_group=` are 422s on purpose, because a filter that is silently discarded returns a correct-looking page answering a different question.

List item: {id, event, event_label, event_group, description, actor_type, user_id, user:{id,name,email}|null, auditable_type, auditable_label, auditable_id, auditable:{id,label}|null, changed_fields:[...], ip_address, request_id, created_at}. The detail endpoint adds old_values, new_values and user_agent.

IF THE ENDPOINT 404s when you try it, the backend has not deployed it yet. Keep building against this contract — it is fixed — but say so plainly in your checkpoint. Do not fabricate a mock server, do not commit fixture data as if it were live, and do not soften the contract to match something that does exist.

## Ground rules

- Inspect the current implementation before editing. This dashboard already shipped Batch 2; follow what is there rather than building a parallel style beside it.
- Stay with the stack: React 18, Vite, the Redux action/reducer conventions in src/redux (see jobTitlesActions.jsx + jobTitleReducer.jsx — the requestId stale-response guard, the separate list/save error fields), Axios, React Bootstrap, react-select, SweetAlert2, react-toastify. Do not add a dependency unless it is genuinely necessary and you explain why first. In particular do NOT add a diffing library; the diff here is a three-column table, not a text diff.
- Reuse the shell: PageHeader, DataTableServer, TablePaginationServer, Loader, ProtectedElement/ProtectedRoute, utils/toast, utils/apiHelpers (parseApiError). Register the new slice in src/redux/Store.jsx.
- Keep Arabic/RTL and the dashboard's visual language. Show the server's Arabic messages. Read this document as UTF-8; do not copy mojibake from a mis-decoded terminal.
- Treat every value in an audit row as untrusted text — it is verbatim user input, replayed. Render as plain text; never dangerouslySetInnerHTML, never eval a JSON string into markup.
- Handle loading, empty, error, retry and permission-denied states. Guard against stale responses on a fast filter change.
- There is no test runner beyond `npm test` over src/**/*.test.js. Adding a pure-function test beside the existing departmentPayload.test.js is welcome; a new runner is not. Run `npm run build` and `npm run lint`, and separate new failures from pre-existing ones.

## The two things that make this screen different from every other list

**1. It is append-only. There is no edit, no delete, no restore, and no permission that could authorize one.** Render zero mutation affordances — that includes passing showActions={false} to DataTableServer, which otherwise renders an actions column by default. A destructive-looking button on an audit trail is worse than useless; it advertises a power that does not exist and invites someone to try.

**2. Reading it writes to it.** AuditLogMiddleware records every authenticated request, so every refresh of this page appends a row to the table it is showing. Therefore: NO polling, NO auto-refresh interval, no refetch-on-window-focus. One explicit "تحديث" button, and nothing else fetches on its own.

## Build these, in this order

**1. Permission, route, navigation.**
Add `auth.audit.view` (عرض سجل التدقيق) to src/data/permissionsCatalog.js under the existing إدارة النظام grouping, keeping the hasPermission convention. Also add `auth.audit.viewValues` (عرض تفاصيل التغييرات) marked `fieldLevel: true` alongside hr.employees.viewSalary/viewIdentity — it gates keys inside the payload, not a route. Note that viewValues is NECESSARY BUT NOT SUFFICIENT: the server also requires the view permission of the module the row is about (hr.employees.view for an employee row, auth.users.view for a user row, and so on), because an audit row is a copy of someone else's record. So old_values/new_values can be absent on one row and present on the next for the same caller; render that per row from what the payload actually contains, never from a cached "may I see values" boolean. Route `/audit-log` gated on auth.audit.view, sidebar entry under إدارة النظام beside المستخدمين and الأدوار والصلاحيات. Never call a /permissions endpoint; none exists.

**2. src/data/auditEvents.js — one module, two maps, both with honest fallbacks.**
EVENT_META: key → {group, variant} for the badge colour and grouping. The full vocabulary the backend actually emits today, and there are no others:
model group — created, updated, deleted.
auth group — user_logged_in, user_logged_out, tokens_refreshed, all_tokens_revoked, refresh_token_replay_detected, user_created, user_updated, user_deleted, role_created, role_updated, role_deleted, role_assigned, role_unassigned.
request group — api_request.
Destructive events (deleted, user_deleted, role_deleted, role_unassigned, all_tokens_revoked) get the danger variant. `refresh_token_replay_detected` is a SECURITY SIGNAL, not a routine event — a rotated refresh token was presented twice, which is either a bug or a stolen token — so give it a distinct, louder treatment than plain danger and a one-line Arabic explanation in the detail panel. api_request is muted.
Arabic labels come from the server's `event_label`. Keep a local label map ONLY as a fallback for when that key is missing, and for an event key you do not recognise render the raw key rather than inventing a label — a new backend event must look unfamiliar, not look like something else.
AUDITABLE_META: short key → {label, linkTo}. There are exactly nine keys and they are the morph aliases the server stores — employee, employee_assignment, employee_document, employee_warning, job_title, department, user, role, permission. Do not shorten employee_assignment to assignment or employee_warning to warning; those strings are not what the column holds, so the labels would never render and ?auditable_type=assignment is a 422. employee موظف links to /hr/employees/{id} — that is the only audit target with a real detail route. department وحدة تنظيمية and user مستخدم and role دور and permission صلاحية link to their LIST screens (/departments, /users, /roles, /roles) because no detail route exists. job_title مسمى وظيفي links to /hr/job-titles. employee_assignment سجل تعيين, employee_warning إنذار and employee_document وثيقة موظف get NO link at all: they live inside an employee profile tab and the audit row does not carry the employee id, so there is nowhere truthful to send the user. Do not guess a URL; an unrecognised auditable_type renders as its raw key with no link.

**3. Redux pair.**
src/redux/actions/auditActions.jsx + src/redux/redurcers/auditReducer.jsx, registered in Store.jsx. Mirror the job-titles pair: a monotonic requestId so a slow page-1 response cannot overwrite a fast page-2 one, `items`/`meta`/`fetchLoading`/`error` for the list, and a separate `detail`/`detailLoading`/`detailError` for the single row — a failed detail fetch must not blank the table behind the panel. Build list params in one buildListParams function that omits empty values rather than sending blanks, drops any sort outside the two legal keys, and CSV-joins event and event_group. NEVER use fetchAllPages against audit-logs — it exists for bounded picker sets, and this table has no bound.

**4. The list — src/sections/system/AuditLog.jsx + AuditLogFilters.jsx.**
Columns: التوقيت (absolute Damascus time, with a quieter relative "منذ ٣ دقائق" beneath it); المستخدم; الحدث (badge from event_label); السجل المتأثر (auditable.label, linked per AUDITABLE_META; when `auditable` is null render `{auditable_label} #{auditable_id}` unlinked, because the record is gone or unresolvable); التغييرات (first three changed_fields as chips plus "+N"); عنوان IP. Label the timestamp column التوقيت (بتوقيت دمشق) once, in the header, so nobody misreads a time. Render created_at from the offset the server sent; do not re-zone it and do not strip the offset before parsing.
Actor rendering, three distinct cases, three distinct strings: a user → their name; actor_type "system" → النظام with a tooltip saying it was a seeder, console command or background job; actor_type "user" with user null → مستخدم محذوف #{user_id}, which is the truth and is exactly the case an audit is most often opened to answer.
Filters, all server-side, URL-synced (?user_id=&event=&date_from=… ) so a finding can be sent to someone as a link — that is most of what this screen is for. Changing any filter resets to page 1. Read meta.per_page back rather than assuming your request was honoured. Do not filter the loaded page in the browser and call it a filter. Offer actor_type as a three-state control (الكل / مستخدم / النظام) next to the user picker: the three actor cases above are rendered distinctly, and until 2026-09-20 the system one was the only one that could not be asked for.
THE api_request DEFAULT: default the list to event_group=model,auth — every authenticated request writes an api_request row, so including them buries every real change under routine traffic at roughly a hundred to one. But a filter that hides data invisibly is not acceptable on an audit tool: render a always-visible chip saying "مخفي: طلبات الواجهة البرمجية" with a switch to include them, so the default is stated on screen rather than hidden in code.
The user_id filter uses a picker over /auth/users gated on auth.users.view; where the caller lacks that permission, fall back to a plain numeric id input rather than inventing a lookup.
Distinguish the two empty states: "لا توجد نتائج مطابقة" with a clear-filters action, versus a genuinely empty trail.

**5. The detail panel — AuditLogDetail.jsx + AuditDiff.jsx.**
Row click opens the full row (GET /audit-logs/{id}) in an offcanvas or modal — choose one, make it correct under RTL, and say which you chose and why. Header: the event badge, the timestamp, the actor, the target with its link. A metadata block: IP, user agent (truncated to one line with a copy button — a raw UA string will destroy the layout), request_id with a copy button, and the description verbatim when present.
AuditDiff renders old_values/new_values as a three-column table — الحقل · القيمة السابقة · القيمة الجديدة — over the UNION of both key sets, with a clear marker for a key present in only one side. `created` has old_values null: render "سجل جديد" and the new values alone. `deleted` has new_values null: render the last known state. Unchanged keys should not appear at all for an `updated` event (the backend only writes changed keys, so this is mostly free — do not re-add them).
FOUR value-rendering rules, and each one matters:
- "***REDACTED***" is not a value. Render it as a محجوب chip with a tooltip: the field was redacted before storage and the real value was never written to this table. Rendering it as text makes it look like someone literally typed that.
- Raw enum ints. The audit row stores `status: 1`, with no `_label` — this table predates the label convention and cannot be retrofitted. This is the ONE sanctioned place to use src/data/hrEnums.js for display, and only under this condition: always show the raw value beside the label, as `نشط (1)`. The ground truth stays on screen, so a stale map degrades into a redundant label rather than into a lie. Put that reasoning in a comment; the standing rule everywhere else is still "render the server's label".
- Raw FK ids. `department_id: 3 → 7` stays as ids — do not resolve them with extra requests, and do not leave them dangling either: link an id to its record where AUDITABLE_META has a route.
- null, true/false, empty string and a nested object are four different things and must look like four different things. "—" for all of them is a lie; use explicit, distinguishable renderings (فارغ / نعم / لا / a formatted JSON block).

**6. The correlation drill-down.**
The detail panel gets "عرض كل ما جرى في هذه العملية" when request_id is present: it refilters the list by that request_id. This is the feature the column was added for — one user action writes the api_request row plus one model row per touched model, and request_id is the only thing tying them together. When that filter is active it must be loud and trivially reversible: a prominent chip naming the request with an × that restores the previous filters, and the api_request rows INCLUDED in this mode (the request row is the point). The column is indexed, so this query is cheap.

**7. Per-record history — AuditTrail.jsx.**
A component taking {auditableType, auditableId} that renders the same list, filtered, paginated, newest first, with the target column dropped (it is constant). Mount it as a fourth tab on src/sections/hr/EmployeeProfile.jsx — سجل التغييرات — gated on auth.audit.view and following the tab convention already there: a user without the permission sees the profile without the tab, not an error. This is the higher-value half of the feature for day-to-day HR work: "what happened to this employee's record and who did it" is asked far more often than "what happened at 14:03".

## Out of scope — do not build

- Export / CSV / print. There is no export endpoint, and a client-side CSV of the 15 rows currently on screen would be mistaken for a full export. If the user asks for one, it is a backend change first.
- Retention, archival, purge or any "clear log" UI. The table is append-only by design and in production the DB user should hold INSERT and SELECT only.
- Charts, activity heatmaps, dashboards, anomaly scoring. This is a reading tool.
- Real-time streaming / websockets / polling — see the second rule above.
- Any change to departments, auth, roles, notifications or HR beyond adding the one employee-profile tab.

## How to work and report

Do the seven items in order. After each, run `npm run build` and give me a two-or-three-line checkpoint — what you changed and whether it built — then continue without waiting for me. If an item is genuinely blocked, finish every other item and say exactly what you left out and why.

Verify before you finish: the screen as a caller WITHOUT auth.audit.view (denied cleanly, no blank page); WITHOUT auth.audit.viewValues (values keys absent — the diff must say so, not render an empty table); WITH auth.audit.viewValues but WITHOUT hr.employees.view, where an employee row's values are absent and a login row's are present in the same session, so the panel must decide per row; a created row, an updated row touching one field, and a deleted row; a row containing ***REDACTED***; a row with actor_type system and a row with a deleted user; a row whose auditable is soft-deleted (unlinked, correct label); an unknown event key and an unknown auditable_type (raw, unlinked, no crash); a request_id drill-down returning three or more rows including the api_request one; the api_request toggle in both positions with the chip visible; date_from equal to date_to returning that whole day including its last hour; page=99 returning an empty page with correct meta rather than an error; a filter set survived through a URL copy-paste into a new tab; the employee profile tab with and without the permission; and the whole screen at ~400px width in RTL.

Finish with a concise report: changed files, behaviour completed, checks run, real blockers. No file contents, no full diff. Under 300 words.
```

---

## Flagged to the backend — one blocker, three findings

*Status as of 2026-09-17: 1 is closed, 2 is narrowed and recorded as GAP-13, 3
is answered as a design decision, 4 is still open and unfixed.*

1. ✅ **CLOSED — the endpoint now exists.** `AuditLog` (model, in `app/Shared/`),
   `AuditTrailService` (the reader; `AuditLogService` remains the writer),
   `AuditLogIndexRequest`, `AuditLogResource` + `AuditLogDetailResource` +
   `AuditTargetStub`, `AuditEventCatalogue`, `AuditLogController`, two routes,
   two permissions, and `API_DOCUMENTATION.md` §9. 30 tests, suite green at 305.

2. ⚠️ **NARROWED, NOT CLOSED — the audit trail is a back door around
   `hr.employees.viewSalary`.**
   `AuditLogService::sanitize()` redacts twelve keys — credentials plus
   `national_id`, `salary`, `agreed_salary`, `sham_cash_account`. It does **not**
   redact the nine classification axes (`activity_type`,
   `organizational_grade`, `experience_level`, `engineering_rank`,
   `industrial_experience_years`, and the three pay flags plus
   `distinction_grade`), which `viewSalary` gates everywhere else precisely
   because they are the inputs pay is computed from. So an employee update
   writes them verbatim, and anyone holding an audit-read permission can read
   them out of `new_values` on an append-only table that cannot be scrubbed
   afterwards. Either add them to the sanitize list, or ship
   `auth.audit.viewValues` as a genuinely separate grant — the prompt assumes
   the latter and handles the absent key either way.

   **Taken: the separate grant.** `sanitize()` is unchanged, because redacting
   the axes is irreversible on an append-only table — it would make "who changed
   this person's grade, and to what" unanswerable forever, which is the question
   an audit exists to answer. The residue is real and is now recorded as
   **GAP-13** in `API_BUILD_STANDARD.md`: a caller with `viewValues` and without
   `hr.employees.viewSalary` can still read the axes out of history. The
   remaining fix is a policy decision (refuse to grant the two apart), not a code
   change, and it was not taken unilaterally.

   **⚠️ Amended 2026-09-20 — the hole was wider than this entry said, and that
   part IS fixed.** Framing it around `viewSalary` was too narrow.
   `HasAuditLog` fills `new_values` from `getAttributes()`, so
   `auth.audit.view` + `viewValues` and nothing else could read *every* audited
   table — employees, departments, job_titles, users, roles — with no HR or Auth
   read permission granted at all. Neither option above closes that, because
   both were written about the nine axes. `AuditLogDetailResource` now also
   requires the view permission of the module the row is about, per type,
   failing closed for a type it has no entry for. What remains is exactly the
   `viewSalary` residue described above and nothing wider.

3. ✅ **ANSWERED — `api_request` will be ~99% of the table.** Safe GETs are still
   audited, deliberately: "who read this" is a control, not noise, and the audit
   endpoints themselves are the clearest case. The volume is handled at the read
   end instead — `event_group` is a first-class filter, and the doc tells clients
   to default to `model,auth` while saying so on screen. Nothing was excluded
   from the `audit` middleware, and the audit-log routes carry it like the rest.

4. ❌ **STILL OPEN, NOT FIXED — `user_agent` is `varchar(255)`.** Real user-agent
   strings exceed that. Under MySQL strict mode the insert throws and the row is
   swallowed by the `try/catch` in `HasAuditLog` / `AuditLogMiddleware` — an
   audit row silently not written is the worst failure this table has.

   Deliberately out of scope for the read surface: it is a **write-path**
   schema defect on a shared table, independent of exposing the endpoints, and
   widening a column on `audit_logs` deserves its own commit and its own check of
   the production SQL mode. The detail endpoint now returns `user_agent`, so the
   symptom is at least visible: a row whose UA was truncated or whose insert was
   swallowed will show it. **Next action:** confirm `sql_mode` in production,
   then `->change()` the column to `text` in a `database/migrations/Shared/`
   migration.
