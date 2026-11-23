# Documents — piece 2, documents between managers: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Workflow 4 of the design, by upload. A manager uploads a document and names the managers who sign it in order. المدير العام signs last and decides. Every decision prints a card with the unit's stamp and the manager's signature. The parties see, comment on, and (before the first signature) delete the document.

**Architecture:** A `Document` model in `app/Modules/Documents` implements the engine's `Approvable`. It submits a sequential advisory route: the named managers, then the root unit's manager as the decisive step. Decisions go through the module's own endpoint: it checks the unit, the stamp, the signature and the confirmation, calls `ApprovalService::act()` inside a decision gate, records a mark, and rebuilds the printed copy, all in one transaction. An observer on the engine's `ApprovalStep` refuses any document decision made outside that gate, and any reassignment that breaks the rules. The approvals module gains exactly one thing, an optional visibility contract, so documents stay off the general approvals screens.

**Tech Stack:** Laravel 12, PHP 8.2, PHPUnit (sqlite in memory), Pint; mPDF 8.3 / FPDI (piece 1).

**Spec:** [`docs/superpowers/specs/2026-11-22-documents-design.md`](../specs/2026-11-22-documents-design.md) ("spec §n"): §1.4 (workflow 4), §5, §6.1–§6.3, §7, §10.3, §11.3, §11.4, §17 piece 2, §18, §19. It builds on piece 1: [`2026-11-22-documents-foundations.md`](2026-11-22-documents-foundations.md), commit `6ae8347`.

## Global Constraints

- **Prerequisite:** piece 1 is committed (`6ae8347`, branch `documents-foundations`). Branch `documents-between-managers` from it. If it is not there, stop and ask.
- **No commit, stage, reset, stash or push without the owner's explicit go-ahead.** Leave changes in the working tree; `git add -N` new files.
- Another agent may edit this tree: run `git status` and re-read a file before editing it.
- **Untouched:**
  - how the engine submits, decides, withdraws and reassigns;
  - `ManagementChain`;
  - the Leave and Scheduling modules.

  In `app/Modules/Approvals/`, only Task 5's three files change: a new contract, `ApprovalPolicy.php` and `ApprovalListController.php`. Every existing approvals, leave and swap test passes unchanged.
- **Workflow 4 only:** `kind` Internal, `origin` Uploaded. No template, صادر number or reference (piece 3). No recipient, sending or employee signature (piece 4). The columns those pieces need are created now, nullable, as spec §6.1 lists them.
- **The route** (spec §7.1):

  ```
  ApprovalRoute::sequential(Advisory, namedEmployee(a₁), …, namedEmployee(aₙ), namedEmployee(gm)->decisive())
  ```

  - `gm` is the manager of the root unit (level `GeneralAdministration`), read at submission.
  - It is a named step, not `unitManager(code)`: the root's `code` is nullable.
  - The creator names 1–10 approvers, in order (no parallel, §1.4).
- **Decision checks, in this order** (spec §7.2):
  1. it is the caller's turn;
  2. the caller manages a unit now;
  3. that unit has a current stamp;
  4. the caller has a current signature;
  5. the confirmation.

  Then one transaction: gate, `act()`, mark, print.
- **Confirmation** (spec §10.3): `confirmed_by` `1` biometric (recorded as the app reports it) or `2` password. A password is checked with `Hash::check` against the caller's account.
- **Who:**
  - Creating needs a unit's manager (spec §5).
  - Approvers are unit managers, active, not the creator and not المدير العام.
  - Seeing a document needs one of: being its creator, holding any step on it, or `documents.view`.
  - Deleting is for the creator or the system administrator, before any approval or rejection.
  - Reassigning a document step is for the system administrator only.
- **Files** (private `local` disk, streamed with `Cache-Control: private, no-store`):
  - `documents/{folder}/original.pdf`, and `documents/{folder}/uploaded.pdf` when the intake repaired the file;
  - `documents/{folder}/printed/{uuid}.pdf`;
  - `{folder}` is a UUID chosen at creation;
  - supporting attachments go through `AttachmentStore` (attachable = the document).
- **Limits:**
  - the file: `PdfIntake::MAX_KB` (20480), PDF/JPG/JPEG/PNG;
  - attachments: up to 10, each ≤ 20480 KB, `pdf,jpg,jpeg,png,doc,docx,xls,xlsx`;
  - approvers: 1–10;
  - text lengths: title ≤ 255, description ≤ 2000, note ≤ 500, comment ≤ 2000;
  - `import_number` (وارد): integer 1–999999999, unique within the creator's register (spec §8.4: the إدارة).
- **Card geometry** (legacy, spec §11.4), all in mm:
  - card 45 × 25, 5 apart, block 6 from the bottom of the last page;
  - rows centred, the first mark at the right;
  - a new page when the block's top would be above 55% of the page height;
  - stamp 14 × 14, signature 14 × 8.

  Card colours:

  | Card | Fill | Border |
  |---|---|---|
  | Normal | `#FFFFFF` | `#969696` |
  | Rejection | `#FFEBEB` | `#C85050` |
  | Decider | `#F5FAFF` | `#3C64B4` |

  The note is cut to 120 characters. The date is `Y-m-d H:i`, Asia/Damascus. One line «رقم المستند: {id}» is printed under the block.
- **Every new model:** `HasAuditLog`, a morph alias, a label in `AuditTargetStub::TYPE_LABELS`, and `AuditLogDetailResource::TYPE_VIEW_PERMISSIONS`:

  | Alias | Label | Permissions |
  |---|---|---|
  | `document` | «مستند» | `['documents.view']` |
  | `document_mark` | «توقيع على مستند» | `['documents.view']` |
  | `document_comment` | «تعليق على مستند» | `['documents.view']` |
- **Notification types** (`NotificationEventCatalogue`, with labels):
  - `document.approved` «تمت الموافقة على المستند»
  - `document.rejected` «تم رفض المستند»
  - `document.rejected_by_manager` «رفض مدير المستند»
  - `document.stamp_missing` «ختم وحدة غير مرفوع»

  Approvers keep the engine's `approval.step_pending` / `approval.step_assigned`.
- **Arabic copy, exact:**
  - **Creating:**
    - «تم إرسال المستند للاعتماد.» (201)
    - «لا يُنشئ المستندات إلا مدير وحدة تنظيمية.» (403)
    - «لا يمكن اختيار «{name}» معتمداً لأنه لا يدير وحدة تنظيمية.»
    - «لا يمكنك اختيار نفسك معتمداً.»
    - «المدير العام يُضاف تلقائياً في آخر المسار، فلا تختره بين المعتمدين.»
    - «لا يوجد مدير عام في الهيكل التنظيمي، فلا يمكن إرسال المستند.»
    - «لا يمكن تكرار المعتمد نفسه.»
    - «اختر معتمداً واحداً على الأقل.»
    - «لا يزيد عدد المعتمدين على 10.»
    - «رقم الوارد {n} مستخدم في سجل هذه الإدارة.»
    - «عنوان المستند مطلوب.»
    - «الملف مطلوب.»
    - «يُرجى رفع الملف بصيغة PDF أو صورة لا يتجاوز حجمه 20 ميغابايت.»
    - «لا تزيد المرفقات على 10.»
    - «المرفق يجب أن يكون PDF أو صورة أو ملف Word أو Excel لا يتجاوز 20 ميغابايت.»
  - **Deciding:**
    - «تمت الموافقة.» / «تم الرفض.» (200)
    - «القرار يجب أن يكون موافقة أو رفضاً.»
    - «تأكيد الهوية مطلوب.»
    - «كلمة المرور مطلوبة للتأكيد.»
    - «كلمة المرور غير صحيحة.»
    - «لا تدير وحدة تنظيمية حالياً، فلا يمكنك التوقيع على المستندات. يُرجى مراجعة مدير النظام لإعادة إسناد الخطوة.»
    - «لم يُرفع الختم الرسمي لوحدتك «{unit}» بعد، ولا يمكن التوقيع قبل رفعه. تم إبلاغ مدير النظام.»
    - «لم ترسم توقيعك بعد. ارسمه ثم أعد المحاولة.» (**428**: the client opens the pad)
  - **The guard:**
    - «يُتَّخذ القرار في المستندات من شاشة المستند.» (422)
    - «إعادة إسناد خطوات المستندات لمدير النظام فقط.» (403)
    - «لا يمكن الإسناد إلا إلى مدير وحدة تنظيمية.»
    - «خطوة القرار الأخير لا تُسند إلا إلى المدير العام الحالي.»
  - **Viewing:**
    - «حُذف هذا المستند.» (410)
    - «لا تملك صلاحية عرض جميع المستندات.» (403)
  - **Comments:**
    - «تمت إضافة التعليق.» (201)
    - «نص التعليق مطلوب.»
  - **Deleting:**
    - «تم حذف المستند.» (200)
    - «لا يمكن حذف مستند بعد أول توقيع عليه.» (422)
  - **Notifications** (title / body):
    - The first approvers: «مستند جديد بانتظار موافقتك» / «يرجى مراجعة: {title}».
    - Each next approver: «دورك للمراجعة» / «يرجى مراجعة: {title}».
    - The decider's turn: «جميع المدراء وافقوا — قرارك مطلوب» / «يرجى مراجعة: {title}». This is legacy's wording, I.6.
    - A reassignment to the current step: «تم إسنادك لاعتماد مستند» / «المستند «{title}» بانتظار مراجعتك الآن.»
    - A reassignment to a later step: «تم إسنادك لاعتماد مستند» / «أصبحت ضمن مسار اعتماد المستند «{title}».»
    - To the creator, approved: «تمت الموافقة على مستندك» / «{title}».
    - To the creator, rejected: «تم رفض مستندك» / «{title}».
    - To the creator, a non-final rejection: «تم رفض المستند من مدير» / «رفض {name} المستند «{title}»، وما زال المستند في مساره.»
    - To the system administrators: «ختم وحدة غير مرفوع» / «حاول {name} التوقيع باسم «{unit}» ولا ختم لها بعد.»
- **Gates:** `php artisan test` and `vendor/bin/pint --test`, both clean at the end of every task.

## Review Focus

- **A double tap on approve** (two requests at once, or a retry after a slow response) must record one decision, one mark and one printed copy. The second gets the engine's «ليس دورك في اعتماد هذا الطلب.». Test in Task 7 (`test_deciding_twice_records_one_mark`).
- **A stamp replaced between two decisions** on one document: the first card keeps printing the old version, even when the new version's file is gone. Test in Task 3 (`test_a_rebuild_prints_each_marks_own_stamp_version`).
- **The longest route:** 10 named managers and المدير العام make 11 cards. They print in 3 rows, the first decision at the right, and the copy opens. Test in Task 3 (`test_eleven_cards_print`).
- **Deleting races approving:** once one step is decided, deletion is refused, and no deletion is half-done. Test in Task 10 (`test_a_signed_document_cannot_be_deleted`).
- **Someone off the document** gets 403 from every document route: show, file, comments, attachments, decision. A party opening a deleted document gets 410. Test in Task 8 (`test_nobody_off_the_document_reads_it`) and Task 10 (`test_a_deleted_document_answers_410_to_its_parties`).

---

### Task 1: The tables, the models, the registrations

**Files:**
- Create: `database/migrations/Documents/2026_11_23_100000_create_documents_table.php`, `…_100100_create_document_marks_table.php`, `…_100200_create_document_comments_table.php`
- Create: `app/Modules/Documents/Enums/DocumentKind.php`, `DocumentOrigin.php`, `DocumentStatus.php`, `MarkKind.php`, `Confirmation.php`
- Create: `app/Modules/Documents/Models/Document.php`, `DocumentMark.php`, `DocumentComment.php`
- Modify: `AppServiceProvider` (aliases), `AuditTargetStub`, `AuditLogDetailResource`, `NotificationEventCatalogue` (the four types)
- Test: `tests/Feature/Documents/DocumentModelTest.php`

**Interfaces:**
- Produces:
  - `documents`: spec §6.1 exactly, plus `uploaded_path` string(512) nullable and `folder` uuid.
    - Columns: `kind`, `origin`, `title`, `description`, `creator_id` (FK employees, restrict), `creator_unit_id` (FK departments, restrict), `register_unit_id` (FK departments, restrict), `created_by` (FK users, null on delete);
    - `template_id` / `template_version` / `field_values` / `export_number` / `reference` / `recipient_id` / `filing_type` / `sent_at` / `signed_at` / `refused_at` / `refusal_reason` / `legacy_id` / `legacy` (all nullable);
    - `import_number`, `status`, `decided_at`, `original_path`, `printed_path`, `deleted_by`;
    - timestamps, soft deletes.
    - Uniques (`register_unit_id`, `import_number`) and (`register_unit_id`, `export_number`).
  - `document_marks`: spec §6.2, no soft deletes.
  - `document_comments`: `document_id`, `employee_id`, `body` text, timestamps, soft deletes.
  - Enums:
    - `DocumentKind` (Internal 1, ToEmployee 2);
    - `DocumentOrigin` (Uploaded 1, Template 2, Legacy 3);
    - `DocumentStatus` (Pending 1, Approved 2, Rejected 3, AwaitingSignature 4, Signed 5, Refused 6), with `label()` «قيد الاعتماد» «معتمد» «مرفوض» «بانتظار التوقيع» «موقّع» «مرفوض من الموظف»;
    - `MarkKind` (ApprovalCard 1, RejectionCard 2, EmployeeSignature 3);
    - `Confirmation` (Biometric 1, Password 2).
  - `Document` relations: `creator()`, `creatorUnit()`, `registerUnit()`, `approval()` (morphOne `Approval`, 'approvable'), `marks()` (by `position`), `comments()`, `attachments()` (morphMany `Attachment`, by id).
  - `DocumentMark` relations: `stamp()` → `UnitStamp`, `signature()` → `EmployeeSignature` (withTrashed).

- [ ] **Step 1: Write the failing test**

`DocumentModelTest` (RefreshDatabase): `test_a_document_round_trips_its_enums_and_relations`. Create one document by hand with each relation's row, then assert:
- `kind`, `origin` and `status` come back as their enums;
- `marks()` orders by `position`;
- a mark's `signature` still resolves after the signature is soft-deleted.

Then `test_the_new_types_are_registered`:
- the three aliases are in `Relation::morphMap()`;
- `NotificationEventCatalogue::labelOf('document.rejected_by_manager')` is «رفض مدير المستند».

- [ ] **Step 2: Run it and see it fail** — `php artisan test tests/Feature/Documents/DocumentModelTest.php` → FAIL (no tables).
- [ ] **Step 3: Implement** the migrations, enums, models and registrations above. `MorphMapTest` and `AuditTargetStubTest` enforce the registrations.
- [ ] **Step 4: Run the tests and the gates** — `php artisan test && vendor/bin/pint --test` → PASS, Pint clean.
- [ ] **Step 5: Stop for review**

---

### Task 2: Units and managers

**Files:**
- Create: `app/Modules/Documents/Services/UnitManagers.php`
- Test: `tests/Feature/Documents/UnitManagersTest.php`

**Interfaces:**
- Produces:
  - `UnitManagers::unitOf(Employee $employee): ?Department`: the non-deleted unit they manage (`Department::otherUnitManagedBy($id, null)`).
  - `UnitManagers::generalManager(): ?Employee`: the manager of the root (level `GeneralAdministration`), or null when the post is vacant.
  - `UnitManagers::registerOf(Department $unit): Department`: the unit itself at level ≤ `Division`, else the nearest `Division` ancestor (spec §4, §8.4).

- [ ] **Step 1: Write the failing tests**

Build a root, a division, a section and an office, each with its own manager. Then:
- `test_a_manager_s_unit_is_found`: also, a non-manager gives null.
- `test_the_general_manager_is_the_root_s_manager`: also null when the post is vacant.
- `test_the_register_is_the_unit_s_idara`: office → division, section → division, division → itself, root → root.

- [ ] **Step 2: Run them and see them fail.**
- [ ] **Step 3: Implement.** Use `Department::loadAncestors()` for the walk.
- [ ] **Step 4: Run the tests and the gates.**
- [ ] **Step 5: Stop for review**

---

### Task 3: The cards and the printed copy

**Files:**
- Create: `app/Modules/Documents/Pdf/CardLayout.php`, `app/Modules/Documents/Pdf/CardPlacement.php`, `app/Modules/Documents/Pdf/DocumentPrinter.php`, `resources/views/documents/card.blade.php`
- Test: `tests/Feature/Documents/CardLayoutTest.php`, `tests/Feature/Documents/DocumentPrinterTest.php`

**Interfaces:**
- Consumes:
  - `PdfWorkspace`, `PdfSession` (piece 1);
  - `UnitStamps::bytes()`, `Signatures::bytes()` (piece 1);
  - `DocumentMark` (Task 1).
- Produces:
  - `CardLayout::place(int $count, float $pageWidth, float $pageHeight): CardPlacement`;
  - `CardPlacement` (readonly): `bool $newPage`, `array<int, array{x: float, y: float}> $positions` (mark order), `float $blockBottom`;
  - `DocumentPrinter::print(Document $document): string`: writes a new printed copy, built from `original_path` plus every mark in `position` order, to `documents/{folder}/printed/{uuid}.pdf`, and returns that path. It never touches `printed_path` and never deletes a file.

The geometry is legacy's (`../Raqi-App/app/Services/DocumentStampService.php`):

```php
$perRow  = max(1, (int) floor(($pageWidth + 5) / (45 + 5)));
$rows    = (int) ceil($count / $perRow);
$height  = $rows * 25 + max(0, $rows - 1) * 5;
$top     = $pageHeight - 6 - $height;
$newPage = $top < $pageHeight * 0.55;
// on a new page, $top is computed the same way on that page
// row r holds min($perRow, $count - r * $perRow) cards, centred:
//   $startX = ($pageWidth - ($inRow * 45 + ($inRow - 1) * 5)) / 2
//   card c of the row (c = 0 is the row's first mark) sits at
//   x = $startX + ($inRow - 1 - $c) * 50,  y = $top + $r * 30
```

- [ ] **Step 1: Write the failing tests**

`CardLayoutTest` (A4, 210 × 297):
- `test_one_card_is_centred`: x 82.5, y 266.
- `test_five_cards_make_two_rows_the_first_at_the_right`: 4 per row; the first card at x 157.5 in row 0; the fifth alone, centred, in row 1.
- `test_eleven_cards_fit_above_the_line`: 3 rows, `newPage` false.
- `test_a_short_page_takes_a_new_page`: `place(8, 210, 100)` has `newPage` true.

`DocumentPrinterTest` (`Storage::fake('local')`): a document whose original is `Pdfs::plain()`, a sealed stamp and signature, and marks built by hand.
- `test_a_card_is_printed_on_a_copy`: the returned path is under `documents/{folder}/printed/`, and the copy opens via `PdfIntake::pageCount()` with as many pages as the original. The original is byte-for-byte unchanged.
- `test_a_rejection_and_a_decider_card_print`: a rejection card and a decisive card print, and the copy opens.
- `test_eleven_cards_print`: 11 marks print, and the copy opens.
- `test_a_rebuild_prints_each_marks_own_stamp_version`: replace the unit's stamp, then delete the new version's sealed file. A rebuild still succeeds, because the earlier mark names the old `stamp_id`.
- `test_no_decrypted_image_is_left_on_disk`: after `print()`, `Storage::disk('local')->allFiles('pdf-work')` is empty.

- [ ] **Step 2: Run them and see them fail.**
- [ ] **Step 3: Implement**

Inside `PdfWorkspace::run()`:
1. Import every page of the original with `AddPageByArray` at its own size and `useTemplate`.
2. Lay the marks out on the last page with `CardLayout`, adding a page of the last page's size when `newPage` is true.
3. Write each card with `WriteFixedPosHTML()` from `documents.card`, in RTL, with the images passed as `imageVars` (`var:stamp{markId}`, `var:signature{markId}`). Use the Global Constraints' colours and sizes.
4. Write the «رقم المستند: {id}» line, centred, 2 mm under the block.
5. `Output()` to a session file, then copy it to the disk path.

- [ ] **Step 4: Run the tests and the gates.**
- [ ] **Step 5: Stop for review**

---

### Task 4: Creating a document

**Files:**
- Create: `app/Modules/Documents/Services/DocumentCreation.php`, `app/Modules/Documents/Support/CreateDocumentInput.php`, `app/Modules/Documents/Requests/CreateDocumentRequest.php`, `app/Modules/Documents/Controllers/DocumentController.php` (`store`), `app/Modules/Documents/Resources/DocumentResource.php`
- Create: `app/Modules/Documents/Policies/DocumentPolicy.php` (`view`, `delete`; used from Task 7 on, tested in Tasks 8 and 10)
- Create: `app/Modules/Documents/Exceptions/NotAUnitManagerException.php` (403) and `DocumentRouteException.php` (422, message given)
- Modify: `app/Modules/Documents/Models/Document.php`: `implements Approvable` (spec §7.4), `#[UsePolicy(DocumentPolicy::class)]`
- Modify: `app/Modules/Documents/routes.php` (`POST /`)
- Test: `tests/Feature/Documents/CreateDocumentTest.php`

**Interfaces:**
- Consumes: `UnitManagers` (Task 2), `PdfIntake` and `PdfWorkspace` (piece 1), `ApprovalService::submit()`, `AttachmentStore`.
- Produces:
  - `DocumentCreation::create(Employee $creator, User $by, CreateDocumentInput $input): Document`. `CreateDocumentInput` is a readonly bag: title, description, importNumber, file, approverIds, attachments.
  - `DocumentPolicy`:
    - `view(User, Document)`: the creator, any step holder (`approval.steps.approver_id`), or `documents.view`.
    - `delete(User, Document)`: the creator. The super admin passes through `Gate::before`.
  - `DocumentResource`, used by every later endpoint: `id`, `kind`, `origin`, `title`, `description`, `status{value,label}`, `import_number`, `creator{id,full_name}`, `creator_unit{id,name}`, `created_at`, `decided_at`, `has_printed_copy`, `steps[{id, order, approver{id,full_name}, status{value,label}, decisive, note, acted_at, skip_reason}]`, `attachments[{id, original_name, mime_type, size}]`, `my_step_id`, `can{decide, delete}`.
  - `Document` Approvable:
    - `approvalRequester()`: the creator.
    - `canBeApproved()`: null.
    - `describeForApprover()`: the words in the Global Constraints. The decider's wording is used when the approval's current step is decisive.
    - `approvalSummary()`: the title, and "{creator} · {unit}".
    - `approvalSummaryRelations()`: `['creator', 'creatorUnit']`.
    - `approverRowDetails()`: `[id => ['stamp_missing' => bool]]` for the requesting account's unit.
    - `onApprovalDecided()`:
      - Approved: Approved, plus `decided_at`, and the creator told «تمت الموافقة على مستندك».
      - Rejected: Rejected, and the creator told «تم رفض مستندك».
      - Cancelled: `deleted_by` = the authenticated account (or the creator's), then soft-deleted.

- [ ] **Step 1: Write the failing tests**

`CreateDocumentTest`: a root with GM, a division with manager C (the creator), sections with managers A1 and A2; every one has an account. `POST /api/documents` multipart: `title`, `file` = `Pdfs::plain()`, `approvers[]` = [A1, A2].
- `test_a_manager_sends_a_document_for_signing`: 201 «تم إرسال المستند للاعتماد.».
  - The row: Internal, Uploaded, Pending; `creator_unit_id` = the division; `register_unit_id` = the division.
  - The file on disk matches `original_path`.
  - The approval: Advisory, three steps (A1 order 1, A2 order 2, GM order 3 decisive), only A1 pending at the current order.
  - A1 has a notification «مستند جديد بانتظار موافقتك» whose reference type is `document`.
  - The response is a `DocumentResource`.
- `test_only_a_unit_manager_creates`: 403 «لا يُنشئ المستندات إلا مدير وحدة تنظيمية.».
- `test_approvers_must_be_unit_managers_other_than_the_creator_and_the_gm`:
  - a non-manager gives 422 with their name in the message;
  - the creator, and the GM, each give their own 422;
  - a repeated approver gives «لا يمكن تكرار المعتمد نفسه.».
- `test_a_vacant_general_manager_post_refuses_sending`: «لا يوجد مدير عام في الهيكل التنظيمي، فلا يمكن إرسال المستند.»
- `test_the_general_manager_s_own_document_skips_his_step`: GM creates with A1. The GM step is Skipped (`Requester`). A1 approving later (Task 7) completes it.
- `test_a_repaired_pdf_keeps_the_uploaded_file`: bind a repairer writing `Pdfs::plain()` and upload `Pdfs::withXrefStream()`. Both `original_path` and `uploaded_path` exist.
- `test_a_photo_is_sent_as_a_pdf` and `test_a_word_file_is_refused`: the photo becomes a PDF; the Word file gives the intake's message.
- `test_supporting_attachments_up_to_ten`: 10 are stored through `AttachmentStore`; 11 are refused with «لا تزيد المرفقات على 10.».
- `test_an_import_number_is_unique_in_the_register`: a second document of the same division with the same وارد is refused with «رقم الوارد 77 مستخدم في سجل هذه الإدارة.».
- `test_nothing_is_left_on_disk_when_sending_fails`: a vacant GM post leaves no file under `documents/` or `attachments/`.

- [ ] **Step 2: Run them and see them fail.**
- [ ] **Step 3: Implement**

1. Validate the input: the request rules, then the approver rules in the service, before any file is written.
2. Inside `PdfWorkspace::run()`, run `PdfIntake::accept()` and copy the printable file (and the uploaded one, when repaired) to `documents/{folder}/`.
3. Then one transaction: create the row, store the attachments, `submit()` the route.
4. On any throw, delete the `documents/{folder}` directory and the stored attachments' files.

`store` uses `SelfEmployee` for the creator.

- [ ] **Step 4: Run the tests and the gates.**
- [ ] **Step 5: Stop for review**

---

### Task 5: The visibility rule in the approvals module

**Files:**
- Create: `app/Modules/Approvals/Contracts/RestrictsApprovalVisibility.php`
- Modify: `app/Modules/Approvals/Policies/ApprovalPolicy.php` (`view`), `app/Modules/Approvals/Controllers/ApprovalListController.php` (`index`)
- Modify: `Document` (implements the contract)
- Test: `tests/Feature/Documents/ApprovalVisibilityTest.php`

**Interfaces:**
- Produces: `interface RestrictsApprovalVisibility { public static function mayReadApprovalsWithoutBeingParty(User $user): bool; }`. `Document` returns `$user->hasPermission('documents.view')`.

- [ ] **Step 1: Write the failing tests**

`ApprovalVisibilityTest`: one document (from Task 4's setup) and one leave request in the same unit, and an HR user scoped to that unit with `approvals.view`.
- `test_hr_s_approvals_list_leaves_documents_out`: `GET /api/approvals` lists the leave's approval and not the document's.
- `test_the_approval_screen_refuses_a_document_s_chain`: `GET /api/approvals/{documentApproval}` is 403. The leave's is 200.
- `test_parties_and_documents_view_still_read_it`: A1 (a step holder) and a `documents.view` holder both get 200 on the document's approval. The list includes it for the `documents.view` holder.

- [ ] **Step 2: Run them and see them fail.**
- [ ] **Step 3: Implement**

- **`ApprovalPolicy::view`**: after `isParty`, if the approval's subject class implements the contract and the user may not read it, deny.
- **`ApprovalListController::index`**: add `whereNotIn('approvable_type', …)` over the morph-map aliases whose class implements the contract and returns false for this user.
- Leave and swaps do not implement it.

- [ ] **Step 4: Run the tests and the gates.** The whole approvals, leave and scheduling suites must pass unchanged.
- [ ] **Step 5: Stop for review**

---

### Task 6: The guard on the engine's steps

**Files:**
- Create: `app/Modules/Documents/Services/DecisionGate.php` (singleton), `app/Modules/Documents/Observers/DocumentStepGuard.php`
- Modify: `AppServiceProvider` (`ApprovalStep::observe(DocumentStepGuard::class)`, the singleton)
- Test: `tests/Feature/Documents/DocumentStepGuardTest.php`

**Interfaces:**
- Produces:
  - `DecisionGate::through(int $stepId, callable $work): mixed`: the step is open only while `$work` runs.
  - `DecisionGate::isOpen(int $stepId): bool`.

- [ ] **Step 1: Write the failing tests**

`DocumentStepGuardTest`:
- `test_the_general_approve_endpoint_refuses_a_document_step`: A1 calling `POST /api/approvals/{id}/steps/{step}/approve` gets 422 «يُتَّخذ القرار في المستندات من شاشة المستند.». The step stays pending.
- `test_only_the_system_administrator_reassigns_a_document_step`:
  - an `approvals.reassign` holder (company-wide) gets 403 «إعادة إسناد خطوات المستندات لمدير النظام فقط.»;
  - the administrator moving A1's step to another unit's manager gets 200;
  - moving it to a non-manager gives «لا يمكن الإسناد إلا إلى مدير وحدة تنظيمية.».
- `test_the_decider_s_step_goes_only_to_the_current_general_manager`: once the root's manager changes, the decisive step moves to the new GM; to anyone else it gives «خطوة القرار الأخير لا تُسند إلا إلى المدير العام الحالي.».
- `test_leave_steps_are_untouched`: a leave approval decided and reassigned through the general endpoints behaves as before.
- `test_a_withdrawal_closes_steps_as_skipped`: the engine's `withdraw()` closing steps is not a decision, so the guard lets it through.

- [ ] **Step 2: Run them and see them fail.**
- [ ] **Step 3: Implement**

On `updating`, the guard does nothing unless the step's approval's `approvable_type` is `document`.
- **A decision:** status changing from Pending to Approved or Rejected throws `ApprovalException` (422) unless the gate is open.
- **A reassignment** (`approver_id` dirty) must pass three checks:
  - the actor (`auth()->user()`) is a super admin, else `ApprovalException` (403);
  - the new approver is `UnitManagers::unitOf() !== null`;
  - a decisive step goes only to `generalManager()`.

- [ ] **Step 4: Run the tests and the gates.**
- [ ] **Step 5: Stop for review**

---

### Task 7: Deciding

**Files:**
- Create: `app/Modules/Documents/Services/DocumentDecisions.php`, `app/Modules/Documents/Requests/DecideDocumentRequest.php`, `app/Modules/Documents/Exceptions/SignatureRequiredException.php` (428), `DocumentDecisionException.php` (422, message given)
- Modify: `DocumentController` (`decide`), `routes.php` (`POST /{document}/decision`, `whereNumber`, `can:view,document`)
- Modify: `app/Modules/Documents/Services/UnitStamps.php` (`replace()` forgets the stamp-missing key)
- Test: `tests/Feature/Documents/DecideDocumentTest.php`

**Interfaces:**
- Consumes: Tasks 2, 3, 6; `ApprovalService::act()`; `UnitStamps::current()`; `Signatures::current()`.
- Produces: `DocumentDecisions::decide(Document $document, User $actor, Decision $decision, ?string $note, Confirmation $by, ?string $password): Document`.

- [ ] **Step 1: Write the failing tests**

`DecideDocumentTest`: Task 4's document; every manager has a sealed signature and their unit a stamp. Body: `decision` (`approve`|`reject`), `note`, `confirmed_by`, `password`.
- `test_the_chain_signs_in_order_and_the_gm_decides`:
  - A1, A2 and GM approve in turn (`confirmed_by=1`), and the document ends Approved;
  - each decision adds one ApprovalCard mark with `stamp_id`, `signature_id`, `unit_name`, `employee_name`, `confirmed_by`, and the GM's with `decisive` true;
  - `printed_path` changes each time and the previous copy is deleted;
  - A2 is told «دورك للمراجعة», the GM «جميع المدراء وافقوا — قرارك مطلوب», and C «تمت الموافقة على مستندك».
- `test_a_manager_s_rejection_is_recorded_and_the_document_goes_on`:
  - A1 rejects: a RejectionCard mark;
  - C is told «تم رفض المستند من مدير»;
  - A2 is now current;
  - after A2 and the GM approve, it ends Approved.
- `test_the_gm_s_rejection_ends_it`: the document is Rejected, and C is told «تم رفض مستندك».
- `test_the_gm_s_own_document_is_approved_once_his_managers_sign`: approved even when A1 rejected (D30.2).
- `test_a_password_confirmation`: a wrong password gives 422 «كلمة المرور غير صحيحة.»; the right one gives 200, with the mark's `confirmed_by` Password.
- `test_no_signature_asks_for_the_pad`: 428 «لم ترسم توقيعك بعد. ارسمه ثم أعد المحاولة.», and the step stays pending.
- `test_a_missing_stamp_holds_the_approval`:
  - 422 with the unit's name, and the step stays pending;
  - the system administrators get «ختم وحدة غير مرفوع» once: a second try sends no second notification;
  - A1's `GET /api/me/approvals` row carries `stamp_missing` true in its details (spec §7.4);
  - after the stamp is uploaded, the row says false and the approval goes through.
- `test_a_manager_who_lost_their_unit_cannot_sign`: clear A1's unit's `manager_id`, and the decision gives «لا تدير وحدة تنظيمية حالياً…».
- `test_deciding_twice_records_one_mark`: a second identical request gets «ليس دورك في اعتماد هذا الطلب.», and there is one mark.
- `test_a_print_failure_rolls_the_decision_back`: bind a `DocumentPrinter` that throws. The step stays pending, no mark exists, and `printed_path` is unchanged.

- [ ] **Step 2: Run them and see them fail.**
- [ ] **Step 3: Implement**

1. Run the checks in the Global Constraints' order. For a missing stamp, `Cache::add("documents.stamp-missing.{unitId}", true)` and, when that adds the key, notify the `super_admin` role.
2. Then `DB::transaction`:
   - `DecisionGate::through()` around `act()`;
   - `DocumentMark::create()` (next position);
   - `DocumentPrinter::print()`;
   - update `printed_path`;
   - `DB::afterCommit()` deletes the previous copy;
   - on a throw, delete the new copy and rethrow.
3. A non-final rejection then notifies the creator.

`UnitStamps::replace()` calls `Cache::forget("documents.stamp-missing.{$unit->id}")`.

- [ ] **Step 4: Run the tests and the gates.**
- [ ] **Step 5: Stop for review**

---

### Task 8: Seeing documents, and the lists

**Files:**
- Create: `app/Modules/Documents/Requests/DocumentsIndexRequest.php`, `app/Modules/Documents/Exceptions/DocumentDeletedException.php` (410)
- Modify: `DocumentController` (`index`, `show`, `file`), `routes.php`
- Test: `tests/Feature/Documents/DocumentListsTest.php`

**Interfaces:**
- Consumes: `DocumentPolicy` (Task 4).
- Produces:
  - Routes:
    - `GET /api/documents?box=incoming|outgoing|all&status=&search=`;
    - `GET /api/documents/{document}` (bound `withTrashed`, a trashed one answers 410);
    - `GET /api/documents/{document}/file` (the printed copy, else the original).

- [ ] **Step 1: Write the failing tests**

`DocumentListsTest`:
- `test_incoming_lists_what_i_sign_my_turn_first`: A2's incoming shows the document A2 must decide now ahead of one A2 already decided. A document A2 is not on is absent.
- `test_outgoing_lists_what_i_created`.
- `test_all_needs_documents_view`: without it, 403 «لا تملك صلاحية عرض جميع المستندات.».
- `test_search_and_status_filter`: by title; by status.
- `test_show_and_file_for_a_party`: a `DocumentResource`, and `my_step_id` and `can.decide` true for the current approver. `file` streams the original before any decision and the printed copy after, both with `no-store`.
- `test_nobody_off_the_document_reads_it`: 403 from show, file and decision for an employee holding every permission except `documents.view`.

- [ ] **Step 2: Run them and see them fail.**
- [ ] **Step 3: Implement.** Paginate with `ApiRespond::paginated` and `perPage`. "My turn first" orders by whether the caller holds a pending step at the approval's current order, then by id descending.
- [ ] **Step 4: Run the tests and the gates.**
- [ ] **Step 5: Stop for review**

---

### Task 9: Comments and attachments

**Files:**
- Create: `app/Modules/Documents/Controllers/DocumentCommentController.php`, `app/Modules/Documents/Requests/DocumentCommentRequest.php`
- Modify: `DocumentController` (`attachment`), `routes.php` (`GET|POST /{document}/comments`, `GET /{document}/attachments/{attachment}`, scoped bindings, `can:view,document`)
- Test: `tests/Feature/Documents/DocumentCommentsTest.php`

- [ ] **Step 1: Write the failing tests**
- `test_parties_comment_and_read_each_other`: C and A1 post comments, and both see both, oldest first, each with its author and time. Creating one gives 201 «تمت إضافة التعليق.».
- `test_a_comment_needs_a_body`: «نص التعليق مطلوب.»; over 2000 characters is refused.
- `test_an_attachment_is_streamed_to_parties_only`: a party gets the bytes with `no-store`. An attachment of another document is a 404 (scoped). An outsider gets 403.

- [ ] **Step 2: Run them and see them fail.**
- [ ] **Step 3: Implement.** `AttachmentStore::stream()` for the file. A comment's author is the caller's employee (`SelfEmployee`).
- [ ] **Step 4: Run the tests and the gates.**
- [ ] **Step 5: Stop for review**

---

### Task 10: Deleting

**Files:**
- Create: `app/Modules/Documents/Services/DocumentDeletion.php`
- Modify: `DocumentController` (`destroy`), `routes.php` (`DELETE /{document}`, `can:delete,document`)
- Test: `tests/Feature/Documents/DeleteDocumentTest.php`

**Interfaces:**
- Produces: `DocumentDeletion::delete(Document $document, User $by): void`. It refuses after any approved or rejected step, then calls `ApprovalService::withdraw()` with the creator's account; the engine lets only the requester withdraw. `onApprovalDecided(Cancelled)` (Task 4) soft-deletes, with `deleted_by` = the authenticated account, which is `$by`.

- [ ] **Step 1: Write the failing tests**
- `test_the_creator_deletes_before_the_first_signature`: 200 «تم حذف المستند.». The document is soft-deleted with `deleted_by` = C, the approval is Cancelled with its steps closed (`ClosedByCancellation`), it is gone from every list, and A1's inbox no longer has it.
- `test_the_system_administrator_deletes_any_unsigned_document`: `deleted_by` = the administrator, and the audit trail records the administrator.
- `test_a_signed_document_cannot_be_deleted`: after A1 approves, 422 «لا يمكن حذف مستند بعد أول توقيع عليه.», and nothing changed.
- `test_only_the_creator_or_the_administrator`: A1 gets 403.
- `test_the_general_withdraw_is_a_deletion`: C calling `POST /api/approvals/{id}/withdraw` leaves the document soft-deleted.
- `test_a_deleted_document_answers_410_to_its_parties`: A1 opening it (as from an earlier notification) gets 410 «حُذف هذا المستند.». An outsider gets 403.

- [ ] **Step 2: Run them and see them fail.**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Run the tests and the gates.**
- [ ] **Step 5: Stop for review**

---

### Task 11: The API documentation, Postman and the work order

**Files:**
- Modify: `docs/API_DOCUMENTATION.md` (§20.5 onwards: creating, deciding, the lists, show/file, comments, attachments, deleting; the visibility note in §15; quick-reference rows)
- Modify: `postman/Alraqi-ERP.postman_collection.json` (the documents folder; use a script file, keeping the file's tab formatting)
- Modify: `docs/EMPLOYEE_APP_V1_WORK_ORDER.md` (§8 entry), `docs/START_HERE.md` (the documents bullet)

- [ ] **Step 1: Document every endpoint** in the §20 style:
  - who may call it;
  - its fields and limits;
  - each response and error with its exact copy;
  - the 428 meaning "open the pad";
  - the confirmation fields;
  - that the general approvals screens leave documents out.
- [ ] **Step 2: Add the Postman requests**, then validate the JSON (`php -r "json_decode(file_get_contents('postman/Alraqi-ERP.postman_collection.json'), flags: JSON_THROW_ON_ERROR);"` → no output).
- [ ] **Step 3: Run the gates once more.**
- [ ] **Step 4: Stop.** Report to the owner, who commits.

---

## Addendum (2026-11-23): the owner's rulings after the final review

Spec §7.8 and the two 2026-11-23 rows of §19. Same Global Constraints, with these additions:

- **Inactive units:** "a unit's manager" means the manager of a non-deleted, **active** unit, for creating, being named, signing and reassigning. The one-unit-per-manager rule (piece 1) still counts every non-deleted unit, active or not. المدير العام is the root's manager whatever the root's flag.
- **The engine's one further addition:** `ApprovalService::cancel(Approval $approval): Approval`, plus `SkipReason::ClosedByAdministrator = 6` «أُغلقت بقرار مدير النظام». Only Documents calls it; leave and swaps never do. Nothing else in the engine changes.
- **New copy, exact:**
  - «لا يمكن إغلاق طلب ليس قيد الاعتماد.» (the engine)
  - «أُغلق المستند: لم يُستكمل.» (200)
  - «لا يُغلق إلا مستند قيد الاعتماد.» (422)
  - «سبب الإغلاق مطلوب، من 3 إلى 500 حرف.»
  - `DocumentStatus::NotCompleted = 7` «لم يُستكمل»
  - To the creator: «لم يُستكمل مستندك» / «أغلق مدير النظام المستند «{title}»: {reason}. يمكنك إرساله من جديد.»
  - Notification type `document.not_completed` «لم يُستكمل المستند»
- **Who:** closing and the attention list are the system administrator's alone: Gate ability `documents.close`, defined false, passed only by the super-admin bypass, never a permission row.

### Task 12: Documents ignore inactive units

**Files:**
- Modify: `app/Modules/Documents/Services/UnitManagers.php` (`unitOf`)
- Test: `tests/Feature/Documents/UnitManagersTest.php`, `CreateDocumentTest.php`, `DecideDocumentTest.php`, `DocumentStepGuardTest.php`

**Interfaces:**
- Produces: `UnitManagers::unitOf(Employee $employee): ?Department`, the non-deleted, **active** unit they manage (by id). `generalManager()` and `registerOf()` are unchanged.

- [ ] **Step 1: Write the failing tests**
  - `UnitManagersTest::test_an_inactive_unit_is_not_managed`: deactivating the unit makes `unitOf` null.
  - `CreateDocumentTest::test_a_manager_of_an_inactive_unit_cannot_send`: 403 «لا يُنشئ المستندات إلا مدير وحدة تنظيمية.».
  - `CreateDocumentTest::test_a_manager_of_an_inactive_unit_cannot_be_named`: 422 with their name.
  - `DecideDocumentTest::test_an_approver_whose_unit_is_deactivated_cannot_sign`: «لا تدير وحدة تنظيمية حالياً…», and the step stays pending.
  - `DocumentStepGuardTest::test_a_step_cannot_go_to_a_manager_of_an_inactive_unit`: «لا يمكن الإسناد إلا إلى مدير وحدة تنظيمية.».
  - `OneUnitPerManagerTest`: unchanged, and still green (an inactive unit still counts).
- [ ] **Step 2: Run them and see them fail.**
- [ ] **Step 3: Implement**: `where('is_active', true)` in `unitOf`.
- [ ] **Step 4: Run the tests and the gates.**
- [ ] **Step 5: Stop for review**

### Task 13: Closing a stuck document as «لم يُستكمل», and the attention list

**Files:**
- Modify: `app/Modules/Approvals/Services/ApprovalService.php` (`cancel`), `app/Modules/Approvals/Enums/SkipReason.php` (`ClosedByAdministrator`)
- Test: `tests/Feature/Approvals/ApprovalCancellationTest.php` (with the existing `ApprovalFixtures` / `FakeApprovable`)
- Modify (the undeployed documents migration, in place): `closed_by` (FK users, null on delete), `closed_reason` text, both nullable. `live_import_number` becomes `CASE WHEN deleted_at IS NULL AND status <> 7 THEN import_number END`.
- Modify: `DocumentStatus` (NotCompleted 7), `Document` (`onApprovalDecided`), `DocumentCreation` (the وارد pre-check counts live documents that are not NotCompleted), `DocumentResource` (`closed_reason`), `DocumentsIndexRequest` (status 7), `NotificationEventCatalogue`, `AppServiceProvider` (the `documents.close` ability)
- Create: `app/Modules/Documents/Services/DocumentClosing.php`, `app/Modules/Documents/Services/DocumentsNeedingAttention.php`, `app/Modules/Documents/Requests/CloseDocumentRequest.php`
- Modify: `DocumentController` (`close`, `attention`), `routes.php`:
  - `POST /{document}/close`: `whereNumber`, `can:documents.close`
  - `GET /attention`: `can:documents.close`, paginated
- Test: `tests/Feature/Documents/DocumentClosingTest.php`, `tests/Feature/Documents/DocumentsNeedingAttentionTest.php`

**Interfaces:**
- Produces:
  - `ApprovalService::cancel(Approval $approval): Approval`: locks as `act` does, refuses a non-pending approval («لا يمكن إغلاق طلب ليس قيد الاعتماد.»), closes the pending steps Skipped with `ClosedByAdministrator`, ends the approval Cancelled with `decided_at`, and tells the subject (`onApprovalDecided`). It is allowed after decisions, unlike `withdraw`.
  - `DocumentClosing::close(Document $document, User $by, string $reason): Document`, in one transaction:
    1. Lock the document; refuse unless Pending.
    2. Set NotCompleted, `closed_by`, `closed_reason` and `decided_at`.
    3. `cancel()` the approval.
    4. Notify the creator.
  - `Document::onApprovalDecided(Cancelled)`: when the document is already NotCompleted, leave it (no soft delete); otherwise keep the withdrawal's soft delete.
  - `DocumentsNeedingAttention::query(): Builder`: pending documents where the pending decisive step's approver is not `generalManager()` (reason `former_general_manager`), or the current pending step's approver has no `unitOf()` (reason `approver_manages_no_unit`). Each row is a `DocumentResource` plus `attention{reason, step_id, approver{id, full_name}}`.

- [ ] **Step 1: Write the failing tests**
  - `ApprovalCancellationTest`:
    - cancelling after one decision leaves the decided step as it was, closes the pending ones with `ClosedByAdministrator`, and makes the approval Cancelled;
    - the subject is told once;
    - a non-pending approval is refused with the message;
    - existing leave and withdraw tests stay unchanged.
  - `DocumentClosingTest`:
    - `test_the_administrator_closes_a_signed_document_as_not_completed`: after A1 signs:
      - the response is 200 «أُغلق المستند: لم يُستكمل.»;
      - the status is NotCompleted, with `closed_reason` and `closed_by`;
      - the marks, the printed copy and the numbers are kept;
      - the approval is Cancelled, A2's and the GM's steps are Skipped with `ClosedByAdministrator`, and it is gone from A2's inbox;
      - the creator is told;
      - it is still in the creator's outgoing list, and show is 200.
    - `test_the_promotion_dead_end_ends_cleanly`: A1 signs. A1's unit manager is cleared and the root's manager becomes A1. Reassigning the decisive step to A1 is refused by the engine. The administrator closes the document, and it is NotCompleted.
    - `test_only_the_system_administrator_closes`: a `documents.view` holder, and the creator, get 403.
    - `test_only_a_pending_document_closes`: an Approved document gives 422 «لا يُغلق إلا مستند قيد الاعتماد.».
    - `test_a_reason_is_required`: «سبب الإغلاق مطلوب، من 3 إلى 500 حرف.».
    - `test_the_creator_sends_again_with_the_same_incoming_number`: after closing a document whose وارد is 77, a new document of the same register with وارد 77 is 201.
  - `DocumentsNeedingAttentionTest`:
    - a document waiting on a former GM is listed with `former_general_manager`;
    - a document whose current approver's unit was deactivated is listed with `approver_manages_no_unit`;
    - a healthy pending document is absent;
    - an outsider gets 403.
- [ ] **Step 2: Run them and see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the tests and the gates.** The whole approvals, leave and scheduling suites must pass unchanged.
- [ ] **Step 5: Stop for review**

### Task 14: Docs, Postman, the work order

- [ ] **Step 1:** `API_DOCUMENTATION.md`:
  - §20: the close endpoint and the attention list, and the reassign procedure of spec §7.8;
  - inactive units under "who";
  - the new status 7;
  - §15: the engine's `cancel` is the documents' administrator close, and leave and swaps don't use it;
  - quick-reference rows.
- [ ] **Step 2:** Postman: add two requests to the "Documents - between managers" folder, keeping the TAB formatting. Validate the JSON.
- [ ] **Step 3:** Work order §8: add to the piece 2 entry (or a short new entry dated 2026-11-23) the owner's two rulings and what was built.
- [ ] **Step 4:** Run the gates. Stop.
