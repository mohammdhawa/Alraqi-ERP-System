# Employee import by upload — the design

> ⚙️ **One file since 2026-10-24** (project owner): HR merges the صرح columns
> the import reads into their own file, under their صرح headers, and the صرح
> upload is gone — the API refuses `sarh`, the screen has one file, the run
> keeps one file's name and hash (`sarh_*` dropped). Every «two files» and
> «صرح» below describes the design as first built; `WORKBOOK_COLUMN_MAP.md`
> lists the merged columns. A blank merged cell never erases a stored value
> (this replaces §7's "no صرح record" rule).

> **Status: approved and built 2026-10-22** — backend, dashboard screen and the
> final review's fixes (work order §8, "the employee import by upload"); not
> committed. **§12, excluding rows before the real import, agreed with the
> project owner and built 2026-10-23** — not committed.
> **R1**, decided while planning: a terminated or suspended employee in the
> workbook is left untouched and reported. *As first written:* for review,
> 2026-10-22. Nothing below is built. Agreed with the
> project owner in conversation the same day: who uses it, the leavers rule,
> the passwords, the dry-run flow, approach A, the API (part 1) and the screen
> (part 2). **Part 3 — §8 errors and limits and §9 testing — was approved
> before it was shown, so it is first read here: review it.** §11 lists the
> points to confirm.
>
> Read with [`EMPLOYEE_APP_V1_WORK_ORDER.md`](EMPLOYEE_APP_V1_WORK_ORDER.md) §4.4
> and its 2026-10-15 entries (the importer as built) and
> [`API_BUILD_STANDARD.md`](API_BUILD_STANDARD.md).

---

## 1. What it is

A dashboard screen where HR, or the developer, chooses HR's workbook and the
صرح export, sees a dry run's report, and then imports for real. It replaces
running `hr:import-employees` on a server with two long file paths. The command
stays: same importer, same rules, two callers.

## 2. Decided

| # | Question | Answer |
|---|---|---|
| U1 | Who uses it? | **HR and the developer** |
| U2 | Only until the first import settles, or for good? | **For good, and the workbook stays the source.** Each upload brings imported people in line with the workbook; an ERP edit to an imported field is overwritten by the next upload, and the dry run shows it first (§5.1) |
| U3 | Someone no longer in the workbook? | **Reported, never changed.** The report lists them (§5.2); HR terminates by hand in the employee screen, with a date and a reason |
| U4 | How do the temporary passwords reach HR? | **In the real run's own response, never stored.** A lost file costs per-person resets |
| U5 | How does a dry run lead to the real import? | **One upload, two steps.** The files wait on the private disk at most an hour; the real run imports exactly the files the dry run read |
| U6 | Approach | **A — a run record and synchronous requests.** No queue (B), no `Artisan::call` (C) |

## 3. What stays and what is new

**Unchanged:** `OpenSpoutWorkbookReader`, `EmployeeRowMapper`, `UnitResolver`,
`EmployeeImporter::run()`'s rules (the `kobo:` key, create / correct / restore,
per-row transactions, dropped duplicates, accounts once, a dry run rolled back
whole) and the command.

**Changed:**

- **The صرح-only fields are not cleared on update** (§7) — a defect found while
  designing this, in today's importer too.
- `ImportReport` gains **changes** (§5.1) and **not in the file** (§5.2);
  `ImportReportWriter` writes both as two more sheets, so the command's report
  has them too.
- `WorkbookException` messages become Arabic and carry no file path (§8).

**New:** the `employee_import_runs` table, `EmployeeImportService`, four
endpoints, one permission, a cleanup command, the screen.

## 4. The API — `app/Modules/HR`

### 4.1 The run record — `employee_import_runs`

| Column | |
|---|---|
| `id` | |
| `created_by` | the user who uploaded; FK `users` |
| `status` | unsignedTinyInteger, `ImportRunStatus`: `1` checked (dry run done), `2` executed, `3` expired |
| `workbook_name`, `sarh_name` | the original file names, as HR chose them; `sarh_name` nullable |
| `workbook_path`, `sarh_path` | on the private disk under `hr-import/runs/{id}/`; nulled when the files are deleted |
| `workbook_sha256`, `sarh_sha256` | kept: they say which files a run read, after the files are gone |
| `summary` | JSON, the counts: outcomes and issues by severity. **Kept for good** |
| `report` | JSON, the rows, changes and not-in-file. **Personal data — purged at expiry**, nullable |
| `checked_at`, `executed_at`, `expires_at` | `expires_at` = upload + 60 minutes |
| timestamps | |

No password, ever. A real run replaces `summary` and `report` with its own.

### 4.2 Endpoints

All `auth:sanctum` + `permission:hr.employees.import` (§4.3).

| Endpoint | Does | Success |
|---|---|---|
| `POST /api/hr/employee-imports` | multipart: `workbook` (required), `sarh` (optional). Stores both, runs the dry run, saves the run | `201`, the run with its report |
| `GET /api/hr/employee-imports/{run}` | the run and its report, e.g. after a reload | `200` |
| `GET /api/hr/employee-imports/{run}/report` | the report as the Arabic RTL xlsx `ImportReportWriter` writes | `200`, the file |
| `POST /api/hr/employee-imports/{run}/execute` | the real import on the stored files, then deletes them | `200`, the run with the real report, plus `credentials`: `{file_name, content_base64}` or `null` when no account was created |

**A run is its uploader's.** Another user's run is `404` (C9, row hidden), for
the developer too: HR's dry run is not executed by someone who did not read it.

**Execute refuses** (422, Arabic): an expired run; a run already executed.

### 4.3 The permission — `hr.employees.import` «استيراد الموظفين من ملف»

- **Company-wide only.** The import writes into every unit; a scoped grant
  cannot enforce its scope, so `PermissionReach` lists it company-only and a
  scoped grant of it is refused (decisions 21–24).
- **It needs `hr.employees.viewSalary` and `hr.employees.viewIdentity` as
  well**, both company-wide. The import writes pay fields and national IDs, and
  the report shows them old → new; decision 13 (من لا يرى لا يكتُب) says a
  writer of a field must be able to read it. Checked by route middleware on all
  four endpoints. **To confirm — §11.**
- Seeded in `PermissionSeeder` with its label; `PermissionSeederTest` and
  `PermissionReachTest` updated.

### 4.4 One import at a time

A cache lock (`hr-import`) around the dry run and around the real run. Two real
runs together could create the same person twice; a dry run beside a real run
would report a half-written state. Busy → `409`.

### 4.5 The audit trail

- `logAction`: `hr.employees.import.checked` and `hr.employees.import.executed`
  — the run id, file names, hashes and counts. No names, no values.
- Each employee, posting and account the real run writes is audited by its
  model hook as today, **with the uploader as the actor** (the command's runs
  have none).

### 4.6 Expiry — `hr:expire-import-runs`

Scheduled ⚙️ **every five minutes** in `routes/console.php` (*revised
2026-10-24:* hourly let files outlive their hour by up to another). It runs
**under the import lock and never waits for it** — while a check, re-check or
real run holds the lock the sweep is skipped («Skipped: an import is running.»)
and the next one catches up. A `checked` run past `expires_at`
becomes `expired`: files deleted, paths and `report` nulled. An `executed` run
past `expires_at` keeps its status and loses its `report` the same way; its
files went at execution. What stays of any run: who, when, file names,
hashes, counts.

## 5. Two additions to the report

### 5.1 What an update changes

For each existing person (`updated`, `restored`), the fields whose value the
run changes: `field`, Arabic label, `old`, `new`. Computed in
`EmployeeImporter::importOne()` from the model before `correct()` saves it,
plus the opening posting's text fields. Values display-ready: the label of an
enum, the unit's path, the job title's name.

This is how U2's overwrite is visible: the dry run shows an ERP edit about to
be replaced, and HR fixes the workbook or accepts it.

### 5.2 Not in the file

Every employee on the roll — not soft-deleted, not terminated — this run did
not match: employee number, name, unit, and **«مستورد سابقاً»** (they have an
`employee_import_sources` row) or **«مُضاف يدوياً»**. Report only (U3).

## 6. The screen — `erp-dashboard`

`hr/employees/import`, «استيراد الموظفين»; in the HR menu for holders of the
permission (`Routingdata.jsx`, `Sidemenudata.jsx`, `permissionsCatalog.js`).

1. **Choose the files.** Workbook required, صرح optional — without it, the
   command's warning: national ID, mother's name and children are not
   imported. «فحص الملف» uploads and runs the dry run.
2. **The dry run's report.**
   - The summary: created / updated / restored / skipped / failed; errors,
     warnings, for the record.
   - Three tabs. **السطور** — one row per workbook row, outcome and issues,
     filtered by outcome and severity. **التغييرات** — per person, old → new,
     on `AuditDiff`'s layout. **غير موجودين في الملف** — §5.2, with the note
     that a leaver is terminated by hand.
   - Download the report (`fileDownload.js`).
   - The expiry, counted down: «تنتهي صلاحية هذا الفحص بعد 42 دقيقة»; after
     it, upload again.
3. **The real import.** «استيراد فعلي» → `confirmDestructive` with the counts
   and the overwrite warning → execute.
   - The credentials file downloads at once, with a notice: it is kept
     nowhere, hand it to HR and delete it, a lost password is a per-person
     reset.
   - The file stays in the page's memory only — «تنزيل كلمات المرور مرة أخرى»
     until the page is left or reloaded; never browser storage.
   - The real run's report replaces the dry run's; the run reads «تم الاستيراد».

**Not built:** a list of past runs (the audit log has them); terminating from
the not-in-file tab (the employee screen does it, with its date and reason).

## 7. Found while designing — the صرح-only fields are cleared on update

`EmployeeRowMapper::map()` sets `mother_name`, `national_id` and the
children's counts from the صرح record, and to `null` when the person has
none — no صرح file uploaded, or a new hire not in the export yet. On a
**create** that is right. On an **update**, `correct()` saves those `null`s
over stored values: **a re-run without `--sarh` today would clear every
national ID and mother's name.** (The counts are already unset when null.)

**The fix:** with no صرح record for the person, the صرح-only attributes are left
out of the update — the stored values stay. The names fall back to the
workbook's full name as now. A صرح record that **has** the field blank still
clears it: the source said so (U2). Tested through the importer, so both the
command and the screen get it.

## 8. Errors and limits — ⚠️ first shown here

| Case | Response |
|---|---|
| no workbook; not `.xlsx`; a file over **20 MB** | `422`, validation, Arabic. Today's files are 0.7 MB and 0.3 MB. The server's `upload_max_filesize` / `post_max_size` must allow 40 MB (this machine: 40M / 40M) |
| unreadable file; no sheet `قاعدة البيانات` (`Sheet1` for صرح); a required column missing or doubled | `422` with `WorkbookException`'s message — **made Arabic and path-free** (C12, C20), e.g. «الملف لا يحتوي على الأعمدة: …». The command prints the same message. ⚙️ *2026-10-23/24:* a sheet that fails mid-read (a corrupt file) is refused too — «تعذّر قراءة الملف — قد يكون تالفاً؛ افتحه في Excel واحفظه ثم ارفعه مرة أخرى.» — and on **every** refusal the reader closes the sheet first, or Windows keeps the file locked and the cleanup cannot delete it |
| a run of another user, or none | `404` |
| execute: expired / already executed | `422`: «انتهت صلاحية هذا الفحص، ارفع الملف مرة أخرى.» / «تم تنفيذ هذا الاستيراد من قبل.» |
| another import running | `409`: «يجري استيراد آخر الآن، حاول بعد قليل.» |
| missing permission, or a scoped grant | `403` |
| a row fails during the real run | as the command: that row rolls back and reads `failed`; the others commit; the response is `200` with the report |
| the response of a real run is lost (network, closed tab) | the import happened; the run reads executed; the passwords are gone — per-person resets (U4). The screen says so if it reloads onto an executed run |

**Time.** Synchronous. 185 rows ran in seconds from the command line; the
build measures a real run through the endpoint. If it passes 30 seconds, the
request limit is raised for this endpoint and this section revisited before
anything else.

⚙️ **Revisited 2026-10-22, after the final review.** Measured with the October
files on an **empty** database (so all 184 accounts are created, bcrypt at 12
rounds): dry run **1.54 s**, real run **33.79 s** — past 30 s, almost all of
it one bcrypt hash per new account. So: a dry run now hashes **one** password
for all the accounts it rolls back; the check and the real run raise PHP's
limit to **600 s** and hold the lock **900 s**. The dashboard sets no request
timeout.

⚙️ **Also revised 2026-10-24 (final review, Part 1's minors):**

- The real run checks the stored files against the **hashes** taken at the
  check, and refuses a changed one, 422 «تغيّر الملف المخزّن منذ الفحص؛ ارفع
  الملف مرة أخرى.» — U5 enforced, not assumed.
- Both uploads are stored **inside** the cleanup's reach (a failure storing
  the صرح file used to orphan the workbook), and a store that reports failure
  is one.
- The report download and the passwords file are built in the private
  `hr-import/tmp` folder, and openspout's own working files beside them — not
  the shared system temp folder.
- The run carries **`seconds_left`**, by the server's clock; the screen counts
  down from it, not from `expires_at` against a browser clock that may be off.

⚠️ **Deployment:** a reverse proxy in front of the API (nginx, IIS,
a hosting panel) must allow this endpoint at least ~2 minutes, or it cuts the
response after the import has run — and with it the only copy of the
passwords.

**The uploaded files hold more than the import reads** — pay values, the صرح
export's religion, sect and ID scans. Private disk, never a URL, deleted at
execution or expiry; the reader still reads only its allow-lists.

## 9. Testing

Feature tests with small xlsx fixtures written by openspout, as
`OpenSpoutWorkbookReaderTest` does:

- **Upload:** a dry run changes nothing; files stored; run `checked`; the
  report has rows, changes and not-in-file; validation and `WorkbookException`
  cases give Arabic `422`s with no path.
- **Changes:** an employee edited in the ERP → the dry run lists old → new; the
  real run overwrites it.
- **Not in the file:** an imported and a hand-added employee missing from the
  workbook are listed and marked; a terminated one is not; none is changed.
- **§7:** an update without a صرح record keeps `national_id` and
  `mother_name` — through the importer and the command.
- **Execute:** imports, deletes the files, returns credentials once; a second
  execute, an expired run, another user's run (`404`) are refused.
- **Passwords:** absent from `employee_import_runs`, the log, the audit trail
  and the `GET` responses; present only in the execute response.
- **Lock:** a held lock → `409`, nothing imported.
- **Authorization:** without the permission, without viewSalary or
  viewIdentity, with a scoped grant → `403`.
- **Expiry command:** files deleted, `report` nulled, `summary` kept, for
  checked and executed runs; a live run untouched.
- **Audit:** both actions logged without personal data; employee changes
  carry the uploader.

Each guard mutated and seen to fail its test, as the house does. Dashboard:
`node --test` for the pure helpers (the diff rows, the countdown, base64 →
file); the screen checked in the browser against the development API.

## 10. Out of scope

Leavers terminated by the import (U3); imports in any other layout; a queue;
a run history screen; HR approving a run before it executes.

## 11. To confirm at review

1. **The import needs viewSalary and viewIdentity too** (§4.3). The
   alternative — the import permission alone, the report hiding pay and ID
   values — would let someone overwrite fields they cannot see.
2. **A run belongs to its uploader**, the developer included (§4.2).
3. **A blank workbook cell clears the ERP value** on update — U2 read
   literally. The changes tab shows each one before it happens.
4. **§7's fix** goes in with this work — or first, on its own, since today's
   command has it.
5. **Where it is built:** the API here; the screen in `erp-dashboard`, after
   the API is green.

## 12. Excluding rows before the real import — agreed and built 2026-10-23

HR asked to leave people out of an import from the review screen, without
going back to Excel. Agreed with the project owner in conversation, part by
part.

### 12.1 Decided

| # | Question | Answer |
|---|---|---|
| U7 | Edit rows on the screen, or only leave them out? | **Leave them out only.** Nothing is edited on the screen; the workbook stays the source (U2). An edit made here would be undone by the next upload of the same workbook — a second source of truth that silently loses |
| U8 | Does an exclusion outlast the upload? | **This upload only.** Every upload starts with nobody excluded; to keep someone out for good, HR removes or fixes the row in the workbook. No hidden list carries over |
| U9 | How does an exclusion reach the real run? | **Saved on the run, and the dry run re-checked** (approach A). Leaving a row out can change other rows — two rows sharing a national ID: the second loses it only while the first is imported — and who is "not in the file". So the report is recomputed with the exclusions applied, and U5 holds: the real run imports exactly what was reviewed |

Rejected: sending exclusions with the real run only and marking them on the
old report (B) — the report would be wrong about duplicates and the missing
list; a fresh upload without the rows (C) — what HR asked to avoid.

### 12.2 The API

**`PUT /api/hr/employee-imports/{run}/exclusions`**, body `{"rows": [4, 17]}`
— sheet row numbers, the ones HR sees in Excel and in السطور. Same middleware
as §4.2, same ownership (another's run is 404).

- The list **replaces** the saved one; `[]` clears it. Duplicates in the list
  are ignored.
- Under the import lock (§4.4): the dry run is **run again** on the stored
  files with those rows left out, and the run's `summary`, `report` and
  `excluded_rows` are replaced together. **200** — the run, as §4.2 renders it.
- **Which rows:** only those whose outcome in a dry run **with nothing
  excluded** is `created`, `updated` or `restored`. ⚙️ *Revised 2026-10-24
  (final review):* that dry run is made **afresh at each re-check**, not taken
  from the upload — the ERP may have changed since (a reinstated employee's
  row becomes importable, and the real run would import it). So a re-check is
  two dry runs when rows are excluded. A row already excluded stays eligible,
  so it can be brought back.
- **The hour does not restart** (§4.6); `expires_at` is unchanged.
- **Audit:** `employees_import_excluded` in the `hr` group — run id and the row
  numbers (not personal data). The real run's `employees_import_executed`
  carries the excluded row numbers too.

**The run** — `employee_import_runs` gains `excluded_rows` (JSON, nullable; a
list of row numbers) and `importable_rows` (JSON; the rows a dry run with
nothing excluded would import — set at the upload, refreshed at each re-check —
what the screen offers checkboxes on, since `report` shows excluded rows as
`excluded`). A new migration; the table is not
recreated. Both are kept after expiry with `summary`: row numbers name no one.

**The real run** (§4.2 execute) imports the stored files with `excluded_rows`
left out — the same call the re-check makes, with `dryRun: false`. ⚙️ *Added
2026-10-24 (final review):* execute may carry `{"excluded_rows": [...]}` — the
list the screen showed — and refuses another list than the saved one, 422
«تغيّرت قائمة المستبعدين منذ عُرضت هذه الصفحة؛ راجع الفحص ثم استورد.»: a stale
screen (a lost apply response, a second tab) must not confirm counts for a list
it did not show. The dashboard always sends it.

### 12.3 The importer

`EmployeeImporter::run()` gains `array $excludedRows = []`. A row in it:

- gets the new outcome **`excluded`** («مستبعد»), with an `info` issue
  «استُبعد هذا السطر من الاستيراد في هذه المرة.»;
- is **not written** — no employee created, corrected or restored, no account,
  no posting, no `employee_import_sources` change;
- **still counts as present in the file** for §5.2: its Kobo key is added to
  the keys the not-in-file list is matched against, so the person is never
  listed under غير موجودين في الملف;
- is set aside **after** the row's key is read and **before** anything is
  written, so it never reaches the unique-value checks: it no longer holds a
  national ID or Sham Cash number against a later row, which keeps its own.
  (A Kobo id never repeats in HR's workbook — `ID_Sarah` is unique, HR
  2026-10-14 — so the importer's existing guard against a repeated id is left
  as it is.)

`ImportReport::OUTCOMES` gains `excluded`; `summary()` gains its count;
`ImportReportWriter` labels it «مستبعد» on الملخص and السطور. The command
passes no exclusions, so its behaviour is unchanged.

### 12.4 The screen

During review only — a checked run inside its hour (§6 step 2):

- In **السطور**, a checkbox on every row whose original outcome was created,
  updated or restored; none on لم يُستورد or مرفوض.
- Ticking selects; nothing is sent until **«تطبيق الاستبعاد (N)»** above the
  table. It sends the whole list, shows «جارٍ إعادة الفحص…», blocks the import
  button meanwhile, and replaces the report with the re-checked one.
- While the ticks differ from the saved list, a note «لم يُطبَّق الاستبعاد
  بعد» shows, with a «تراجع» to restore the saved ticks, and **«استيراد فعلي»
  is disabled** — a real run never imports a list HR did not see re-checked.
- Excluded rows read **«مستبعد»** with the box ticked; unticking one and
  applying brings it back. The outcome filter gains «مستبعد»; the summary a
  «مستبعد» card.
- The import confirmation adds the count: «… N مستبعد. لن يُستورد المستبعدون
  في هذه المرة؛ يُدرجون في الرفع القادم ما لم يُحذفوا من الملف.»
- After the real run, excluded rows stay visible as «مستبعد», without
  checkboxes.

### 12.5 Errors

| Case | Response |
|---|---|
| a row number not in the file | 422 «السطر 12 غير موجود في الملف.» — nothing saved |
| a row the original dry run would not import (لم يُستورد / مرفوض) | 422 «السطر 12 لا يمكن استبعاده: لن يُستورد أصلاً.» |
| `rows` missing, not a list, or not whole numbers | 422, validation |
| the run executed / expired | 422 — §8's messages |
| another import running | 409 — §8 |
| another user's run | 404 |
| every importable row excluded | allowed; the real run imports nobody, and the confirmation says «0 جديد، 0 تحديث…» |

### 12.6 Testing

- **The re-check:** two rows sharing a national ID — excluding the first gives
  the second its ID in the new report; clearing the list restores the original
  report exactly.
- **Not imported:** an excluded new person is not created and gets no account;
  an excluded existing person keeps the ERP's values and is not listed as
  missing.
- **The real run** honours the saved list, imports the rest, and its audit
  entry carries the excluded rows; `employees_import_excluded` is logged on
  each change.
- **Refusals:** each row of §12.5, plus a row already excluded staying
  eligible, and the hour unchanged by a re-check.
- **The command** excludes nothing, so no row of its report reads «مستبعد»
  (الملخص and the console table list it with a count of 0); it is otherwise
  unchanged, and the writer labels the outcome when a row has it.
- **Dashboard:** `node --test` for the selection helpers — eligible rows, the
  ticks versus the saved list, the payload; the page checked in the browser
  against the development API.
- Each new guard mutated and seen to fail its test.

