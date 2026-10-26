# Employee Import by Upload — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** HR and the developer choose HR's workbook and the صرح export in the dashboard, review a dry run, and import for real — the same importer `hr:import-employees` runs, with two additions to its report.

**Architecture:** A run record (`employee_import_runs`) and synchronous endpoints in `app/Modules/HR` (approach A). `EmployeeImportService` stores the two uploads on the private disk, runs `EmployeeImporter` as a dry run, then — on execute — for real on the same files, returning the temporary passwords in that response only. The importer itself gains the صرح-field fix, the not-on-duty rule, and the *changes* and *not in the file* report sections, so the command gets all of them too. The screen is one page in `erp-dashboard`.

**Tech Stack:** Laravel 12, PHP 8.3, openspout 4.28, PHPUnit feature tests with `RefreshDatabase`, Sanctum, Pint · React 18, react-bootstrap, axios, sweetalert2, `node --test`.

**Spec:** [`docs/EMPLOYEE_IMPORT_UPLOAD_DESIGN.md`](../../EMPLOYEE_IMPORT_UPLOAD_DESIGN.md) (approved 2026-10-22, §11 as written). Read with work order §4.4 and its 2026-10-15 entries.

## Global Constraints

- **No git writes.** Do not commit, stage or branch; each task ends at a checkpoint where the user decides on a commit (memory: committing is the user's decision; a plan's commit steps do not count).
- **Re-check `git status` before editing a shared file** — another agent may edit this tree, and `erp-dashboard`, concurrently.
- Every PHP file starts `declare(strict_types=1);`; match the house comment density — a docblock saying *why*, citing the decision (U1–U6, §n of the spec).
- Every response through `ApiRespond` (`success`, `created`); every client-facing message Arabic and display-ready (C20); no path, class or trace in any response (C12).
- A business refusal is `EmployeeImportException` (implements `App\Shared\Exceptions\DomainException`); it carries no payload (C17).
- **A temporary password exists only in `ImportReport::credentials()` and the execute response.** Never in `employee_import_runs`, the report, the log, the audit trail, a `GET` response or a file left on disk.
- Run lifetime **60 minutes** from upload; upload limit **20 MB** per file, `.xlsx` only; the lock name `hr-employee-import`.
- New permission `hr.employees.import`, label «استيراد الموظفين من ملف»; **not** added to `PermissionReach::ROW_SCOPED` (so company-only) and **not** given to any seeded role.
- Files: `.php` and `.js(x)` with LF endings (`git ls-files --eol` on new files).
- After each task: the whole suite and `vendor/bin/pint --test` green; mutate each new guard and watch a test fail (the house's mutation check). Dashboard: `npm test` and `npm run lint`.

## Review Focus

1. **The two files swapped** — the صرح export chosen as the workbook: refused with an Arabic 422 naming the missing sheet «قاعدة البيانات», nothing stored. Test in Task 6.
2. **«استيراد فعلي» pressed twice** — the second execute is refused «تم تنفيذ هذا الاستيراد من قبل.» and creates nobody; the status is re-read *inside* the lock. Test in Task 8; the button disables while pending in Task 12.
3. **The tab left open past the hour, then execute** — 422 «انتهت صلاحية هذا الفحص، ارفع الملف مرة أخرى.»; the screen returns to step 1. Test in Task 8; behaviour in Task 12.
4. **Arabic file names with invisible right-to-left marks** (the real workbook starts with 18 U+200F) — kept verbatim as `workbook_name`, never used in a storage path. Test in Task 6.
5. **A reload after a real run** — `GET` of an executed run returns no credentials, and the screen says the passwords are gone (per-person resets). Test in Task 7; behaviour in Task 12.

---

## File map

**alraqi-erp-system**

| File | Responsibility |
|---|---|
| `app/Modules/HR/Import/EmployeeRowMapper.php` | + `SARH_ONLY` constant |
| `app/Modules/HR/Import/EmployeeImporter.php` | §7 fix, not-on-duty rule, changes, not-in-file |
| `app/Modules/HR/Import/EmployeeImportFields.php` (new) | Arabic labels and display values for every field the importer writes; the old → new diff |
| `app/Modules/HR/Import/ImportReport.php` | + changes, not-in-file, `summary()`, `toArray()` |
| `app/Modules/HR/Import/ImportReportWriter.php` | + sheets التغييرات and غير موجودين في الملف |
| `app/Modules/HR/Import/OpenSpoutWorkbookReader.php` | Arabic, path-free messages |
| `app/Console/Commands/ImportEmployees.php` | prefixes reader errors with the file it read |
| `database/migrations/HR/2026_10_22_100000_create_employee_import_runs_table.php` (new) | the run record |
| `app/Modules/HR/Enums/ImportRunStatus.php` (new) | `Checked = 1`, `Executed = 2`, `Expired = 3` |
| `app/Modules/HR/Models/EmployeeImportRun.php` (new) | the run |
| `app/Modules/HR/Policies/EmployeeImportRunPolicy.php` (new) | viewSalary + viewIdentity company-wide |
| `app/Modules/HR/Exceptions/EmployeeImportException.php` (new) | the refusals |
| `app/Modules/HR/Services/EmployeeImportService.php` (new) | check, find, execute, expire |
| `app/Modules/HR/Requests/EmployeeImportRequest.php` (new) | upload validation |
| `app/Modules/HR/Resources/EmployeeImportRunResource.php` (new) | the run on the wire |
| `app/Modules/HR/Controllers/EmployeeImportController.php` (new) | four actions |
| `app/Modules/HR/routes.php` | four routes |
| `app/Console/Commands/ExpireImportRuns.php` (new), `routes/console.php` | hourly expiry |
| `database/seeders/PermissionSeeder.php`, `app/Shared/Support/AuditEventCatalogue.php` | permission; two events in a new `hr` group |
| `docs/API_DOCUMENTATION.md`, `docs/EMPLOYEE_APP_V1_WORK_ORDER.md` §8 | the endpoints; what was built |

**erp-dashboard**

| File | Responsibility |
|---|---|
| `src/sections/hr/import/importReport.js` (+ `.test.js`) (new) | pure helpers: filters, expiry label, base64 → Blob |
| `src/sections/hr/import/EmployeeImport.jsx` (new) | the page: choose, review, import |
| `src/sections/hr/import/ImportRowsTab.jsx`, `ImportChangesTab.jsx`, `ImportMissingTab.jsx` (new) | the three tabs |
| `src/data/Routingdata.jsx`, `src/data/Sidemenudata.jsx`, `src/data/permissionsCatalog.js`, `src/data/auditEvents.js` | route, menu, permission, event labels |

---

### Task 1: The صرح-only fields are kept on update (spec §7)

**Files:**
- Modify: `app/Modules/HR/Import/EmployeeRowMapper.php`, `app/Modules/HR/Import/EmployeeImporter.php` (`importOne()`)
- Test: `tests/Feature/HR/Import/EmployeeImporterTest.php`, `tests/Feature/HR/Import/ImportEmployeesCommandTest.php`

**Interfaces:**
- Produces: `EmployeeRowMapper::SARH_ONLY = ['mother_name', 'national_id', 'sons_count', 'daughters_count']`; `MappedRow` gains `public readonly bool $hasSarh` (true when the row had a صرح record).

- [ ] **Step 1: Write the failing tests** in `EmployeeImporterTest`:
  - `test_an_update_without_a_sarh_record_keeps_the_stored_sarh_fields` — import a row with a صرح record (`national_id` `01234567890`, `mother_name` `فاطمة`, 2 sons); re-run the same row with `$sarhById = []`; assert `national_id === '01234567890'`, `mother_name === 'فاطمة'`, `sons_count === 2`, outcome `updated`.
  - `test_a_sarh_record_with_a_blank_field_still_clears_it` — second run's صرح record has `اسم الام` blank → `mother_name` is `null` (U2: the source said so).
  - `test_a_create_without_a_sarh_record_still_writes_nulls` — new person, no صرح record → created, `national_id` null, `sons_count` 0.
  - In `ImportEmployeesCommandTest`: `test_a_rerun_without_sarh_keeps_national_ids` — real run with `--sarh`, second real run without it (new credentials path), the employee keeps `national_id`.
- [ ] **Step 2: Run** `php artisan test --filter=EmployeeImporterTest` — the first and the command test FAIL (`national_id` null).
- [ ] **Step 3: Implement.** Set `hasSarh` in `map()`; in `importOne()`, when `$existing !== null && ! $mapped->hasSarh`, remove `SARH_ONLY` keys from `$attributes` before `dropTakenUniques()`. Docblock cites spec §7.
- [ ] **Step 4: Run** the HR import tests — PASS. Then the suite and Pint.
- [ ] **Step 5: Checkpoint** — report to the user; no commit.

### Task 2: An employee not on duty is not touched by the import — ⚠️ R1, awaiting the owner's confirmation

> The mapper writes `status = Active` for every row. Today an upload would put a **terminated or suspended** employee back to نشط — without re-enabling the account the termination hook disabled — against U3 (termination is by hand in the ERP) and C-3 (nobody acts on an employee who is not on duty). **Recommended:** leave them untouched and report it. If the owner chooses otherwise, replace this task before starting it.

**Files:**
- Modify: `app/Modules/HR/Import/EmployeeImporter.php` (`importOne()`)
- Test: `tests/Feature/HR/Import/EmployeeImporterTest.php`

**Interfaces:**
- Consumes: `Employee::isOnDuty(): bool` (exists, C-3).
- Produces: outcome `skipped` with a `warning` for an existing, not-trashed employee who is not on duty.

- [ ] **Step 1: Write the failing tests:**
  - `test_a_terminated_employee_in_the_workbook_is_left_untouched` — import, terminate (status `Terminated`), change the row's title, re-run → outcome `skipped`; status still `Terminated`; title unchanged; the issue message is exactly «الموظف ليس على رأس عمله في النظام — لم يُحدَّث من الملف. إن عاد إلى العمل فأعِد حالته من شاشة الموظف.»
  - `test_a_suspended_employee_is_left_untouched_too` — same with `Suspended`.
  - `test_a_soft_deleted_employee_is_still_restored` — unchanged behaviour: outcome `restored`.
- [ ] **Step 2: Run** — the first two FAIL (status becomes Active).
- [ ] **Step 3: Implement** the early return in `importOne()` after `employeeFor()`: existing, not trashed, `! isOnDuty()` → `['skipped', $existing, null]` with the warning appended. The source row is still remembered (`EmployeeImportSource::remember`), so Task 3's not-in-file does not list them.
- [ ] **Step 4: Run** — PASS; suite and Pint.
- [ ] **Step 5: Checkpoint.**

### Task 3: The report's two additions — changes and not in the file (spec §5)

**Files:**
- Create: `app/Modules/HR/Import/EmployeeImportFields.php`
- Modify: `EmployeeImporter.php`, `ImportReport.php`, `ImportReportWriter.php`
- Test: `tests/Feature/HR/Import/EmployeeImporterTest.php`, `tests/Feature/HR/Import/EmployeeImportFieldsTest.php` (new), `ImportEmployeesCommandTest.php`

**Interfaces:**
- Produces:
  - `EmployeeImportFields::LABELS` — `array<string, string>`, field => Arabic label, for every key `MappedRow::$attributes` can hold plus `department_id`, `job_title_id`, `legacy_workplace`, `legacy_manager_name`, `legacy_job_title`. Copy the wording `erp-dashboard/src/sections/hr/EmployeeFormModal.jsx` uses for the same fields.
  - `EmployeeImportFields::changes(Model $model, array $attributes): array<int, array{field: string, label: string, old: ?string, new: ?string}>` — fills a **clone**, takes its `getDirty()` keys, renders both sides with `display()`.
  - `EmployeeImportFields::display(Model $model, string $field, mixed $value): ?string` — `BackedEnum` → `label()`; dates → `Y-m-d`; bool → «نعم» / «لا»; `department_id` → the unit's names from the division down, joined « › » (`Department::loadAncestors()`); `job_title_id` → the title's name; else the string.
  - `ImportReport::addChanges(int $row, string $name, ?string $employeeNumber, array $fields): void`, `changes(): array`
  - `ImportReport::addNotInFile(int $employeeId, string $employeeNumber, string $name, ?string $unit, bool $imported): void`, `notInFile(): array`
  - `ImportReport::summary(): array{created: int, updated: int, restored: int, skipped: int, failed: int, errors: int, warnings: int, info: int}`
  - `ImportReport::toArray(): array{rows: array, changes: array, not_in_file: array}` — **never** credentials; a dry run's `created` rows carry `employee_number: null` (as the writer blanks it).

- [ ] **Step 1: Write the failing tests:**
  - `EmployeeImportFieldsTest::test_every_field_the_mapper_writes_has_a_label` — map a fixture row with every workbook and صرح column filled; every key of `attributes` and `openingPosting`, plus `department_id` and `job_title_id`, is in `LABELS`.
  - `EmployeeImportFieldsTest::test_display_renders_enums_units_titles_dates_and_booleans` — a `Gender` → its label; a section id → «الإدارة الهندسية › قسم التخطيط والدراسات»; `has_hazard_pay` true → «نعم».
  - `EmployeeImporterTest::test_a_dry_run_lists_what_an_update_would_change` — import; edit the employee's `phone` in the ERP; dry run → `changes()` holds one entry for that row with `field: 'phone'`, `old` the ERP value, `new` the workbook's; nothing changed in the database.
  - `test_an_unchanged_or_created_row_lists_no_changes`.
  - `test_the_opening_postings_text_is_in_the_changes` — `legacy_job_title` edited → listed.
  - `test_people_on_the_roll_missing_from_the_file_are_listed` — one imported earlier and absent now → `imported: true`; one created by `Employee::factory()` → `imported: false`; a terminated one and a soft-deleted one → not listed; none of them changed.
  - `test_a_failed_row_does_not_list_its_employee_as_missing` — a row whose Kobo key exists but fails → its employee is not in `notInFile()` (matched by the keys in the file, not by outcome).
  - `ImportEmployeesCommandTest::test_the_report_has_the_changes_and_missing_sheets` — sheet names `التغييرات` and `غير موجودين في الملف` exist, with a header row.
- [ ] **Step 2: Run** — FAIL (class and methods missing).
- [ ] **Step 3: Implement.** `importOne()` calls `changes()` before `correct()` for an existing employee, and `recordOpeningPosting()` adds the posting's dirty fields to the same entry. `importAll()` collects the source keys it saw; after the loop, `addNotInFile()` for every employee not trashed, status not `Terminated`, with no `employee_import_sources` row whose `source_key` is in that set (inside the dry run's transaction, so new people count as matched). Writer: sheet **التغييرات** — السطر، الاسم، الرقم الوظيفي، الحقل، القيمة في النظام، القيمة في الملف (one line per field); sheet **غير موجودين في الملف** — الرقم الوظيفي، الاسم، الوحدة، المصدر («مستورد سابقاً» / «مُضاف يدوياً»); both RTL with auto-filter.
- [ ] **Step 4: Run** — PASS; suite and Pint.
- [ ] **Step 5: Checkpoint.**

### Task 4: The reader's refusals in Arabic, with no path (spec §8)

**Files:**
- Modify: `app/Modules/HR/Import/OpenSpoutWorkbookReader.php`, `app/Console/Commands/ImportEmployees.php`
- Test: `tests/Feature/HR/Import/OpenSpoutWorkbookReaderTest.php`, `ImportEmployeesCommandTest.php`

**Interfaces:**
- Produces: `WorkbookException::getMessage()` — Arabic, never containing the path. Exact texts:
  - missing file: «تعذّر العثور على الملف.»
  - unreadable: «تعذّر فتح الملف — تأكد أنه ملف Excel ‏(xlsx) سليم.»
  - no sheet: «لا توجد ورقة باسم «{sheet}». الأوراق الموجودة: {names joined '، '}»
  - no header row: «الورقة «{sheet}» لا تحتوي على السطر {n} الذي تُقرأ منه العناوين.»
  - doubled header: «العنوان «{header}» مكرر في السطر {n} من الورقة «{sheet}» — لا يمكن تحديد أيّهما يُقرأ.»
  - missing columns: «السطر {n} من الورقة «{sheet}» ينقصه: {columns joined '، '}»

- [ ] **Step 1: Update the reader tests' expected messages** to the texts above, and add `assertStringNotContainsString($path, $e->getMessage())` to each. Command test: `test_a_reader_error_names_the_file_it_read` — the console line is `"{$workbook}: لا توجد ورقة …"`.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** the messages; the command prints `"{$file}: {$e->getMessage()}"` where `$file` is whichever of the two it was reading.
- [ ] **Step 4: Run** — PASS; suite and Pint.
- [ ] **Step 5: Checkpoint.**

### Task 5: The run record, the permission and the gate

**Files:**
- Create: the migration, `ImportRunStatus.php`, `EmployeeImportRun.php`, `EmployeeImportRunPolicy.php`, `EmployeeImportException.php`
- Modify: `database/seeders/PermissionSeeder.php`, `app/Shared/Support/AuditEventCatalogue.php`
- Test: `tests/Feature/HR/Import/EmployeeImportRunTest.php` (new); update `PermissionSeederTest` (`TOTAL` + list), `PermissionReachTest` (company-only list), `AuditEventCatalogueTest` if it pins group names

**Interfaces:**
- Produces:
  - Table `employee_import_runs` — columns exactly as spec §4.1; `status` unsignedTinyInteger indexed; `summary` json not null; `report` json nullable; `created_by` FK `users` `restrictOnDelete`.
  - `ImportRunStatus: int` — `Checked = 1` «تم الفحص», `Executed = 2` «تم الاستيراد», `Expired = 3` «منتهي الصلاحية»; `label(): string`.
  - `EmployeeImportRun` — casts: `status` enum, `summary`/`report` array, the three timestamps datetime; `isExpired(?CarbonInterface $now = null): bool` (`status === Expired` or `now >= expires_at`); `creator(): BelongsTo`.
  - `EmployeeImportRunPolicy::import(User $user): Response` — allow when `AccessScope::reachesEverything($user, 'hr.employees.viewSalary')` **and** the same for `viewIdentity`; else deny «يتطلب الاستيراد صلاحيتَي عرض الرواتب وأرقام الهوية على مستوى الشركة.» (Auto-discovered, as `EmployeePolicy` is.)
  - `EmployeeImportException` named constructors, status as data: `expired()` 422 «انتهت صلاحية هذا الفحص، ارفع الملف مرة أخرى.»; `alreadyExecuted()` 422 «تم تنفيذ هذا الاستيراد من قبل.»; `busy()` 409 «يجري استيراد آخر الآن، حاول بعد قليل.»; `unreadable(string $which, WorkbookException $e)` 422 «{ملف الموارد|ملف صرح}: {message}».
  - Audit events `employees_import_checked` «فحص ملف استيراد الموظفين» and `employees_import_executed` «استيراد الموظفين من ملف», in a new group `GROUP_HR = 'hr'`.

- [ ] **Step 1: Write the failing tests** in `EmployeeImportRunTest`: `test_is_expired_after_sixty_minutes_or_when_marked` (travel 59 / 60 minutes); `test_the_policy_needs_both_field_permissions_company_wide` — company grant with both → allowed; missing either → denied; both via a `ScopeType::Unit` grant → denied; super admin → allowed. Seeder test expects `hr.employees.import` with its label.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** the files above; migrate the development database.
- [ ] **Step 4: Run** — PASS; suite and Pint.
- [ ] **Step 5: Checkpoint.**

### Task 6: Upload and dry run — `POST /api/hr/employee-imports`

**Files:**
- Create: `EmployeeImportService.php`, `EmployeeImportRequest.php`, `EmployeeImportRunResource.php`, `EmployeeImportController.php`
- Modify: `app/Modules/HR/routes.php`, `docs/API_DOCUMENTATION.md`
- Test: `tests/Feature/HR/Import/EmployeeImportUploadTest.php` (new)

**Interfaces:**
- Consumes: Task 3's `ImportReport::summary()` / `toArray()`, Task 5's model, policy, exception.
- Produces:
  - `EmployeeImportService::check(User $user, UploadedFile $workbook, ?UploadedFile $sarh): EmployeeImportRun` — under `Cache::lock('hr-employee-import', 300)` (not acquired → `busy()`): store as `hr-import/runs/{uuid}/workbook.xlsx` and `…/sarh.xlsx` on disk `local`; read with the command's sheets and header rows (`قاعدة البيانات` row 3; `Sheet1` row 1); `WorkbookException` → delete the folder, throw `unreadable()`; run the importer with `dryRun: true`; then create the run (`Checked`, hashes, `expires_at = now + 60 min`, summary, `toArray()`); `logAction(event: 'employees_import_checked', …)` with run id, names, hashes, summary — no personal data.
  - `EmployeeImportService::findOwned(int $id, User $user): EmployeeImportRun` — `where created_by` + `firstOrFail()` → 404.
  - `EmployeeImportRequest` — `workbook: required|file|mimes:xlsx|max:20480`, `sarh: nullable|file|mimes:xlsx|max:20480`; Arabic attribute names «ملف الموارد», «ملف صرح».
  - `EmployeeImportRunResource` — `id, status, status_label, workbook_name, sarh_name, checked_at, executed_at, expires_at, summary, report` (`report` as stored, `null` once purged). Never a path, never a hash.
  - Route group `employee-imports`, name `employee-imports.`, middleware `['permission:hr.employees.import', 'can:import,'.EmployeeImportRun::class]` on all four routes; `{run}` `whereNumber`. Declared before the `employees/{employee}` routes (the routes file's ordering rule).

- [ ] **Step 1: Write the failing tests** (fake `WorkbookReader` as `ImportEmployeesCommandTest` does, keyed on the stored file name `workbook.xlsx` / `sarh.xlsx`; `Storage::fake('local')`):
  - `test_a_dry_run_stores_the_files_and_changes_nothing` — 201; `data.status === 1`; `data.summary.created === 1`; `Employee::count() === 0`; both files exist under `hr-import/runs/`.
  - `test_the_report_carries_rows_changes_and_missing` — keys present, with Task 3's shapes.
  - `test_the_swapped_files_are_refused_with_the_missing_sheet` — reader throws no-sheet for the workbook → 422, message starts «ملف الموارد: لا توجد ورقة باسم «قاعدة البيانات»»; no run row; no files left. *(Review Focus 1)*
  - `test_original_names_are_kept_verbatim_and_never_used_as_paths` — workbook named `"\u{200F}\u{200F}Program_HR_10_2026.xlsx"` → `workbook_name` equal to it; stored path ends `/workbook.xlsx`. *(Review Focus 4)*
  - `test_validation` — no workbook, a `.csv`, a 21 MB file → 422 Arabic.
  - `test_authorization` — no permission → 403; import without viewSalary → 403 with the policy message; scoped grant → 403.
  - `test_a_held_lock_refuses_with_409` — `Cache::lock('hr-employee-import')->get()` first → 409, nothing stored.
  - `test_the_check_is_audited_without_personal_data` — one `employees_import_checked` row; its `new_values` contain no employee name.
- [ ] **Step 2: Run** — FAIL (404 route).
- [ ] **Step 3: Implement** the service method, request, resource, controller `store()`, routes. Add the endpoint to `API_DOCUMENTATION.md` (a new section after the last, with the shapes above).
- [ ] **Step 4: Run** — PASS; suite and Pint.
- [ ] **Step 5: Checkpoint.**

### Task 7: Read a run and download its report

**Files:**
- Modify: `EmployeeImportController.php`, `EmployeeImportService.php`, `routes.php`, `API_DOCUMENTATION.md`
- Test: `tests/Feature/HR/Import/EmployeeImportReadTest.php` (new)

**Interfaces:**
- Produces: `GET /api/hr/employee-imports/{run}` (`show`) and `GET /api/hr/employee-imports/{run}/report` (`report`). `EmployeeImportService::reportFile(EmployeeImportRun $run): string` — writes the xlsx to a temp file from the stored `report` (rebuild an `ImportReport` from the stored `report` via `ImportReport::fromArray(array $data, bool $dryRun): self`, `$dryRun` true while the run is `Checked`), streamed as `تقرير-استيراد-الموظفين-{run id}.xlsx` and deleted after send (`deleteFileAfterSend`). Purged report → 422 «انتهت صلاحية هذا الفحص، ارفع الملف مرة أخرى.»

- [ ] **Step 1: Write the failing tests:** `test_the_uploader_reads_their_run`; `test_another_users_run_is_404` (another holder of the same permissions); `test_the_report_downloads_as_xlsx_with_the_sheets` (`الملخص`, `السطور`, `التغييرات`, `غير موجودين في الملف`); `test_an_executed_run_never_returns_credentials` — after Task 8 exists, a `GET` holds no `credentials` key and no password string *(Review Focus 5 — write it now as a skipped test, enable in Task 8)*; `test_a_purged_report_cannot_be_downloaded`.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** — PASS; suite and Pint.
- [ ] **Step 5: Checkpoint.**

### Task 8: The real import — `POST /api/hr/employee-imports/{run}/execute`

**Files:**
- Modify: `EmployeeImportService.php`, `EmployeeImportController.php`, `routes.php`, `API_DOCUMENTATION.md`
- Test: `tests/Feature/HR/Import/EmployeeImportExecuteTest.php` (new)

**Interfaces:**
- Produces: `EmployeeImportService::execute(int $id, User $user): array{run: EmployeeImportRun, credentials: ?array{file_name: string, content_base64: string}}` — under the lock: `findOwned()` again; refuse `isExpired()` → `expired()`, `Executed` → `alreadyExecuted()`; run the importer for real on the stored files; when `credentials()` is non-empty, write them with `ImportReportWriter::writeCredentials()` to a `tempnam()` file, base64 it, unlink it in `finally`; update the run (`Executed`, `executed_at`, summary, `toArray()`, paths null); delete the folder; `logAction(event: 'employees_import_executed', …)`. File name «كلمات-المرور-المؤقتة-{Y-m-d-Hi}.xlsx». Response `200`, `data: {run: EmployeeImportRunResource, credentials}`.

- [ ] **Step 1: Write the failing tests:**
  - `test_execute_imports_and_returns_the_passwords_once` — employees created; `data.credentials.content_base64` decodes to an xlsx whose rows hold the employee number and a 10-character password; the files are gone; status 2.
  - `test_execute_twice_is_refused_and_creates_nobody` — second call 422 «تم تنفيذ هذا الاستيراد من قبل.»; `Employee::count()` unchanged. *(Review Focus 2)*
  - `test_an_expired_run_is_refused` — travel 61 minutes → 422 with the expiry message. *(Review Focus 3)*
  - `test_another_users_run_is_404`; `test_a_held_lock_refuses_with_409`.
  - `test_no_password_is_stored_logged_or_left_on_disk` — after execute: the run row's `summary`/`report` JSON, every `audit_logs` row, `storage/logs/laravel.log` (or the log fake) and the temp directory contain none of the passwords.
  - `test_the_import_is_audited_with_the_uploader_as_actor` — `employees_import_executed` logged; the employees' `created` audit rows carry the uploader's `user_id`.
  - Enable Task 7's skipped credentials test.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** — PASS; suite and Pint.
- [ ] **Step 5: Checkpoint.**

### Task 9: Expiry — `hr:expire-import-runs`

**Files:**
- Create: `app/Console/Commands/ExpireImportRuns.php`
- Modify: `EmployeeImportService.php` (+ `expire(): int`), `routes/console.php` (`->hourly()`)
- Test: `tests/Feature/HR/Import/ExpireImportRunsTest.php` (new)

**Interfaces:**
- Produces: `EmployeeImportService::expire(?CarbonInterface $now = null): int` — for runs past `expires_at` with a `report` or a path: `Checked` → `Expired`, files deleted, paths and `report` null; `Executed` keeps its status, `report` null. `summary`, names, hashes stay. Returns the number touched; the command prints it.

- [ ] **Step 1: Write the failing tests:** `test_an_expired_checked_run_loses_its_files_and_report`; `test_an_expired_executed_run_keeps_its_status_and_summary`; `test_a_live_run_is_untouched`; `test_the_command_is_scheduled_hourly` (`Schedule` events contain `hr:expire-import-runs` with `0 * * * *`).
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** — PASS; suite and Pint.
- [ ] **Step 5: Checkpoint.**

### Task 10: Measure, and record what was built

**Files:**
- Modify: `docs/EMPLOYEE_APP_V1_WORK_ORDER.md` §8 (a dated entry), `docs/EMPLOYEE_IMPORT_UPLOAD_DESIGN.md` (status line)

- [ ] **Step 1: Time a real dry run through the endpoint** on the development database with the October files (an admin token; `curl -F workbook=@… -F sarh=@…`). Expected: under 30 seconds. **If over**, stop and report to the user (spec §8) — do not raise limits on your own.
- [ ] **Step 2: Write the §8 entry** — what was built, the test count, the timing, R1's answer, and that the dev database's `employee_import_runs` was migrated.
- [ ] **Step 3: Checkpoint** — the backend is complete; ask before starting the dashboard.

### Task 11: The dashboard's pure helpers

**Files (erp-dashboard):**
- Create: `src/sections/hr/import/importReport.js`, `src/sections/hr/import/importReport.test.js`
- Modify: `src/data/permissionsCatalog.js`, `src/data/auditEvents.js`

**Interfaces:**
- Produces:
  - `filterRows(rows, { outcome, severity }): rows` — `outcome` matches `row.outcome`; `severity` keeps rows with at least one issue of it; empty filters keep all.
  - `expiryLabel(expiresAt, now): string | null` — «تنتهي صلاحية هذا الفحص بعد N دقيقة» (N = minutes left, rounded up; «دقيقة واحدة» at 1); `null` when passed.
  - `base64ToBlob(base64): Blob` — type `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`.
  - `OUTCOME_LABELS` — `created` «جديد», `updated` «تحديث», `restored` «استعادة», `skipped` «لم يُستورد», `failed` «مرفوض» (the writer's words).
  - `permissionsCatalog.js`: `hr.employees.import` «استيراد الموظفين من ملف» in the `hr.employees` group; listed company-only (not in `HONOURED_FROM_SCOPED_GRANT`). `auditEvents.js`: the two events and the `hr` group with the backend's labels.

- [ ] **Step 1: Write the failing tests** (`node:test`, as `fileDownload.test.js`): each `filterRows` case; `expiryLabel` at 42 min, 1 min, 0 and past; `base64ToBlob` round-trips bytes and sets the type; the catalogue lists the permission as company-only.
- [ ] **Step 2: Run** `npm test` — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `npm test` and `npm run lint` — PASS.
- [ ] **Step 5: Checkpoint.**

### Task 12: The import page

**Files (erp-dashboard):**
- Create: `src/sections/hr/import/EmployeeImport.jsx`, `ImportRowsTab.jsx`, `ImportChangesTab.jsx`, `ImportMissingTab.jsx`
- Modify: `src/data/Routingdata.jsx` (path `hr/employees/import`, `requiredPermissions: ["hr.employees.import"]`), `src/data/Sidemenudata.jsx` (HR children, title «استيراد الموظفين», same permission)

**Interfaces:**
- Consumes: Task 6–8 endpoints; Task 11 helpers; `downloadFile(path, fallbackName)`; `confirmDestructive({action, subject, text, …})`; `parseApiError(error)`.

Behaviour, per spec §6:
- Step 1: two file inputs (`accept=".xlsx"`); without صرح, the warning «بدون ملف صرح لن تُستورد الأرقام الوطنية وأسماء الأمهات وعدد الأبناء.»; «فحص الملف» posts `FormData`; disabled while pending.
- Step 2: summary cards; tabs السطور / التغييرات / غير موجودين في الملف (the changes table uses `AuditDiff`'s old/new table styling, not the component); «تنزيل التقرير» → `downloadFile(\`/hr/employee-imports/${id}/report\`, …)`; `expiryLabel` refreshed every 30 s; when it returns `null`, the import button is replaced by «انتهت صلاحية هذا الفحص، ارفع الملف مرة أخرى.» and a return to step 1.
- Step 3: «استيراد فعلي» → `confirmDestructive` with the counts and «ستُستبدل أي تعديلات أُجريت في النظام على هذه الحقول بقيم الملف.»; disabled while pending *(Review Focus 2)*; on success, save `base64ToBlob(credentials.content_base64)` as `credentials.file_name` at once, keep the Blob in component state only (never storage) for «تنزيل كلمات المرور مرة أخرى», and show «لا تُحفظ كلمات المرور في أي مكان: سلّم الملف للموارد البشرية ثم احذفه. كلمة المرور المفقودة تُعاد لكل موظف على حدة.»; `credentials: null` → «لم يُنشأ أي حساب جديد.»
- A 422 expiry or already-executed from execute → toast the server's message and reload the run *(Review Focus 3)*. Opening a run that is already executed (a reload) → its report, and «انتهى الاستيراد. لم تعد كلمات المرور متاحة؛ أعِد تعيينها لكل موظف عند الحاجة.» *(Review Focus 5)*. The run id is kept in the URL (`?run=`) so a reload finds it.

- [ ] **Step 1: Build the page and its tabs.**
- [ ] **Step 2: Run** `npm run lint` and `npm test` — PASS.
- [ ] **Step 3: Verify in the browser** against the development API with the October files, as a user holding the three permissions: dry run → the three tabs fill; download the report; let a run expire (or shorten via a dev-only `expires_at` update) → the expiry state; real run on the development database → the credentials file downloads and re-downloads; reload → the "passwords gone" note; a user without viewSalary → the server's 403 message. Screenshot each state for the user.
- [ ] **Step 4: Checkpoint** — report, with the screenshots.

---

# Part 2 — Excluding rows before the real import (spec §12)

**Goal:** On the review screen HR ticks rows to leave out of this import; the dry run is re-checked with them left out, and the real run imports exactly that.

**Architecture:** The importer gains an `excluded` outcome for rows passed to `run(…, excludedRows:)`. The run stores `importable_rows` (fixed at the first check) and `excluded_rows`; a new `PUT …/exclusions` re-runs the dry run under the lock and replaces `summary`, `report` and `excluded_rows` together; `execute` passes `excluded_rows` to the real run. The screen adds checkboxes to السطور and an apply button.

**Spec:** [`docs/EMPLOYEE_IMPORT_UPLOAD_DESIGN.md`](../../EMPLOYEE_IMPORT_UPLOAD_DESIGN.md) §12 (approved 2026-10-23). Part 1's Global Constraints apply unchanged.

## Global Constraints (Part 2)

- Exclusions last **this upload only** (U8): nothing carries an exclusion to another run.
- Row numbers are **sheet row numbers** (the report's `rows[].row`).
- Eligibility is decided against **`importable_rows`** — the rows whose outcome in the run's first dry run was `created`, `updated` or `restored` — never against the current report.
- A re-check does **not** move `expires_at`.
- Exact copy (§12): outcome label «مستبعد»; issue «استُبعد هذا السطر من الاستيراد في هذه المرة.»; refusals «السطر {n} غير موجود في الملف.» and «السطر {n} لا يمكن استبعاده: لن يُستورد أصلاً.»; audit event `employees_import_excluded` «استبعاد سطور من استيراد الموظفين».

## Review Focus (Part 2)

1. **Exclusions applied, then the hour runs out before «استيراد فعلي»** — apply and execute both answer the expiry 422; the screen reloads the run. Test in Task 15 (apply on an expired run); behaviour in Task 18.
2. **Ticks changed but not applied, then import** — the import button is disabled on the screen; the server imports the *saved* list only, whatever the screen holds. Test in Task 16; behaviour in Task 18.
3. **Exclude, then clear** — `[]` restores the first dry run's report exactly. Test in Task 15.
4. **Apply pressed twice, or apply while an import runs** — the lock answers 409 and nothing is half-saved. Test in Task 15.
5. **Every importable row excluded** — the real run creates nobody and returns `credentials: null`. Test in Task 16.

## File map (Part 2)

| File | Change |
|---|---|
| `app/Modules/HR/Import/EmployeeImporter.php` | `run(…, array $excludedRows = [])`; the `excluded` outcome |
| `app/Modules/HR/Import/ImportReport.php`, `ImportReportWriter.php` | `excluded` in `OUTCOMES`, `summary()`, the labels |
| `database/migrations/HR/2026_10_23_100000_add_exclusions_to_employee_import_runs_table.php` (new) | `importable_rows`, `excluded_rows` |
| `app/Modules/HR/Models/EmployeeImportRun.php`, `Resources/EmployeeImportRunResource.php` | the two columns, cast and rendered |
| `app/Modules/HR/Services/EmployeeImportService.php` | `check()` stores `importable_rows`; new `exclude()`; `execute()` passes the list |
| `app/Modules/HR/Requests/EmployeeImportExclusionsRequest.php` (new), `Controllers/EmployeeImportController.php`, `routes.php` | the endpoint |
| `app/Modules/HR/Exceptions/EmployeeImportException.php` | `rowNotInFile()`, `rowNotImportable()` |
| `app/Shared/Support/AuditEventCatalogue.php` | the new event |
| `docs/API_DOCUMENTATION.md` §18, work order §8 | the endpoint; what was built |
| `erp-dashboard`: `src/sections/hr/import/importReport.js` (+ test), `ImportRowsTab.jsx`, `EmployeeImport.jsx`, `src/data/auditEvents.js` | the selection and the apply button |

---

### Task 13: The importer's `excluded` outcome

**Files:**
- Modify: `app/Modules/HR/Import/EmployeeImporter.php` (`run()`, `importAll()`), `ImportReport.php`, `ImportReportWriter.php`
- Test: `tests/Feature/HR/Import/EmployeeImporterTest.php`, `ImportEmployeesCommandTest.php`

**Interfaces:**
- Produces: `EmployeeImporter::run(iterable $rows, array $sarhById, bool $dryRun = false, string $sourceFile = '', string $sourceSheet = 'قاعدة البيانات', array $excludedRows = []): ImportReport`; `ImportReport::OUTCOMES` = `['created', 'updated', 'restored', 'excluded', 'skipped', 'failed']`; `summary()['excluded']`.

- [ ] **Step 1: Write the failing tests** in `EmployeeImporterTest` (the test's `import()` helper gains `array $excludedRows = []`):
  - `test_an_excluded_new_person_is_not_created` — rows `4 => row(1)`, `5 => row(2, …)`, `excludedRows: [5]` → `Employee::count() === 1`, `User::count() === 1`, row 5's outcome `excluded`, its issues contain «استُبعد هذا السطر من الاستيراد في هذه المرة.», `credentials()` has one entry.
  - `test_an_excluded_existing_person_is_left_as_the_erp_has_them` — import, change the row's phone in the workbook row, re-run with that row excluded → the phone unchanged in the ERP, `changes() === []`, outcome `excluded`, the row's `employee_number` is the person's number.
  - `test_an_excluded_person_is_not_listed_as_missing` — import, re-run excluding the row → `notInFile() === []`.
  - `test_an_excluded_row_no_longer_holds_a_national_id_against_a_later_row` — two rows whose صرح records share `الرقم الوطني في الهوية`: a dry run → the second row has the "held by another" warning; a real run with the first excluded → no such warning, and the second person has the ID.
  - `test_the_summary_counts_excluded_rows` — `summary()['excluded'] === 1`.
  - In `ImportEmployeesCommandTest`, `test_the_writer_labels_an_excluded_row` — `ImportReportWriter::writeReport()` of `ImportReport::fromArray()` holding one `excluded` row → its outcome cell reads «مستبعد»; the existing command tests pass unchanged (the command excludes nothing).
- [ ] **Step 2: Run** `php artisan test tests/Feature/HR/Import` — the new tests FAIL.
- [ ] **Step 3: Implement.** In `importAll()`, right after `$seen[$mapped->sourceKey] = $rowNumber;`: a row in `$excludedRows` is reported `excluded` — `employee_number` from `EmployeeImportSource::employeeFor()` or null, the info issue — and skipped with `continue`; nothing is written, and its key is already in `$seen`, so §5.2 counts it as present. `ImportReportWriter::OUTCOMES` gains `'excluded' => 'مستبعد'` between استعادة and لم يُستورد.
- [ ] **Step 4: Run** — PASS; suite and Pint.
- [ ] **Step 5: Checkpoint.**

### Task 14: The run remembers what can be excluded

**Files:**
- Create: `database/migrations/HR/2026_10_23_100000_add_exclusions_to_employee_import_runs_table.php`
- Modify: `EmployeeImportRun.php`, `EmployeeImportRunResource.php`, `EmployeeImportService.php` (`check()`, `import()`)
- Test: `tests/Feature/HR/Import/EmployeeImportUploadTest.php`

**Interfaces:**
- Consumes: Task 13's `run(…, excludedRows:)`.
- Produces: columns `importable_rows` and `excluded_rows` (json, nullable), both cast `array`; the resource renders both as lists (`[]` when null); `EmployeeImportService::import(string $workbookPath, ?string $sarhPath, string $workbookName, bool $dryRun, array $excludedRows = []): ImportReport`.

- [ ] **Step 1: Write the failing test** `test_a_check_records_the_rows_it_would_import` — the fake reader returns row 4 (importable) and row 5 (no `ID_Sarah`) → `data.importable_rows === [4]`, `data.excluded_rows === []`.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** the migration (a new one; the table is not recreated), the casts and fillables, the resource keys; in `check()`, `importable_rows` = the row numbers whose outcome is `created`, `updated` or `restored`. Migrate the development database.
- [ ] **Step 4: Run** — PASS; suite and Pint.
- [ ] **Step 5: Checkpoint.**

### Task 15: `PUT /api/hr/employee-imports/{run}/exclusions`

**Files:**
- Create: `app/Modules/HR/Requests/EmployeeImportExclusionsRequest.php`
- Modify: `EmployeeImportService.php` (+ `exclude()`), `EmployeeImportController.php` (+ `exclusions()`), `EmployeeImportException.php`, `routes.php`, `AuditEventCatalogue.php`, `docs/API_DOCUMENTATION.md` (§18.5)
- Test: `tests/Feature/HR/Import/EmployeeImportExclusionsTest.php` (new)

**Interfaces:**
- Consumes: Task 14's columns and `import(…, $excludedRows)`.
- Produces:
  - `EmployeeImportService::exclude(int $id, User $user, array $rows): EmployeeImportRun` — under `locked()`: `findOwned()`; executed → `alreadyExecuted()`; `isExpired()` or files gone → `expired()`; every row checked before anything is saved — not among the report's row numbers → `rowNotInFile($n)`, not in `importable_rows` → `rowNotImportable($n)`; then `import(…, dryRun: true, excludedRows: <sorted, unique>)` and one `update()` of `summary`, `report`, `excluded_rows`; `logAction(event: 'employees_import_excluded', …)` with the run id and `excluded_rows`.
  - `EmployeeImportException::rowNotInFile(int $row)` / `rowNotImportable(int $row)` — 422, §12.5's text.
  - Request rules: `rows` → `present`, `array`; `rows.*` → `integer`, `min:1`.
  - Route `PUT employee-imports/{run}/exclusions` → `exclusions`, `whereNumber('run')`, inside the existing group. Response 200, the run.

- [ ] **Step 1: Write the failing tests.** `FakeImportReader` gains a static `$rows` override (reset in every `setUp`) so this test can serve rows 4 and 9 (two importable people) and 5 (no `ID_Sarah`):
  - `test_excluding_a_row_rechecks_the_report` — PUT `{rows:[9]}` → 200, `data.excluded_rows === [9]`, `data.summary.excluded === 1`, `data.summary.created === 1`, row 9's outcome `excluded`.
  - `test_an_empty_list_restores_the_first_report_exactly` — exclude 9, then PUT `{rows:[]}` → `data.report` equals the report the upload returned. *(Review Focus 3)*
  - `test_an_excluded_row_can_be_brought_back` — exclude 9, then PUT `{rows:[4]}` → `excluded_rows === [4]`, row 9 `created`.
  - `test_duplicates_in_the_list_are_ignored` — `{rows:[9,9]}` → `[9]`.
  - `test_a_row_not_in_the_file_is_refused` — `{rows:[99]}` → 422 «السطر 99 غير موجود في الملف.»; the run unchanged.
  - `test_a_row_that_would_not_be_imported_is_refused` — `{rows:[5]}` → 422 «السطر 5 لا يمكن استبعاده: لن يُستورد أصلاً.»
  - `test_validation` — `rows` missing, `rows: "4"`, `rows: ["a"]` → 422 validation errors on `rows`.
  - `test_an_executed_or_expired_run_is_refused` — after execute → «تم تنفيذ هذا الاستيراد من قبل.»; after `travel(61)->minutes()` → «انتهت صلاحية هذا الفحص، ارفع الملف مرة أخرى.» *(Review Focus 1)*
  - `test_a_held_lock_refuses_with_409_and_saves_nothing` *(Review Focus 4)*; `test_another_users_run_is_404`.
  - `test_the_hour_does_not_restart` — `expires_at` identical before and after.
  - `test_each_change_is_audited_with_the_row_numbers` — one `employees_import_excluded` row whose `new_values` holds `[9]` and no name.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement**; the event goes into `AuditEventCatalogue` (group `hr`, label «استبعاد سطور من استيراد الموظفين»); document the endpoint as API §18.5.
- [ ] **Step 4: Run** — PASS; suite and Pint.
- [ ] **Step 5: Checkpoint.**

### Task 16: The real run honours the saved list

**Files:**
- Modify: `EmployeeImportService.php` (`execute()`, `auditMetadata()`), `docs/API_DOCUMENTATION.md` §18.4, work order §8
- Test: `tests/Feature/HR/Import/EmployeeImportExecuteTest.php`

**Interfaces:**
- Consumes: Task 15's `exclude()`; `import(…, excludedRows: $run->excluded_rows ?? [])`.

- [ ] **Step 1: Write the failing tests:**
  - `test_execute_leaves_the_excluded_rows_out` — fake rows 4 and 9; exclude 9; execute → one employee created, `data.run.summary.excluded === 1`, the credentials file holds one person. *(Review Focus 2)*
  - `test_excluding_everyone_imports_nobody` — exclude 4 and 9; execute → 200, `Employee::count() === 0`, `data.credentials === null`. *(Review Focus 5)*
  - `test_the_executed_audit_entry_lists_the_excluded_rows` — `employees_import_executed`'s `new_values` holds `"excluded_rows":[9]`.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement**; `auditMetadata()` adds `excluded_rows`.
- [ ] **Step 4: Run** — PASS; suite and Pint. Record Part 2's backend in the work order §8 (a dated 2026-10-23 entry).
- [ ] **Step 5: Checkpoint.**

### Task 17: The dashboard's selection helpers

**Files (erp-dashboard):**
- Modify: `src/sections/hr/import/importReport.js` (+ `.test.js`), `src/data/auditEvents.js`

**Interfaces:**
- Produces:
  - `OUTCOME_LABELS` gains `excluded: "مستبعد"` (between restored and skipped).
  - `canExclude(run, row, now = Date.now()): boolean` — `run.status === 1`, `expiryLabel(run.expires_at, now) !== null`, and `run.importable_rows` includes `row.row`.
  - `sameRows(a, b): boolean` — the same row numbers, order and duplicates ignored.
  - `exclusionPayload(ticked): { rows: number[] }` — sorted, unique.
  - `auditEvents.js`: `employees_import_excluded: M("hr", "muted")` and its fallback label «استبعاد سطور من استيراد الموظفين».

- [ ] **Step 1: Write the failing tests:** `canExclude` true for an importable row of a live checked run; false for a non-importable row, an executed run, a run past its hour. `sameRows([9,4],[4,9])` true; `sameRows([4],[4,9])` false; `sameRows([],[])` true. `exclusionPayload(new Set([9,4,9]))` → `{rows:[4,9]}`. The event's group is `hr`; the outcome label.
- [ ] **Step 2: Run** `npm test` — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `npm test` and eslint on the changed files — PASS.
- [ ] **Step 5: Checkpoint.**

### Task 18: Ticking and applying on the review screen

**Files (erp-dashboard):**
- Modify: `src/sections/hr/import/ImportRowsTab.jsx`, `src/sections/hr/import/EmployeeImport.jsx`

**Interfaces:**
- Consumes: Task 15's endpoint; Task 17's helpers. `ImportRowsTab` gains props `selectable: boolean`, `ticked: Set<number>`, `onToggle(row: number)`, `canTick(row): boolean`.

Behaviour, per spec §12.4:
- `EmployeeImport` holds `ticked` (a Set), reset from `run.excluded_rows` whenever a run arrives from the server (upload, load, apply, execute).
- السطور shows a checkbox column while the run is checked and inside its hour; a box only where `canExclude`.
- Above the table while selectable: «تطبيق الاستبعاد (N)» (N = ticked count), enabled only when `!sameRows(ticked, run.excluded_rows)`; while they differ, the note «لم يُطبَّق الاستبعاد بعد» with «تراجع» (restores the saved ticks). Applying PUTs `exclusionPayload(ticked)` under «جارٍ إعادة الفحص…», then replaces the run.
- «استيراد فعلي» is disabled while the ticks differ from the saved list, and while applying. *(Review Focus 2)*
- A 422 or 409 from apply → toast the server's message; a 422 also reloads the run. *(Review Focus 1)*
- The summary shows a «مستبعد» card; when N > 0 the confirmation adds «N مستبعد. لن يُستورد المستبعدون في هذه المرة؛ يُدرجون في الرفع القادم ما لم يُحذفوا من الملف.»
- After the real run, excluded rows read «مستبعد», with no checkboxes.

- [ ] **Step 1: Build it.**
- [ ] **Step 2: Run** `npm test` and eslint on the changed files — PASS; a development build (`npx vite build --mode development` into a temp folder) succeeds.
- [ ] **Step 3: Verify in the browser** against the development API, on a dry run of the October files created for the test account as in Task 12 (re-enable `import-ui-test@alraqi.test` for the check, disable it after): tick two rows → the note and the disabled import button; «تراجع»; tick and apply → «مستبعد» rows, the card, the re-checked counts; bring one back; expire the run in the database and apply → the expiry message and the reload. **No real import on the development database** unless every row is excluded (then it writes nothing). Screenshot each state.
- [ ] **Step 4: Checkpoint.**

### Task 19: Final review of Part 2

- [ ] **Step 1:** Package both repositories' working-tree diffs as Part 1 did, and dispatch one fresh reviewer on the most capable model with §12, this Part and its Review Focus.
- [ ] **Step 2:** Re-grade; fix the Critical and Important findings, each RED→GREEN with the whole suite green; ledger the minors.
- [ ] **Step 3:** Update the spec's status line (§12 built) and report.
