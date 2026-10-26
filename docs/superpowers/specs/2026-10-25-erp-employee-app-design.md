# The ERP Employee App («موظف الراقي») — design

**Date:** 2026-10-25 · **Status:** agreed in brainstorming, awaiting spec review
**Implements:** work order §4.10–§4.11 ([`EMPLOYEE_APP_V1_WORK_ORDER.md`](../../EMPLOYEE_APP_V1_WORK_ORDER.md)), mapping §11 ([`LEGACY_TO_ERP_MAPPING.md`](../../LEGACY_TO_ERP_MAPPING.md))
**UX source:** the approved UX spec on `doc_approval` branch `v11_employee_app`,
`docs/superpowers/specs/2026-10-18-employee-app-ux-design.md`, reconciled with
the ERP decisions taken since (§7 below)
**Visual source:** the Claude Design canvas «Al-Raqi Employee App»
(https://claude.ai/artifact/5sJizfy6oetN3oExvedEAy), 19 artboards, last edited
2026-10-21 (A11)

---

## 1. Intent

Every employee records attendance through the company app, so the app is a
payroll input (requirements §7.1). The ERP now serves attendance, leave,
approvals and the `/api/me` surface. This app is the client for that surface.
It ships to the pilot group (D12) while the legacy app keeps running for
everyone else.

It is a **copy** of the existing Flutter app, not a rewrite, and not the same
repository. The legacy app (`doc_approval`) stays as it is for people still on
legacy. The ERP app evolves on its own.

**Success:** the app half of the work order's §6 exit criteria.

- A pilot user signs in with their employee number and changes the temporary
  password.
- They check in and out online and offline, and the offline queue survives a
  token expiry.
- A leave goes direct manager → مدير الموارد → approved, with a push at each
  step, and the balance moves.
- Nobody can read anyone else's attendance, leave or pay through the app.

## 2. Decisions taken in this design

| # | Question | Decision |
|---|---|---|
| A1 | What the copy starts from | `doc_approval` branch **`v11_employee_app`**: the five-tab redesign, already built against the legacy API |
| A2 | Git history | **New repository, history kept.** Clone, `v11_employee_app` becomes `main`, tag `forked-from-doc_approval-v11` |
| A3 | Application id (D11) | **`tech.alraqi_company.erp`**, Android and iOS. Permanent once published |
| A4 | Display name | **«موظف الراقي»**. The legacy app is «الراقي», so the two are distinguishable during the pilot |
| A5 | Firebase (D8) | **The legacy Firebase project, reused.** The new Android and iOS apps are registered inside it, and the ERP backend sends through that project's service account |
| A6 | Scope | **The work order's v1, exactly.** No team view (فريقي اليوم, the Team screen). Managers and HR approve in the app; everything else is on the dashboard |
| A7 | API host | **The local ERP now.** The pilot URL is decided when the ERP is deployed. `API_BASE_URL` is a `--dart-define` with no default |
| A8 | The documents feature | **Removed from the copy.** It stays in git history and in `doc_approval`, and is restored onto the ERP's documents API in November |
| A9 | Approach | **Foundation first, then one vertical slice per area**, on ERP-shaped models. No adapter that maps ERP JSON into legacy shapes |
| A10 | The month calendar's day states | **A new ERP endpoint, `GET /api/me/attendance/days`.** The day grid already exists for HR (§14.3); the employee gets it for themself only. The precedence rule lives in one place |
| A11 | The visual source | **The live Claude Design canvas**, not v11's copy of it. v11 saved 16 artboards on 2026-10-18; the canvas has 19. Since then, the employee screens (01–12) changed only in layout robustness (`flex-shrink`, `min-height`), so v11's built screens still match. The role layer (13–18) was reworked: a new manager's view of one employee (15), a «للمتابعة» list (16), HR's employee list (18), and **role-specific tab bars** (manager: فريقي, HR: الموظفون, both in place of راتبي). None of 13–18 is in v1 (A6) |

## 3. The copy and its identity (slice 0)

**Where:** `C:\Users\M.Hawa\Desktop\work\alraqi-erp-app`, beside `doc_approval`.
The GitHub repository is created by the project owner. The suggested name is a
private `alraqi-erp-app`.

**Steps, in order:**

1. Push `v11_employee_app` to `doc_approval`'s `origin`. Today it exists only on
   this disk, and it becomes the new app's ancestor. This is a git write on the
   legacy repo and is done only on the owner's go-ahead.
2. `git clone` the local `doc_approval`, check out `v11_employee_app`, rename it
   to `main`, remove the `origin` remote, and tag the starting commit
   `forked-from-doc_approval-v11`.
3. Add the new remote once the owner has created the repository.

**Identity changes:**

| What | From | To |
|---|---|---|
| Dart package | `doc_approval` | `alraqi_erp`, with every `package:` import rewritten |
| Android `applicationId` / `namespace` | `com.example.doc_approval` | `tech.alraqi_company.erp` |
| iOS bundle id (and `RunnerTests`) | `com.example.docApproval` | `tech.alraqi_company.erp` (`….RunnerTests`) |
| Display name (Android label, iOS `CFBundleDisplayName`) | «الراقي» / `Doc Approval` | «موظف الراقي» |
| `MaterialApp.title` | «تطبيق الموظف» | «موظف الراقي» |
| Version | `1.0.9+10` | `1.0.0+1` |
| Launcher icon | legacy's | a variant built from the existing brand assets that is visibly different from legacy's. The owner approves the image before the pilot build |
| Firebase config | legacy app's files | `google-services.json` / `GoogleService-Info.plist` for the new app ids. The owner registers them in the Firebase console |

**The design files:** `docs/design/employee-app-v2/project/` is replaced with
the live canvas's 19 artboards and its `canvas.json` (A11), so the repository's
design reference is the one the screens are built from. The canvas stays the
place the design is edited; the repository holds a dated copy.

**Removed on day one:**

- `lib/features/documents/` and `test/features/documents/`, and every route,
  request kind and notification mapping that points into them.
- The approver picker: `core/widgets/approver_picker_sheet.dart`,
  `core/widgets/reorderable_approver_list.dart` and
  `core/validation/approver_validation.dart`. The ERP resolves the chain.
- Dependencies only those used. Each one is confirmed unused by `flutter
  analyze` and a grep before it is removed (expected:
  `syncfusion_flutter_pdfviewer`, `open_filex`). `file_picker` and
  `image_picker` stay, because leave attachments need them.

**API host:** `AppConstants.baseUrl` reads `API_BASE_URL` with **no default**.
An empty value fails at startup with a clear message, instead of calling the
placeholder `https://api.yourcompany.com/api`. There are two run
configurations: Android emulator (`http://10.0.2.2:8000/api`) and a device on
the LAN (`http://<PC IP>:8000/api`). Cleartext stays debug-only, as v8 set it.

## 4. The foundation: network and auth core (slice 1)

Mapping §11 items 1–4, plus sessions and push.

### 4.1 Envelope and failures

- One parser for `{success, message, data}` (API §1). Read `success` and
  `message` on every response, and `data` when present.
- `ApiFailure` has typed cases:

  | Case | HTTP | Carries |
  |---|---|---|
  | `Unauthenticated` | 401 | the server's message |
  | `Forbidden` | 403 | the server's message (disabled, suspended, must change password, out of scope, no employee) |
  | `NotFound` | 404 | — (the generic message is never parsed) |
  | `Validation` | 422 | `message`, and the `errors` bag mapped to form fields |
  | `RateLimited` | 429 | `Retry-After` seconds, shown as a countdown |
  | `Server` | 5xx | — |
  | `Offline` | no response | — |

- Every user action sends a fresh `X-Request-Id`. The app's error log records
  it beside the failure, so a reported bug traces to the server's audit rows.

### 4.2 The single-flight refresh interceptor

- It triggers on HTTP **401 only**. A 403 never refreshes (API §1).
- While a refresh is in flight, every other 401 waits on the same future. Then
  each retries once with the new access token. Concurrent 401s cause exactly
  one `POST /auth/refresh`.
- The new pair is written to secure storage, as one write, **before** any
  retry. The refresh token is single-use, so losing the new one forces a
  re-login.
- It never refreshes for `/auth/login`, `/auth/refresh` or `/auth/password`.
  On the last of these, a 401 means "the current password is wrong" (API
  §3.5).
- A failed refresh (any 401 from `/auth/refresh`: expired, invalid, disabled,
  suspended, replay detected) ends the session locally and shows the server's
  message on the login screen.

### 4.3 Session states

`checking → signedOut → mustChangePassword → signedIn`.

- **Boot:** stored tokens are verified with `GET /api/auth/me`. This is the
  server-verified bootstrap `doc_approval` v10 already has, re-pointed.
- **`must_change_password`:** the forced-change screen is the only reachable
  route, plus logout. The API refuses everything else with 403 anyway; the
  app does not loop on it.
- **Password changed:** the response's token pair **replaces** the stored one
  (every older token is dead). Then the permissions primer (location, camera,
  notifications, each with its reason), then home.

### 4.4 Login

- One `login` field: **digits only, numeric keyboard**. The employee number
  is the Kobo id (`KOBO_EMPLOYEE_NUMBER_DESIGN.md`, 2026-10-24).
- `device_name` and `platform` are sent so the sessions list (API §3.6) is
  readable. This adds `device_info_plus`.
- Distinct outcomes:
  - wrong credentials (401)
  - account disabled (403)
  - employee suspended (403)
  - rate-limited (429, with the wait)
  - offline
- An account with **no linked employee** (403 «لا يوجد موظف مرتبط بهذا
  الحساب.» from `/api/me/*`) gets one screen, «هذا التطبيق مخصّص للموظفين»,
  and a logout. Administrative accounts use the dashboard.

### 4.5 Capabilities, not roles

Legacy role strings (`employee` / `manager` / `chief` / `admin`) and every
branch on them are deleted. The UI reads `permissions` from `/auth/me` when a
permission matters. Approving is **not** a permission (API §15): the approvals
segment appears because the person has approval work (§5, slice 5).

### 4.6 Sessions, logout, push

- **«الأجهزة»** under حسابي: `GET /api/auth/sessions`, end one session
  (`DELETE /api/auth/sessions/{id}`), and «تسجيل الخروج من جميع الأجهزة»
  (`POST /api/auth/logout-all`).
- **Logout:** `POST /api/auth/logout`. The server drops this session's push
  token with it. Then local tokens and the media cache are cleared.
- **The offline attendance queue is never dropped silently** on logout.
  v11's session-cleanup rule is kept and re-verified in slice 3.
- **Push:** the FCM token is registered with `POST /api/me/device-tokens` on
  every app start once signed in (never while `mustChangePassword`; the API
  refuses it then) and whenever FCM rotates the token.

## 5. The slices

Dependency order. The app builds and runs after each one. Each slice deletes
the legacy concepts it touches; none is wrapped to look like the old shape.

| # | Slice | ERP endpoints (API doc §) |
|---|---|---|
| 0 | The copy and identity | — (§3 above) |
| 1 | The foundation | `/auth/*` (§3), `/me/device-tokens` (§6.5) (§4 above) |
| 2 | الرئيسية | `/me/summary`, `/me/photo` (§17) |
| 3 | دوامي and check-in | `/me/attendance`, `/me/attendance/sync`, `/me/attendance/{id}/selfie` (§12), **new** `/me/attendance/days` |
| 4 | Leave: الطلبات › طلباتي | `/leave/types?for=requests` (§16.1), `/me/leave-balance` (§16.2), `/me/leave-requests` (+ `/cancel`) (§16.3), `/leave/requests/{id}` (+ attachments) (§16.4) |
| 5 | Approvals: الطلبات › بانتظار قراري | `/me/approvals` (+ `?status=decided`) (§15.2), `/approvals/{id}` (§15.3), `…/approve`, `…/reject` (§15.4) |
| 6 | Notifications and push | `/auth/notifications/*` (§6) |
| 7 | حسابي and راتبي | `/me/profile`, `/me/photo` (§17), `/auth/sessions` (§3.6), `/auth/password` (§3.5) |
| 8 | Pilot hardening | — |

### Slice 2 — الرئيسية

- Every card comes from `/api/me/summary` and stands alone. A `null` card is
  «ستتوفر قريباً», never a zero. Today that is `salary`.
- **Identity header:**
  - The photo comes from `GET /api/me/photo`, through the cache that is cleared
    at session end. A 404 shows initials.
  - Name, job title · unit.
  - The bell with `notifications.unread_count`.
- **Today hero:** from `attendance_today`. A `null` status is «لم يُسجَّل بعد»,
  never absent (API §17.3). On approved leave, the leave type replaces the
  button.
- **Stat tiles:**
  - leave remaining (`leave_balance`)
  - this month's days and hours (`attendance_month`)
  - salary («ستتوفر قريباً»)
- **يحتاج انتباهك:**
  - a missing check-out from the hero's state
  - `approvals.pending_count > 0`
  - `leave_requests.pending_count > 0`
  - Hidden when empty.
- **Role layer:** «بانتظار قراري (n)» when `approvals.pending_count > 0`. No
  فريقي اليوم, no HR priorities (A6).
- **Identity strip:** employee number, direct manager (`manager`, «غير محدد»
  when `null`), workplace.

### Slice 3 — دوامي and check-in

**Backend prerequisite, in the ERP repository:**
`GET /api/me/attendance/days?month=&year=` (or `from`/`to`, ≤ 92 days),
self-scoped with no permission. It is §14.3's day grid for the caller's own
employee: the same `days`, `summary`, `working_days` and `counted_to`, through
the `days()` path `/api/me/summary` already uses. It has its own tests,
including that a caller can never pass another employee's id. It is built and
documented in the API doc before this slice starts.

**In the app:**

- **Day states** are the server's (§14.3):

  | Code | State |
  |---|---|
  | 1 | حاضر — not checked out yet |
  | 2 | حاضر |
  | 3 | انصراف مفقود |
  | 4 | إجازة |
  | 5 | عذر |
  | 6 | غياب |
  | 8 | مرفوض |
  | 9 | لم يُسجَّل بعد |

  `7` (day off) is unreachable as configured but is still rendered. **No
  متأخر and no عطلة** (decisions Q8–Q9). The calendar legend and the
  day-state palette shrink to match.
- **Check in/out:**
  - `type` is the int `1`/`2`.
  - `recorded_at` carries the device's offset.
  - Every capture gets a `client_uuid` (UUID v4) at the moment it is taken,
    and keeps it through every retry. A 200 «هذا السجل محفوظ مسبقاً» is a
    success.
  - The camera, the GPS and `captureWithDraft` recovery stay as v11 has them.
- **Sync:**
  - `records[]` and `selfies[]` are matched by index.
  - Each `succeeded` entry leaves the queue.
  - Each `failed[index]` stays with its display-ready `error`. The employee
    can dismiss it (v11's behaviour).
  - The «وقت التسجيل لا يمكن أن يكون في المستقبل.» refusal is shown, not
    retried forever.
- **Local database:** the schema restarts at version 1 with `client_uuid` in
  it. A new application id is a fresh install, so there is nothing to migrate.
- **Removed:** the `attendanceCheck` flag and the hero's "disabled" phase
  (Q7: every active employee records).
- **Day sheet:** the day's records from `GET /api/me/attendance?date=`. The
  selfie comes through `/me/attendance/{id}/selfie` (404 = purged after three
  months, shown as such). A refused record shows HR's reason. A corrected one
  shows its note.

### Slice 4 — Leave

- **Code moves:** leave leaves `features/attendance/` for its own
  `features/leave/` (data, domain, presentation).
- **The form:**
  - Types come from `GET /api/leave/types?for=requests`.
  - Balance per type comes from `/me/leave-balance`.
  - Dates.
  - A reason, required when the type's `requires_reason` is set.
  - Attachments: camera, photo or file. Up to 5, PDF/JPG/PNG, ≤ 10 MB each,
    at least one when `requires_attachment` (sick leave).
  - A **read-only route preview**: المدير المباشر (the name from the summary's
    `manager`) ← مدير الموارد البشرية. The server resolves the real chain at
    submission. The app never sends approvers.
- **Refusals:** every 422 is shown in its own words, on its field when the
  `errors` bag names one. That includes over-balance, overlap and a vacant
  manager post.
- **My list:** `/me/leave-requests`, newest first, with HR's excuses included
  and marked as such. Filter chips by status.
- **Detail:**
  - The leave request object (§16.3).
  - Its attachments, streamed, never a URL.
  - The **embedded approval timeline**: each step's resolver, approver and
    status, skip reasons in words, reassignments, and the note.
- **Cancel:** shown only when `approval.can_withdraw`, via
  `/me/leave-requests/{id}/cancel`.
- **Return to work is HR's action** (`leave.requests.returnToWork`) and is not
  in the app.

### Slice 5 — Approvals

- **The segment:** «بانتظار قراري» appears when the person has a pending step,
  or any decided history (`?status=decided` non-empty). It is checked once
  per session and refreshed with the summary.
- **Inbox:** `/me/approvals`, pending only. A «قراراتي السابقة» view reads
  `?status=decided`.
- **Opening a row:**
  - `GET /api/approvals/{approval_id}` for the live state.
  - The subject (`leave_request` → `GET /api/leave/requests/{id}`) for the
    dates, type, reason, attachments, and the requester's balance after the
    leave.
- **Acting:** موافقة / رفض appear only when `can_act`, using
  `current_step_id`. **Reject requires a reason in the app** (UX spec §4.4),
  although the API accepts an empty `note`. The screen redraws from the
  approval in the response, never from a cached copy (API §15.1).
- **A decided request** loses its actions. After a step-1 approval, the
  timeline shows the next step as current, not "completed".
- **Not in v1:** inbox filters and reassignment. Both are on the dashboard.

### Slice 6 — Notifications and push

- **List:** `/auth/notifications` (paginated), with mark-read and
  `read-all`. The badge is updated from `marked`.
- **Text:** `type_label` is shown verbatim. When it is `null`, the raw `type`
  is shown, never a guess.
- **Taps follow the API's table (§6):**

  | `type` | Opens |
  |---|---|
  | `approval.step_pending`, `approval.step_assigned` | the approval under الطلبات |
  | `leave.submitted`, `leave.approved`, `leave.rejected`, `leave.cancelled` | that leave |
  | `leave.excuse_filed`, `leave.excuse_cancelled`, `leave.returned_to_work` | my leave list |
  | `attendance.checkout_reminder` | دوامي |
  | `attendance.missing_checkout`, `attendance.record_rejected`, `attendance.record_unrejected` | that day's sheet |
  | anything else | the full text |

- **Push taps** use the FCM data `type`, `reference_type` and `reference_id`
  with the same table.
- **A destination that answers 403 or 404** shows a plain message, never a
  broken page. Nothing is ever acted on from the notification itself.

### Slice 7 — حسابي and راتبي

- **الملف الوظيفي:** from `/api/me/profile`, grouped:
  - المعلومات الشخصية
  - الوظيفة (job, unit, start date, employment type)
  - الهوية (the national id, shown to its owner)

  A missing value reads «غير مسجّل». Contracts, certificates and experience
  have no ERP record yet (requirements §5.8–§5.9): one quiet «ستتوفر قريباً»
  row each. No العهد (finance).
- **Photo:** «تحديث الصورة» by camera or gallery, compressed on the phone, JPG
  or PNG ≤ 5 MB, `POST /api/me/photo`. It replaces the old one, with no
  approval.
- **The rest of حسابي:** the sessions screen (§4.6), change password, about,
  logout. مستنداتي and التقييم والتطوير are single «ستتوفر قريباً» rows.
- **راتبي:**
  - The **pay block** from the profile (decision 17, D10 = shown): agreed
    salary, the nine classification axes, the pay flags.
  - A «إخفاء» toggle masks the amounts. Home stays masked by default.
  - Payslips and the month selector are «ستتوفر مع نظام الرواتب» until
    Stage 4. Nothing is shown as `0`.

### Slice 8 — Pilot hardening

- **On-device checks:**
  - **The refresh race:** open the app after more than 15 minutes idle with an
    offline queue waiting. The sync and the screen load both hit 401, exactly
    one refresh happens, and the session survives.
  - Offline check-in, then reconnect.
  - First login through the forced change.
- **Release build:**
  - The launcher icon, approved.
  - Release signing for Android: a new keystore for the new id, kept out of
    the repository.
  - A release build pointed at the pilot `API_BASE_URL` once the ERP is
    deployed.
- **The first-login run-through** with the pilot group in one sitting (work
  order §4.11).

## 6. Testing

- **Contract fixtures:** the JSON examples in `API_DOCUMENTATION.md` are
  copied verbatim into `test/fixtures/erp/`, and every repository test parses
  them. A change to an ERP shape surfaces as a fixture and a failing test.
- **Unit:** controllers and providers, with fake repositories.
- **Widget:** each home card in each of the six states (ready, not yet
  available, empty, loading, error, offline). The router's deep links, from a
  notification and from a push.
- **Must-have tests, by risk:**
  - **Refresh:**
    - Five concurrent 401s cause one refresh and five retries.
    - A 403 never refreshes.
    - A 401 on `/auth/password` never refreshes.
    - A failed refresh moves the app to `signedOut` with the server's message.
    - The new pair is stored before the first retry.
  - **Attendance:**
    - Re-sending a saved `client_uuid` empties the queue.
    - A partial sync keeps only the failed entries, each with its reason.
    - The future-time refusal is not retried in a loop.
  - **Approvals:**
    - Actions only when `can_act` / `can_withdraw`.
    - The screen redraws from the action's response.
    - Reject without a reason is blocked in the form.
  - **Session:**
    - `must_change_password` reaches only the change screen.
    - Password-change tokens replace the stored pair.
    - An account with no employee reaches only its screen.
- **Carried tests:** v11's existing tests are rewritten with the slice that
  owns them. The documents tests are deleted with the feature.
- **The gate for every slice:** `flutter analyze` clean, `flutter test` green,
  and the slice's flow exercised by hand against the local ERP on its dev
  seeders.

## 7. Where this differs from the 2026-10-18 UX spec

The UX spec was written before these ERP decisions. Where they disagree, the
decision wins:

| UX spec | Here | Why |
|---|---|---|
| Five day states incl. متأخر and عطلة | حاضر / انصراف مفقود / إجازة / عذر / غياب / مرفوض / لم يُسجَّل بعد | Q8, Q9 (no holiday calendar, no "late"); S-1 (state 9) |
| Documents as a request kind; مستنداتي; circular acknowledgement | Removed for v1 | Work order §1: documents move onto the engine in November (A8) |
| Home role layer: فريقي اليوم, HR priorities; Team screen | Not in v1 | A6 |
| Login accepts an email for non-employee accounts | Digits only; non-employee accounts get a single screen | `KOBO_EMPLOYEE_NUMBER_DESIGN.md`, work order §4.10 |
| Request detail: return-to-work action | Not in the app | HR's permission (API §16.7) |
| راتبي: month selector, breakdown, payslip | The pay block only; payslips «ستتوفر مع نظام الرواتب» | No payroll before Stage 4; D10 = shown |
| HeroPhase `disabled` (attendance not enabled) | Removed | Q7: no eligibility flag |
| Hero line «الوردية 08:00 – 16:00», «تبدأ ورديتك» (canvas 01) | No shift line; the hero shows the date, state and check-in time only | Q9: no shift time exists |
| «راجع اليوم أو قدّم طلب تصحيح» on a missing check-out (canvas 01) | «راجع اليوم» only | No employee correction request exists; a correction is HR's (`attendance.records.correct`) |
| Home salary card with the last transferred pay (canvas 01) | «ستتوفر قريباً» | No payroll before Stage 4 |
| Role-specific tab bars: manager فريقي, HR الموظفون (canvas 13, 17, 18) | Everyone gets the employee tab bar in v1 | A6: no team view in v1. The role tab bars arrive with it |

The visual system (colour, type, spacing, the component kit) is unchanged.

## 8. Out of scope

- Any HR or manager administration in the app (manage on the dashboard).
- The team view (A6). It is the next release's first candidate, and canvas
  artboards 13–18 are its design: the manager and HR homes, فريقي, one
  employee's file, «للمتابعة», الموظفون, and the role tab bars.
- Documents, circulars and acknowledgement (November).
- Payslips (Stage 4). طلب سلفة and العهد (إدارة المالية).
- Contracts, certificates, experience records, performance evaluation (no ERP
  record).
- Dark mode (UX spec D7).
- Approval inbox filters and reassignment in the app.

## 9. Owed by people, not code

| What | Who | Blocks |
|---|---|---|
| Go-ahead to push `v11_employee_app` to `doc_approval`'s origin | owner | slice 0 |
| Create the GitHub repository | owner | adding the remote (not the work) |
| Register `tech.alraqi_company.erp` (Android, iOS) in the legacy Firebase project; hand over the two config files | owner | slice 6 push on a device |
| The launcher icon image | owner approves | slice 8 |
| The deployed ERP URL for the pilot build | owner | slice 8 release build |
| Fix line 111 of `API_DOCUMENTATION.md`: it says one access token per user, but the code mints one per session (`AuthService::createAccessToken`) | ERP side | nothing; documentation only |
