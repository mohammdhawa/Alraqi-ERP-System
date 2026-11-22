# Documents — piece 1, foundations: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The pieces every later documents piece stands on: mPDF printing in a private per-print folder, the upload check that converts PDFs the printer cannot open, employees' signatures and units' stamps (encrypted, versioned, system-administrator rules), the company letterhead, and the rule that a person manages at most one unit.

**Architecture:** A new module `app/Modules/Documents` (routes under `/api/documents`), plus routes the Me and HR modules register for the employee's own signature and the administrator's view of it. Images that sign (signatures, stamps) are stored encrypted through one `SealedImages` service. PDF work runs inside `PdfWorkspace::run()`, which owns a temp folder for that print only. The one-unit rule is a `Department` model guard, like the hierarchy guard. No document, template or approval exists yet. Those are pieces 2–4.

**Tech Stack:** Laravel 12, PHP 8.2, PHPUnit (sqlite in memory), Pint; `mpdf/mpdf` ^8.3 (bundles `setasign/fpdi`); `qpdf` or Ghostscript on the server, through `Illuminate\Support\Facades\Process`.

**Spec:** [`docs/superpowers/specs/2026-11-22-documents-design.md`](../specs/2026-11-22-documents-design.md) ("spec §n" below): §5, §6.3, §10.1, §10.2, §11.1, §11.3, §11.5, §13, §17 piece 1.

## Global Constraints

- **Prerequisite:** the documents docs are committed by the owner (`DECISION_ROUND_DOCUMENTS.md`, the design, this plan, and the four modified docs). Then branch `documents-foundations` from that commit. If they are not committed, stop and ask.
- **No commit, stage, reset, stash or push without the owner's explicit go-ahead.** Leave changes in the working tree; `git add -N` new files.
- Another agent may edit this tree: run `git status` and re-read a file before editing it.
- **Untouched:** every file under `app/Modules/Approvals/`, `app/Shared/Services/ManagementChain.php`, and the Leave and Scheduling modules (D28; "Do not modify the existing leave or shift swap workflows").
- **System administrator only** (spec §5) means a Gate ability that returns `false` for everyone, so only `Gate::before`'s super-admin bypass passes. It is **never a permission row**, so no role editor can grant it. Exactly two abilities: `documents.stamps.manage` and `documents.signatures.delete`.
- **New permissions, exact:** `documents.view` «عرض جميع المستندات» and `documents.templates.manage` «إدارة قوالب المستندات», module `documents`, company-wide (not on `PermissionReach`'s allow-list), carried by no seeded role.
- **Every new model:** `HasAuditLog`, a morph alias in `AppServiceProvider::enforceMorphMap`, a label in `AuditTargetStub::TYPE_LABELS`, and an entry in `AuditLogDetailResource::TYPE_VIEW_PERMISSIONS` (`MorphMapTest` and `AuditTargetStubTest` enforce these). Aliases, labels and read permissions, exact: `employee_signature` «توقيع موظف» `['hr.employees.view']` · `unit_stamp` «ختم وحدة» `['departments.view']` · `document_letterhead` «ترويسة المستندات» `['documents.templates.manage']`.
- **Files:** the private `local` disk only, never a URL. Signatures and stamps are **encrypted** with the app key (`Crypt::encrypt($bytes, false)` / `Crypt::decrypt($payload, false)`). Anything streamed carries `Cache-Control: private, no-store`.
- **Limits, exact:** a signature is a PNG ≤ 512 KB; a stamp is PNG/JPG ≤ 2048 KB; a letterhead image is PNG/JPG ≤ 2048 KB; the intake takes PDF/JPG/PNG ≤ 20 MB (`PdfIntake::MAX_KB = 20480`, for piece 2's request).
- **mPDF:** `mode` `utf-8`, `format` `A4`, `default_font` `dejavusans`, `autoScriptToLang` and `autoLangToFont` true. Every print runs in its own folder `pdf-work/{uuid}` on the local disk, deleted in a `finally`, success or failure (spec §11.3). The font cache rebuilt per print is accepted: a few documents a day.
- **Repair (spec §11.5):** `config('documents.pdf.repair.driver')`: `qpdf` (default, env `DOCUMENTS_PDF_REPAIR`), `ghostscript`, or `none`. Binary from `DOCUMENTS_PDF_REPAIR_BINARY` (default `qpdf`, or `gs` for ghostscript), timeout `60` s. qpdf: `[binary, '--object-streams=disable', source, target]`, where exit codes 0 and 3 (warnings) succeed. Ghostscript: `[binary, '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-sDEVICE=pdfwrite', '-dCompatibilityLevel=1.4', '-sOutputFile='.target, source]`, where exit code 0 succeeds.
- **One unit per manager (spec §13):** a person is `manager_id` of at most one non-deleted unit, active or not.
- **Arabic copy, exact:**
  - «تعذّر تجهيز هذا الملف للتوقيع. يُرجى حفظه من جديد بصيغة PDF (مثل Microsoft Print to PDF) ثم رفعه.»
  - «يُرجى رفع الملف بصيغة PDF أو صورة.»
  - «تم حفظ توقيعك.»
  - «لديك توقيع محفوظ، ولا يمكن تغييره. لحذفه راجع مدير النظام.»
  - «لا يوجد توقيع محفوظ.»
  - «تم حذف التوقيع.»
  - «سبب الحذف مطلوب، من 3 إلى 500 حرف.»
  - «التوقيع يجب أن يكون صورة PNG لا يتجاوز حجمها 512 كيلوبايت.»
  - «تم حفظ ختم الوحدة.»
  - «لم يُرفع ختم لهذه الوحدة بعد.»
  - «الختم يجب أن يكون صورة PNG أو JPG لا يتجاوز حجمها 2 ميغابايت.»
  - «تم حفظ الترويسة.»
  - «تم حذف الترويسة.»
  - «لم تُرفع هذه الصورة بعد.»
  - «الصورة يجب أن تكون PNG أو JPG لا يتجاوز حجمها 2 ميغابايت.»
  - «هذا الموظف مدير لوحدة «{name}» بالفعل، ولا يدير الموظف أكثر من وحدة واحدة.»
- **Gates:** `php artisan test` and `vendor/bin/pint --test`, both clean at the end of every task.

## Review Focus

- **A password-protected PDF** cannot be opened by FPDI, and qpdf without the password cannot fix it. It must be refused with the intake's message, never a 500. Test in Task 2 (`test_a_pdf_that_stays_unreadable_after_repair_is_refused`, with the repairer failing).
- **qpdf missing on the server** (exit code 127, or the process throwing) is a failed repair: the upload is refused with the message, and a warning is logged. Test in Task 2 (`test_qpdf_missing_counts_as_a_failed_repair`).
- **A file named `.png` that is not an image** (a renamed PDF, a truncated upload) is refused with the size and type message, and nothing is left on disk. Tests in Task 3 and Task 4.
- **Replacing a stamp leaves the old version readable.** A later piece's printed card points at it (I.10). Test in Task 4 (`test_replacing_keeps_the_old_version_readable`).
- **A unit whose manager already breaks the rule** (data from before the guard) can still be edited on other fields; only naming or restoring a manager is checked. Test in Task 6 (`test_an_existing_violation_does_not_block_unrelated_edits`).

---

### Task 1: The module, the permissions, mPDF and the print workspace

**Files:**
- Modify: `composer.json`, `composer.lock` (`composer require mpdf/mpdf:^8.3`)
- Create: `config/documents.php`
- Create: `app/Modules/Documents/routes.php` (the `auth:sanctum` + `audit` group, empty for now)
- Create: `app/Modules/Documents/Pdf/PdfWorkspace.php`, `app/Modules/Documents/Pdf/PdfSession.php`
- Modify: `database/seeders/PermissionSeeder.php` (`RESOURCES['documents']`, `LABELS`)
- Modify: `app/Providers/AppServiceProvider.php` (the two Gate abilities)
- Test: `tests/Feature/Documents/PdfWorkspaceTest.php`, `tests/Feature/Documents/DocumentsPermissionsTest.php`

**Interfaces:**
- Produces: `PdfWorkspace::run(callable(PdfSession): T $work): T`; `PdfSession::mpdf(array $config = []): \Mpdf\Mpdf` (the Global Constraints' settings, with `tempDir` = the session folder; `$config` overrides); `PdfSession::path(string $name): string` (an absolute path inside the session folder); `PdfSession::$directory` (absolute). `config/documents.php` keys: `pdf.repair.driver`, `pdf.repair.binary`, `pdf.repair.timeout`. Gate abilities `documents.stamps.manage`, `documents.signatures.delete`.

- [ ] **Step 1: Write the failing tests**

`PdfWorkspaceTest` (`Storage::fake('local')`):
- `test_a_print_runs_in_its_own_folder_that_is_gone_afterwards`: inside `run()`, `is_dir($session->directory)` is true, and it lies under `Storage::disk('local')->path('pdf-work')`. After `run()` returns, it no longer exists. `run()` returns the callback's value.
- `test_the_folder_is_removed_when_the_print_fails`: the callback writes a file through `$session->path('x.png')` and throws `RuntimeException`. The exception propagates, and the folder is gone.
- `test_mpdf_prints_arabic_in_the_session_folder`: `$m = $session->mpdf()`, `WriteHTML('<p dir="rtl">قرأت ووافقت</p>')`, then `Output('', 'S')` starts with `%PDF-`. The session folder now holds mPDF's `ttfontdata` cache, which proves `tempDir` points there.
- `test_two_prints_do_not_share_a_folder`: nested or sequential `run()` calls see different `directory` values.

`DocumentsPermissionsTest`:
- `test_the_two_document_permissions_are_seeded_with_their_labels`: after `seedRbac()`, `Permission::where('name', 'documents.view')->value('label')` is «عرض جميع المستندات», and the same for `documents.templates.manage`.
- `test_system_administrator_abilities_pass_only_for_the_super_admin`: `actingAsAdmin()` makes `Gate::allows('documents.stamps.manage')` and `documents.signatures.delete` true. `actingAsUserWithPermissions(Permission::pluck('name')->all())` (every permission) makes both false.

- [ ] **Step 2: Run them and see them fail**

Run: `php artisan test tests/Feature/Documents`
Expected: FAIL (classes, permissions and abilities missing).

- [ ] **Step 3: Install mPDF and implement**

`composer require mpdf/mpdf:^8.3`. `PdfWorkspace::run()`: make `pdf-work/{Str::uuid()}` with `Storage::disk('local')->makeDirectory()`, build `PdfSession` with the absolute path, call `$work`, and `deleteDirectory()` in `finally`. Seeder: `'documents' => ['documents.view', 'documents.templates.manage']` with the exact labels and a comment that no seeded role carries them. AppServiceProvider, next to `Gate::before`: `Gate::define('documents.stamps.manage', static fn (): bool => false)` and the same for `documents.signatures.delete`, with a comment that these are the round's "system administrator only" rights (D15, D23.1, §19 Q3) and must never become permissions. `config/documents.php` per the Global Constraints.

- [ ] **Step 4: Run the tests and the gates**

Run: `php artisan test tests/Feature/Documents && vendor/bin/pint --test`
Expected: PASS, and Pint clean.

- [ ] **Step 5: Stop for review**

---

### Task 2: The upload check, with conversion

**Files:**
- Create: `app/Modules/Documents/Pdf/PdfIntake.php`, `app/Modules/Documents/Pdf/AcceptedPdf.php`, `app/Modules/Documents/Enums/IntakeOutcome.php`
- Create: `app/Modules/Documents/Pdf/Repair/PdfRepairer.php` (interface), `QpdfRepairer.php`, `GhostscriptRepairer.php`, `NullRepairer.php`
- Create: `app/Modules/Documents/Exceptions/UnreadablePdfException.php`, `UnsupportedUploadException.php` (both `DomainException`, 422, the exact copy)
- Modify: `app/Providers/AppServiceProvider.php` (bind `PdfRepairer` from `config('documents.pdf.repair.driver')`)
- Create: `tests/Support/Pdfs.php`
- Test: `tests/Feature/Documents/PdfIntakeTest.php`, `tests/Feature/Documents/PdfRepairerTest.php`

**Interfaces:**
- Consumes: `PdfWorkspace`, `PdfSession` (Task 1).
- Produces:
  - `PdfIntake::accept(UploadedFile $file, PdfSession $session): AcceptedPdf`
  - `PdfIntake::pageCount(string $path, PdfSession $session): ?int` (null when FPDI cannot open it)
  - `PdfIntake::MAX_KB = 20480`
  - `AcceptedPdf` (readonly): `printablePath` (inside the session), `?uploadedPath` (the file as uploaded, set only when Repaired), `pageCount`, `outcome`
  - `IntakeOutcome`: AsUploaded = 1, Repaired = 2, FromPhoto = 3
  - `PdfRepairer::repair(string $source, string $target): bool`
  - `Tests\Support\Pdfs::plain(): string`, `Pdfs::withXrefStream(): string`

- [ ] **Step 1: Write the fixture helper**

`Tests\Support\Pdfs::plain()` returns `(new Mpdf(['tempDir' => sys_get_temp_dir()]))` output for `<p>نص</p>`. `Pdfs::withXrefStream()` returns a one-page PDF 1.5 whose cross-reference is an **uncompressed xref stream**, which FPDI's free parser refuses with `CrossReferenceException` code `0x010B` (checked against mPDF 8.3.1 / FPDI 2.6.6 on 2026-11-22). The bytes are not derivable from a signature, so here they are:

```php
public static function withXrefStream(): string
{
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] >>',
    ];
    $pdf = "%PDF-1.5\n";
    $offsets = [];
    foreach ($objects as $number => $body) {
        $offsets[$number] = strlen($pdf);
        $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
    }
    $offsets[4] = strlen($pdf);
    $rows = pack('CNn', 0, 0, 65535);
    foreach ([1, 2, 3, 4] as $number) {
        $rows .= pack('CNn', 1, $offsets[$number], 0);
    }
    $pdf .= "4 0 obj\n<< /Type /XRef /Size 5 /W [1 4 2] /Root 1 0 R /Length ".strlen($rows)." >>\nstream\n{$rows}\nendstream\nendobj\n";

    return $pdf."startxref\n{$offsets[4]}\n%%EOF\n";
}
```

- [ ] **Step 2: Write the failing tests**

`PdfIntakeTest` (`Storage::fake('local')`; each test inside `PdfWorkspace::run()`; uploads via `UploadedFile::fake()->createWithContent()`):
- `test_a_readable_pdf_is_accepted_as_uploaded`: plain → outcome AsUploaded, pageCount 1, `uploadedPath` null, and the printable file's bytes equal the upload's.
- `test_a_pdf_with_an_xref_stream_is_repaired`: withXrefStream, with a fake repairer bound that writes `Pdfs::plain()` to `$target` and returns true → outcome Repaired, pageCount 1, `uploadedPath` holds the original bytes, and the printable path opens.
- `test_a_pdf_that_stays_unreadable_after_repair_is_refused`: the fake repairer returns false → `UnreadablePdfException` with the exact message. The same when it "succeeds" but writes the xref-stream file again.
- `test_a_photo_becomes_a_one_page_pdf`: `UploadedFile::fake()->image('p.jpg', 1200, 1600)` and a PNG → outcome FromPhoto, pageCount 1, bytes start `%PDF-`.
- `test_anything_else_is_refused`: a `.docx` (mime `application/vnd.openxmlformats-officedocument.wordprocessingml.document`) and a `.txt` → `UnsupportedUploadException` with «يُرجى رفع الملف بصيغة PDF أو صورة.»
- `test_the_check_and_repair_leave_nothing_outside_the_session`: after `run()`, `Storage::disk('local')->allFiles()` is empty.

`PdfRepairerTest`:
- `test_qpdf_is_called_with_object_streams_disabled`: `Process::fake()` → `QpdfRepairer::repair('in.pdf', 'out.pdf')` is true, and `Process::assertRan()` with exactly `['qpdf', '--object-streams=disable', 'in.pdf', 'out.pdf']`.
- `test_qpdf_warnings_still_succeed`: exit code 3 → true. Exit code 2 → false.
- `test_qpdf_missing_counts_as_a_failed_repair`: exit code 127 → false and `Log::warning` written. A process that throws → false.
- `test_ghostscript_rewrites_to_pdf_1_4`: the exact Ghostscript argument list from the Global Constraints.
- `test_the_driver_is_chosen_from_config`: `config(['documents.pdf.repair.driver' => 'none'])` resolves `NullRepairer` (always false); `ghostscript` resolves `GhostscriptRepairer`; `qpdf` resolves `QpdfRepairer`.
- `test_a_real_qpdf_repairs_the_xref_stream_fixture`: `markTestSkipped` unless `qpdf --version` runs. With it, the repaired file opens via `pageCount()`.

- [ ] **Step 3: Run them and see them fail**

Run: `php artisan test tests/Feature/Documents/PdfIntakeTest.php tests/Feature/Documents/PdfRepairerTest.php`
Expected: FAIL.

- [ ] **Step 4: Implement**

- **`pageCount()`**: a fresh `$session->mpdf()` and `setSourceFile($path)`. Catch `\setasign\Fpdi\PdfParser\PdfParserException` (the parent of `CrossReferenceException`) and return null.
- **`accept()`**: dispatch on `$file->getMimeType()`.
  - `application/pdf`: copy to `$session->path('upload.pdf')` and count its pages. When the count is null, repair to `$session->path('repaired.pdf')` and count again. Still null means `UnreadablePdfException`.
  - `image/jpeg` and `image/png`: an mPDF page with the image fitted within 10 mm margins, centred, aspect kept, written to `printable.pdf`.
  - Anything else: `UnsupportedUploadException`.
- **Repairers**: `Process::timeout(config('documents.pdf.repair.timeout'))->run([...])`. Wrap in `try`, so a throw means `Log::warning(...)` and false.
- **Binding**: a `match` on the driver.

- [ ] **Step 5: Run the tests and the gates**

Run: `php artisan test tests/Feature/Documents && vendor/bin/pint --test`
Expected: PASS (the real-qpdf test skipped where qpdf is absent), Pint clean.

- [ ] **Step 6: Stop for review**

---

### Task 3: Sealed images and employees' signatures

**Files:**
- Create: `app/Modules/Documents/Services/SealedImages.php`
- Create: `database/migrations/Documents/2026_11_22_100000_create_employee_signatures_table.php`
- Create: `app/Modules/Documents/Models/EmployeeSignature.php`, `app/Modules/Documents/Enums/SignatureSource.php`
- Create: `app/Modules/Documents/Services/Signatures.php`
- Create: `app/Modules/Documents/Exceptions/SignatureExistsException.php` (409), `NoSignatureException.php` (404)
- Create: `app/Modules/Documents/Controllers/MySignatureController.php`, `EmployeeSignatureController.php`
- Create: `app/Modules/Documents/Requests/MySignatureRequest.php`, `DeleteSignatureRequest.php`
- Modify: `app/Modules/Me/routes.php` (`POST /signature`, `GET /signature`)
- Modify: `app/Modules/HR/routes.php` (`GET` and `DELETE employees/{employee}/signature`, both `can:documents.signatures.delete`)
- Modify: `app/Providers/AppServiceProvider.php`, `app/Modules/Auth/Resources/AuditTargetStub.php`, `app/Modules/Auth/Resources/AuditLogDetailResource.php` (alias `employee_signature`)
- Test: `tests/Feature/Documents/SealedImagesTest.php`, `tests/Feature/Documents/SignatureTest.php`

**Interfaces:**
- Produces:
  - `SealedImages::put(string $bytes, string $directory): string` (path `sealed/{directory}/{uuid}.bin` on `local`)
  - `SealedImages::get(string $path): string`
  - `SealedImages::delete(string $path): void`
  - `Signatures::current(Employee $employee): ?EmployeeSignature`
  - `Signatures::draw(Employee $employee, UploadedFile $png, SignatureSource $on, User $by): EmployeeSignature`
  - `Signatures::bytes(EmployeeSignature $signature): string`
  - `Signatures::delete(Employee $employee, User $by, string $reason): EmployeeSignature`
  - `SignatureSource`: App = 1, Dashboard = 2
  - Table `employee_signatures`:
    - `employee_id` (FK, restrict on delete)
    - `path` string(512), `drawn_on` tinyint
    - `deleted_by` (FK `users`, null on delete), `delete_reason` string(500), both nullable
    - timestamps, soft deletes, index `employee_id`

- [ ] **Step 1: Write the failing tests**

`SealedImagesTest` (`Storage::fake('local')`):
- `test_an_image_is_stored_encrypted_and_reads_back`: put a PNG's bytes; the raw stored file does not contain `"\x89PNG"`; `get()` returns the bytes exactly.
- `test_a_tampered_file_does_not_decrypt`: alter one character of the stored file and `get()` throws `DecryptException`.

`SignatureTest` (an employee with a linked account, `Storage::fake('local')`; a real PNG from `UploadedFile::fake()->image('s.png', 600, 200)`):
- `test_an_employee_draws_their_signature`: `POST /api/me/signature` with `signature` and `drawn_on=1` returns 201 with message «تم حفظ توقيعك.» and `data.drawn_at`. One row, `drawn_on` App, the file sealed.
- `test_the_owner_sees_their_own_signature`: `GET /api/me/signature` streams the PNG's exact bytes with `no-store`. With none, it is 404 «لا يوجد توقيع محفوظ.»
- `test_a_signature_cannot_be_replaced_by_its_owner`: a second POST is 409 with «لديك توقيع محفوظ، ولا يمكن تغييره. لحذفه راجع مدير النظام.», still one row, and no second file on disk. `DELETE /api/me/signature` is 405.
- `test_only_a_real_png_within_512_kb_is_taken`: a JPG, a 600 KB PNG, and a `.png` whose content is `Pdfs::plain()` are each 422 with «التوقيع يجب أن يكون صورة PNG لا يتجاوز حجمها 512 كيلوبايت.», and no file is left.
- `test_the_system_administrator_deletes_a_signature_with_a_reason`: `actingAsAdmin()`, then `DELETE /api/hr/employees/{id}/signature` with a reason returns 200 «تم حذف التوقيع.». The row is soft-deleted with `deleted_by` and `delete_reason`, and its file is still on disk (kept, spec §10.1). The owner's next POST is 201, and `withTrashed()` counts 2.
- `test_a_reason_is_required`: a missing or 2-character reason is 422 «سبب الحذف مطلوب، من 3 إلى 500 حرف.»
- `test_nobody_else_deletes_or_reads_a_signature`: a user holding every permission (HR's included) gets 403 on both `GET` and `DELETE /api/hr/employees/{id}/signature`. There is no route that streams another person's image.
- `test_the_administrator_sees_the_history_without_the_image`: `GET /api/hr/employees/{id}/signature` lists the current and deleted versions (`drawn_at`, `drawn_on`, `deleted_at`, `delete_reason`) and no path or bytes.
- `test_an_account_without_an_employee_cannot_draw`: it gets the existing `NoLinkedEmployeeException` response.
- `test_deleting_when_there_is_none_is_404`: the administrator's `DELETE` on an employee with no live signature is 404 «لا يوجد توقيع محفوظ.»

- [ ] **Step 2: Run them and see them fail**

Run: `php artisan test tests/Feature/Documents/SealedImagesTest.php tests/Feature/Documents/SignatureTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

- **`SealedImages`**: `Crypt::encrypt($bytes, false)` written with `Storage::disk('local')->put()`.
- **`Signatures::draw()`**: seal first, then a transaction that locks the employee row (as `ProfilePhotos::replace` does), refuses when `current()` exists, and creates the row. On any throw, delete the sealed file and rethrow.
- **`MySignatureRequest`**: `required|file|mimes:png|max:512` plus a check that `getimagesize()` reports `IMAGETYPE_PNG`, all mapped to the one message. `drawn_on` is `required|in:1,2`.
- **Controllers**: use `SelfEmployee` for the owner and `ApiRespond` for envelopes.

- [ ] **Step 4: Run the tests and the gates**

Run: `php artisan test && vendor/bin/pint --test`
Expected: PASS, including `MorphMapTest` and `AuditTargetStubTest`. Pint clean.

- [ ] **Step 5: Stop for review**

---

### Task 4: Units' stamps

**Files:**
- Create: `database/migrations/Documents/2026_11_22_110000_create_unit_stamps_table.php`
- Create: `app/Modules/Documents/Models/UnitStamp.php`, `app/Modules/Documents/Services/UnitStamps.php`
- Create: `app/Modules/Documents/Exceptions/NoStampException.php` (404)
- Create: `app/Modules/Documents/Controllers/UnitStampController.php`, `app/Modules/Documents/Requests/UnitStampRequest.php`
- Modify: `app/Modules/Documents/routes.php`:
  - `GET /stamps`
  - `POST /stamps/{department}`
  - `GET /stamps/{department}/image`
  - all `can:documents.stamps.manage`, `whereNumber('department')`
- Modify: `AppServiceProvider`, `AuditTargetStub`, `AuditLogDetailResource` (alias `unit_stamp`)
- Test: `tests/Feature/Documents/UnitStampTest.php`

**Interfaces:**
- Consumes: `SealedImages` (Task 3).
- Produces:
  - `UnitStamps::current(Department $unit): ?UnitStamp`
  - `UnitStamps::replace(Department $unit, UploadedFile $image, User $by): UnitStamp`
  - `UnitStamps::bytes(UnitStamp $stamp): string`
  - `UnitStamps::unitsWithout(): Collection<int, Department>`
  - Table `unit_stamps`:
    - `department_id` (FK, restrict on delete)
    - `path` string(512), `mime_type` string(32)
    - `uploaded_by` (FK `users`, null on delete)
    - `replaced_at` nullable (null = current); timestamps
    - index (`department_id`, `replaced_at`)
    - **no soft deletes**: a version is never deleted (spec §10.2)

- [ ] **Step 1: Write the failing tests**

`UnitStampTest` (a root, a division, a section; `Storage::fake('local')`):
- `test_the_system_administrator_uploads_a_units_stamp`: `POST /api/documents/stamps/{section}` with a PNG returns 201 «تم حفظ ختم الوحدة.». One current row, sealed.
- `test_replacing_keeps_the_old_version_readable`: a second upload. The old row has `replaced_at` set, and `UnitStamps::bytes($old)` still returns the first image. Exactly one row has a null `replaced_at`.
- `test_the_image_is_streamed_to_the_administrator_only`: `GET .../image` streams the current bytes with `no-store`. With none, it is 404 «لم يُرفع ختم لهذه الوحدة بعد.»
- `test_the_list_shows_every_unit_and_which_lack_a_stamp`: `GET /api/documents/stamps` lists every non-deleted unit with `has_stamp` and `stamp_uploaded_at`. `?missing=1` returns only those without one.
- `test_only_png_or_jpg_within_2_mb`: a PDF, a 3 MB PNG, and a fake `.png` are each 422 with «الختم يجب أن يكون صورة PNG أو JPG لا يتجاوز حجمها 2 ميغابايت.», and nothing is left on disk.
- `test_a_deleted_unit_takes_no_stamp`: a soft-deleted unit is 404 on all three routes (the default binding excludes trashed rows).
- `test_nobody_but_the_system_administrator`: every permission (`departments.*` included) gets 403 on all three routes.

- [ ] **Step 2: Run them and see them fail**

Run: `php artisan test tests/Feature/Documents/UnitStampTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

`replace()`: seal first, then a transaction that locks the department row, sets `replaced_at = now()` on the current version, and creates the new one. On a throw, delete the sealed file. The request: `required|file|mimes:png,jpg,jpeg|max:2048` plus `getimagesize()` reporting PNG or JPEG.

- [ ] **Step 4: Run the tests and the gates**

Run: `php artisan test && vendor/bin/pint --test`
Expected: PASS, Pint clean.

- [ ] **Step 5: Stop for review**

---

### Task 5: The letterhead

**Files:**
- Create: `database/migrations/Documents/2026_11_22_120000_create_document_letterheads_table.php`
- Create: `app/Modules/Documents/Models/DocumentLetterhead.php`, `app/Modules/Documents/Enums/LetterheadPart.php`, `app/Modules/Documents/Services/Letterhead.php`
- Create: `app/Modules/Documents/Controllers/LetterheadController.php`, `app/Modules/Documents/Requests/LetterheadImageRequest.php`
- Modify: `app/Modules/Documents/routes.php`:
  - `GET /letterhead`
  - `POST /letterhead/{part}`
  - `GET /letterhead/{part}/image`
  - `DELETE /letterhead/{part}`
  - all `permission:documents.templates.manage`, `{part}` constrained to `header|footer`
- Modify: `AppServiceProvider`, `AuditTargetStub`, `AuditLogDetailResource` (alias `document_letterhead`)
- Test: `tests/Feature/Documents/LetterheadTest.php`

**Interfaces:**
- Produces:
  - `LetterheadPart`: string-backed, Header = `header`, Footer = `footer`
  - `Letterhead::current(): DocumentLetterhead` (the single row, created on first use)
  - `Letterhead::replace(LetterheadPart $part, UploadedFile $image, User $by): DocumentLetterhead`
  - `Letterhead::remove(LetterheadPart $part, User $by): DocumentLetterhead`
  - `Letterhead::pathOf(LetterheadPart $part): ?string` (the local-disk path for piece 3's printing)
  - Table `document_letterheads`:
    - `header_path`, `footer_path`: string(512), nullable
    - `updated_by` (FK `users`, null on delete)
    - timestamps
  - The images are **not** encrypted: a letterhead signs nothing (spec §8.2).

- [ ] **Step 1: Write the failing tests**

`LetterheadTest` (`actingAsUserWithPermissions(['documents.templates.manage'])`, `Storage::fake('local')`):
- `test_the_header_and_footer_are_uploaded_and_read`: `POST /api/documents/letterhead/header` returns 201 «تم حفظ الترويسة.». `GET /api/documents/letterhead` shows `header.uploaded_at` and a null `footer`. `GET .../header/image` streams the bytes.
- `test_a_new_image_replaces_the_old_file`: after a second upload, the old file is gone from disk and one row remains.
- `test_removing_a_part`: `DELETE .../footer` returns 200 «تم حذف الترويسة.», the file is gone, and `GET .../footer/image` is 404 «لم تُرفع هذه الصورة بعد.»
- `test_only_png_or_jpg_within_2_mb`: 422 «الصورة يجب أن تكون PNG أو JPG لا يتجاوز حجمها 2 ميغابايت.»
- `test_an_unknown_part_is_404` (`/letterhead/body`).
- `test_without_the_permission_it_is_403`: `documents.view` alone gets 403 on all four routes.

- [ ] **Step 2: Run them and see them fail**

Run: `php artisan test tests/Feature/Documents/LetterheadTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

Files go to `documents/letterhead/{uuid}.{ext}` on `local`. Replace the old file after the row's update commits, as `ProfilePhotos::replace` does.

- [ ] **Step 4: Run the tests and the gates**

Run: `php artisan test && vendor/bin/pint --test`
Expected: PASS, Pint clean.

- [ ] **Step 5: Stop for review**

---

### Task 6: One unit per manager

**Files:**
- Create: `app/Modules/Departments/Exceptions/ManagerOfAnotherUnitException.php` (`DomainException`, 422)
- Modify: `app/Modules/Departments/Models/Department.php` (`booted()`: a check in `saving` and in `restoring`)
- Create: `app/Console/Commands/ListManagersOfSeveralUnits.php` (`departments:managers-of-several`)
- Test: `tests/Feature/Departments/OneUnitPerManagerTest.php`

**Interfaces:**
- Produces:
  - `Department::otherUnitManagedBy(int $employeeId, ?int $exceptId): ?Department` (static; a non-deleted unit, active or not)
  - The artisan command, which exits 1 when any person manages more than one unit and 0 otherwise

- [ ] **Step 1: Write the failing tests**

`OneUnitPerManagerTest` (`actingAsAdmin()`; a root, two divisions A and B, one employee E):
- `test_naming_the_manager_of_another_unit_is_refused`: E manages A. `PATCH /api/departments/{B}` with `manager_id = E` is 422 with «هذا الموظف مدير لوحدة «A» بالفعل، ولا يدير الموظف أكثر من وحدة واحدة.» (A's name), and B's `manager_id` is unchanged.
- `test_creating_a_unit_with_such_a_manager_is_refused`: `POST /api/departments` with `manager_id = E` gives the same 422.
- `test_an_inactive_unit_still_counts`: A is `is_active = false` and still has E, so naming E on B is refused.
- `test_moving_a_manager_works_once_the_old_post_is_cleared`: clear A's manager, then name E on B, which is 200.
- `test_an_existing_violation_does_not_block_unrelated_edits`: E is set on both A and B through `DB::table('departments')->update(...)`, bypassing the guard. Renaming B through the API is 200. Re-saving B with `manager_id = E` unchanged is 200.
- `test_restoring_a_unit_whose_manager_now_manages_another_is_refused`: A is soft-deleted with E, then E is named on B. `A->restore()` throws `ManagerOfAnotherUnitException`.
- `test_the_command_lists_people_managing_several_units`: with the DB-bypassed violation, `artisan departments:managers-of-several` exits 1, and its output names E and both units. With none, it exits 0 with «لا يوجد موظف يدير أكثر من وحدة.»

- [ ] **Step 2: Run them and see them fail**

Run: `php artisan test tests/Feature/Departments/OneUnitPerManagerTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

- **`saving`**: when `manager_id` is non-null and `isDirty('manager_id')`, look up `otherUnitManagedBy()` and throw with that unit's name.
- **`restoring`**: the same check with no dirty condition.
- **The command**: group non-deleted units by `manager_id` having count > 1, then print a table (employee id, name, units).
- **No change to the employee import**: it names no managers (spec §13).

- [ ] **Step 4: Run the tests and the gates**

Run: `php artisan test && vendor/bin/pint --test`
Expected: PASS, Pint clean. The existing leave, approvals and `ManagementChain` tests must pass unchanged: the guard touches no file they test.

- [ ] **Step 5: Stop for review**

---

### Task 7: The API documentation, Postman and the deployment note

**Files:**
- Modify: `docs/API_DOCUMENTATION.md` (a new `## 20. Documents — foundations` section)
- Modify: `postman/Alraqi-ERP.postman_collection.json` (a "Documents — foundations" folder)
- Modify: `docs/EMPLOYEE_APP_V1_WORK_ORDER.md` (an §8 entry: piece 1 built, the qpdf requirement)

- [ ] **Step 1: Document every endpoint of Tasks 3–5** in the existing section style: method, path, who may call (a gate ability or a permission), the fields with their limits, each response and error with its exact Arabic copy. State that the two system-administrator rights are not permissions.
- [ ] **Step 2: Add the deployment requirement:** the production server needs `qpdf` (or Ghostscript with `DOCUMENTS_PDF_REPAIR=ghostscript`). Without it, a PDF the printer cannot open is refused at upload. Also add the `departments:managers-of-several` check to run before deploying, and the new env keys.
- [ ] **Step 3: Add the Postman requests** for every endpoint, following the shift-swap folder's shape. Validate the JSON with `php -r "json_decode(file_get_contents('postman/Alraqi-ERP.postman_collection.json'), flags: JSON_THROW_ON_ERROR);"`. Expected: no output.
- [ ] **Step 4: Run the gates once more**

Run: `php artisan test && vendor/bin/pint --test`
Expected: PASS, Pint clean.

- [ ] **Step 5: Stop.** Report to the owner, who commits.
