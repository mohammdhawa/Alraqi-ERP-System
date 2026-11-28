# Documents — piece 4, documents to employees: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Workflow 1 of the design. One of HR's managers sends a document directly to one employee, by upload or from a `for_employees` template. The employee reads it in the app and signs «قرأت ووافقت» (their drawn signature is printed on it) or refuses with a reason. Either answer files the document on the employee's record, and HR reads that record's filed documents behind the record's own rules and the salary gate.

**Architecture:**
- **A document to an employee** is the `documents` row of piece 2 with `kind` ToEmployee, `recipient_id` and `filing_type`. It **never touches the approvals engine**: no approval, no route, no approver.
- **Producing the original** (an upload through piece 1's intake, or a template printed with its صادر number and reference) moves out of piece 2–3's `DocumentCreation` into one shared service. Both workflows call it.
- **The employee's answer** goes through its own endpoints, in one transaction under a lock on the document row:
  - **signing:** the confirmation, the first-drawn signature, a new mark kind printed by piece 2's printer, the status, and the filing row;
  - **refusing:** the confirmation, the reason, the status, and the filing row; nothing is printed.
- **The filed list** lives on the HR employee's routes, as piece 1's signature routes do.

**Tech Stack:** Laravel 12, PHP 8.2, PHPUnit (sqlite in memory), Pint; mPDF 8.3 (pieces 1–3).

**Spec:** [`docs/superpowers/specs/2026-11-22-documents-design.md`](../specs/2026-11-22-documents-design.md) ("spec §n"): §1.4 (workflow 1), §4, §5, §6.1–§6.3, §9, §10.1, §10.3, §11.3–§11.4, §15, §16, §17 piece 4, §18 calls 5, 6, 12, 17, §19 Q5–Q6. The decisions: D11, D14–D18, D20.3, D29, D31 of [`DECISION_ROUND_DOCUMENTS.md`](../../DECISION_ROUND_DOCUMENTS.md). It builds on pieces 1–3 on `main` (merge `f6718da`): [piece 1](2026-11-22-documents-foundations.md), [piece 2](2026-11-22-documents-between-managers.md), [piece 3](2026-11-23-documents-templates-numbers.md).

## Global Constraints

- **Prerequisite:** pieces 1–3 are on `main` (`f6718da`). Branch `documents-employees` from `main`. If they are not there, stop and ask.
- **No commit, stage, reset, stash or push without the owner's explicit go-ahead.** Leave changes in the working tree; `git add -N` new files. Another agent may edit this tree: run `git status` and re-read a file before editing it.
- **Untouched:**
  - every file in `app/Modules/Approvals/`, `ManagementChain`, and the Leave and Scheduling modules;
  - piece 2's decision, closing and attention code;
  - the behaviour of documents between managers.

  Every existing test passes unchanged, including piece 2–3's creation tests after Task 2 moves the original-producing code out of `DocumentCreation`.
- **Workflow 1 never touches the engine** (spec §1.4, §9). A ToEmployee document has no `approval`. Nothing in this piece calls `ApprovalService`.
- **Who** (spec §4, §5, D29):
  - **HR** is the unit whose `code` is `config('leave.hr_unit_code')`, and every unit under it.
  - **A sender** is the manager (`UnitManagers::unitOf`) of HR or of any unit under it. With no such unit, nobody sends.
  - **The recipient** is one employee (D18.2) with status Active and an active account (`User::isActive()`), not the sender.
  - **Seeing a document:** its sender, its recipient (from sending on), or `documents.view`.
  - **Commenting:** anyone who sees it **except its recipient** (spec §5, call 5).
  - **Deleting:** the sender or the system administrator, while it is AwaitingSignature (D31).
  - **Answering:** only its recipient, only while AwaitingSignature.
- **Filing types offered for a sent document** (D20.3, the plan's call 1). `EmployeeDocumentType` gains four cases:

  | Value | Case | Label |
  |---|---|---|
  | 9 | `AppointmentDecision` | «قرار تعيين» |
  | 10 | `PromotionDecision` | «قرار ترقية» |
  | 11 | `WorkCertificate` | «شهادة عمل» |
  | 12 | `Warning` | «إنذار» |

  A sent document may be filed as `Contract` (7), `Other` (8) or 9–12. `EmployeeDocumentType::forSentDocuments(): array<self>` lists them.
- **The salary gate** (spec §9.4, §19 Q6): `ShamCash` (4), `Contract` (7), `AppointmentDecision` (9) and `PromotionDecision` (10) read only for a caller whose `hr.employees.viewSalary` grant reaches that employee. That is `RequestRowScopes::forUser($user, 'hr.employees.viewSalary')->covers($employee->department_id)`, the rule `EmployeeResource` already uses. `EmployeeDocumentType::readsThroughSalary(): bool` names the four; `EmployeeDocument::scopeReadableWithout()` leaves all four out (today it leaves out ShamCash only).
- **Statuses:** AwaitingSignature (4) on sending, with `sent_at`; then Signed (5) with `signed_at`, or Refused (6) with `refused_at` and `refusal_reason`. Both answers are final (I.13).
- **What a sent document carries** (the plan's call 2): a title, an optional description, the recipient, the filing type, and either a file (piece 2's intake rules) or a `for_employees` template with its values.
  - No approvers, no وارد number, and no supporting attachments; each is refused if sent.
  - A template document claims its صادر number and reference from **the sender's إدارة** (spec §9.1), exactly as piece 3.
- **The signature block** (spec §11.4, the plan's call 3) is one mark of kind `EmployeeSignature` (3), drawn by piece 2's `DocumentPrinter`:
  - a frame of the cards' size (45 × 25 mm, `CardLayout::CARD_WIDTH/HEIGHT`), the normal colours, no stamp;
  - the heading «قرأت ووافقت», the signature image (14 × 8 mm, as the cards'), the name, and the date and time (`Y-m-d H:i`, Asia/Damascus);
  - placed by `CardLayout::place(1, …)` with the document's `card_floor` and `content_bottom`, so it is centred at the foot of the last page, above the letterhead's footer, and on a new page when it would cover the text;
  - «رقم المستند: {id}» under it, as under the cards.
- **Confirmation** (spec §10.3, D16) for signing and for refusing: piece 2's rule and copy. `confirmed_by` is 1 (biometric, as the app reports) or 2 (password, `Hash::check`).
- **Filing on the record** (spec §9.4): one `employee_documents` row per answer, written in the answer's transaction:
  - `employee_id` the recipient; `type` the filing type; `title` the document's title;
  - `file_path` the document's **printed copy** when signed, or its **original** (the copy as sent) when refused. Nothing is copied: the row points at the document's file;
  - `original_name` `{title}.pdf`; `mime_type` `application/pdf`; `size_bytes` the file's size;
  - `uploaded_by` the sending account (`documents.created_by`);
  - `issued_at` the answer's day (Asia/Damascus);
  - `document_id` the document (a new nullable column).
- **Notifications** (`NotificationEventCatalogue`, with labels):
  - `document.awaiting_signature` «مستند بانتظار توقيع موظف»: to the recipient, on sending;
  - `document.signed` «وقّع موظف على مستند»: to the sender;
  - `document.refused` «رفض موظف مستنداً»: to the sender.

  Each is sent once its transaction has committed, as piece 2's are.
- **Arabic copy, exact:**
  - **Sending:**
    - «تم إرسال المستند إلى الموظف.» (201)
    - «لا يرسل المستندات إلى الموظفين إلا مديرو الموارد البشرية.» (403)
    - «الموظف المستلم مطلوب.»
    - «الموظف المحدد غير موجود.»
    - «لا يمكنك إرسال مستند إلى نفسك.»
    - «لا يمكن إرسال مستند إلى «{name}» لأنه ليس على رأس عمله.»
    - «لا يمكن إرسال مستند إلى «{name}» لأنه لا يملك حساباً فعّالاً.»
    - «نوع الحفظ في ملف الموظف مطلوب.»
    - «نوع الحفظ غير صالح.»
    - «هذا القالب ليس للمستندات المرسلة إلى الموظفين.»
    - «لا يُحدَّد معتمدون لمستند يُرسل إلى موظف.»
    - «لا يُحدَّد رقم وارد لمستند يُرسل إلى موظف.»
    - «لا تُرفق ملفات داعمة بمستند يُرسل إلى موظف.»
    - Reused as they are from pieces 2–3: the title, file, template, صادر and field-value copy.
  - **To the recipient:** title «مستند جديد بانتظار توقيعك», body «أرسل إليك {sender} المستند «{title}».»
  - **Answering:**
    - «تم التوقيع على المستند.» (200)
    - «تم رفض المستند.» (200)
    - «هذا المستند ليس بانتظار توقيعك.» (422)
    - «سبب الرفض مطلوب، من 3 إلى 500 حرف.»
    - Reused from piece 2's decision: «تأكيد الهوية مطلوب.», «كلمة المرور مطلوبة للتأكيد.», «كلمة المرور غير صحيحة.», and the 428 `signature_required` answer.
  - **To the sender:**
    - signed: title «وقّع {name} على المستند», body «وقّع {name} على المستند «{title}».»
    - refused: title «رفض {name} المستند», body «رفض {name} المستند «{title}»: {reason}»
  - **Deleting:**
    - «تم حذف المستند.» (piece 2's)
    - «لا يمكن حذف مستند بعد أن أجاب عنه الموظف.» (422)
  - **The filed list:**
    - «هذا الموظف خارج نطاق صلاحياتك.» (403, the employee policy's own)
    - «لا تملك صلاحية الاطلاع على هذه الوثيقة.» (403)
    - «مرفوض» (the label on a refused row)
- **Migrations:** pieces 1–3 are merged, so nothing is edited in place. New migrations are dated `2026_11_27_…`: one in `database/migrations/HR/` for `employee_documents.document_id`, foreignId nullable, `constrained('documents')`, `restrictOnDelete`, indexed.
- **Gates at the end of every task:** `php artisan test` (the full suite, run alone, never two at once) and `vendor/bin/pint --test`, both clean.

## Review Focus

1. **The employee taps «قرأت ووافقت» twice, or on two phones at once.** Expected: one mark, one printed copy, one filing row and one notification; the second tap answers «هذا المستند ليس بانتظار توقيعك.». Tested in Task 6.
2. **The sender deletes while the employee is signing.** Expected: exactly one wins under the row lock. A signed document is never deleted, and a deleted one is never signed or filed. Tested in Task 6.
3. **A reader with `hr.employees.view` but no `viewSalary` reaching the employee.** Expected: the list leaves out the contract, appointment, promotion and Sham Cash rows, and their files answer 403 even by a guessed id; another employee's row by id is a 404. Tested in Task 7.
4. **A recipient with no job title, no unit, or an employee number only.** Expected: a `for_employees` template prints those chips empty and sends; nothing fails. Tested in Task 2.
5. **The recipient tries the thread.** Expected: reading or writing the comments is 403, while the sender and `documents.view` keep the thread. Tested in Task 5.

---

## File map

| File | Task | What it holds |
|---|---|---|
| `database/migrations/HR/2026_11_27_100000_add_document_id_to_employee_documents_table.php` | 1 | `document_id` |
| `app/Modules/HR/Enums/EmployeeDocumentType.php` | 1 | the four cases, `forSentDocuments()`, `readsThroughSalary()` |
| `app/Modules/HR/Models/EmployeeDocument.php` | 1 | `document()`, the widened `scopeReadableWithout()` |
| `app/Modules/Documents/Services/HrSenders.php` | 1 | who is one of HR's managers |
| `app/Modules/Documents/Services/DocumentOriginals.php` | 2 | the upload or the printed template, with numbers (moved out of `DocumentCreation`) |
| `app/Modules/Documents/Services/DocumentSending.php`, `Requests/SendDocumentRequest.php`, `Support/SendDocumentInput.php` | 2 | sending |
| `resources/views/documents/employee-signature.blade.php` | 3 | the signature block |
| `app/Modules/Documents/Services/RecordFiling.php` | 4 | the `employee_documents` row |
| `app/Modules/Documents/Policies/DocumentPolicy.php`, `Services/DocumentDeletion.php`, `Resources/DocumentResource.php`, `Controllers/DocumentController.php`, `Requests/DocumentsIndexRequest.php` | 5 | seeing, the `mine` box, deleting, the thread |
| `app/Modules/Documents/Services/DocumentAnswers.php`, `Requests/SignDocumentRequest.php`, `Requests/RefuseDocumentRequest.php` | 6 | signing and refusing |
| `app/Modules/Documents/Controllers/FiledDocumentController.php`, `Resources/FiledDocumentResource.php` | 7 | the record's filed list |

---

### Task 1: The data and who sends

**Files:**
- Create:
  - the migration of the file map;
  - `Services/HrSenders.php`.
- Modify:
  - `app/Modules/HR/Enums/EmployeeDocumentType.php`, `app/Modules/HR/Models/EmployeeDocument.php`;
  - `app/Modules/Documents/Models/Document.php` (`recipient(): BelongsTo` to `Employee`, `withTrashed`; `filing_type` cast to `EmployeeDocumentType`; the answer columns in fillable and casts; `filedRecord(): HasOne` to `EmployeeDocument`).
- Test: `tests/Feature/Documents/HrSendersTest.php`, `tests/Unit/HR/EmployeeDocumentTypeTest.php` (or the existing enum test if one exists)

**Interfaces:**
- Produces:
  - `EmployeeDocumentType::AppointmentDecision|PromotionDecision|WorkCertificate|Warning`;
  - `static forSentDocuments(): array<self>` (7, 8, 9, 10, 11, 12);
  - `readsThroughSalary(): bool` (4, 7, 9, 10). `isPaymentInstrument()` stays as it is.
  - `EmployeeDocument::document(): BelongsTo` (`withTrashed`); `scopeReadableWithout()` leaves out every `readsThroughSalary()` type.
  - `HrSenders` (final):
    - `hrUnit(): ?Department`: the live unit whose `code` is `config('leave.hr_unit_code')`;
    - `isSender(Employee $employee): bool`: `UnitManagers::unitOf($employee)` is HR or has HR among its ancestors (`Department::loadAncestors()`).

- [ ] **Step 1: Write the failing tests**
  - `test_the_hr_manager_and_a_manager_under_hr_send`
  - `test_a_manager_outside_hr_does_not_send`
  - `test_a_manager_of_an_inactive_unit_under_hr_does_not_send` (piece 2's inactive-unit rule, through `unitOf`)
  - `test_nobody_sends_without_an_hr_unit`
  - `test_the_new_types_and_their_labels`
  - `test_the_salary_types`: exactly 4, 7, 9 and 10 read through salary, and `readableWithout` leaves all four out.
  - `test_the_types_a_sent_document_may_be_filed_as`
- [ ] **Step 2:** Run the two files. Expect FAIL.
- [ ] **Step 3:** Implement. The migration's `down()` drops the foreign key and the column.
- [ ] **Step 4:** Run the files; PASS. Run the gates.

---

### Task 2: Sending a document to an employee

**Files:**
- Create:
  - `Services/DocumentOriginals.php`, `Services/DocumentSending.php`;
  - `Requests/SendDocumentRequest.php`, `Support/SendDocumentInput.php`.
- Modify:
  - `Services/DocumentCreation.php`: it calls `DocumentOriginals` instead of its own `storeFiles` and `printTemplate`; behaviour unchanged.
  - `Controllers/DocumentController.php` (`send`), `routes.php`;
  - `app/Shared/Support/NotificationEventCatalogue.php` (`document.awaiting_signature`).
- Test: `tests/Feature/Documents/SendDocumentTest.php`; piece 2–3's `CreateDocumentTest` and `CreateFromTemplateTest` must pass unedited.

**Interfaces:**
- Consumes: Task 1's `HrSenders`; piece 3's `TemplatePrinter`, `SystemValues`, `RecipientValues`, `ExportNumbers`, `ReferenceNumbers`, `FieldValues`; piece 1's `PdfIntake`, `PdfWorkspace`.
- Produces:
  - **`DocumentOriginals`** (final), its two methods called inside the caller's transaction (the template one claims numbers):
    - `storeUpload(UploadedFile $file, string $directory): StoredOriginal`
    - `printTemplate(DocumentTemplate $template, array $values, Department $register, ?int $typedExport, SystemValues $system, ?RecipientValues $recipient, string $directory): StoredOriginal`
    - `StoredOriginal` (final readonly, in `Support/`) carries `originalPath`, `?uploadedPath`, `?exportNumber`, `?reference`, `?cardFloor`, `?contentBottom`.
    - Move the code out of `DocumentCreation` as it stands (R4's double check, the claim order, the unique-violation mapping, the folder cleanup), so both workflows share one copy.
  - **`POST /api/documents/to-employee`** (`documents.send-to-employee`), fields:
    - `title`, `description`;
    - `recipient_id`;
    - `filing_type` (one of `forSentDocuments()`);
    - `file`, or `template_id` + `field_values` (+ `export_number` when typed).

    `approvers`, `import_number` and `attachments` are prohibited, each with its copy. 201 answers «تم إرسال المستند إلى الموظف.» with piece 2's `DocumentResource`.
  - **`DocumentSending::send(Employee $sender, User $by, SendDocumentInput $input): Document`.** Refusals first, before any write:
    1. the sender (403);
    2. the recipient (exists; Active; has an active account; not the sender);
    3. the template (live, active, `for_employees`), with its values checked;
    4. a typed صادر number already used.

    Then one transaction:
    1. the original: an upload, or the template printed with `RecipientValues` and the sender's إدارة's numbers;
    2. the row: `kind` ToEmployee, `origin`, `creator_id`/`creator_unit_id` the sender's, `register_unit_id` `registerOf(senderUnit)`, `recipient_id`, `filing_type`, `status` AwaitingSignature, `sent_at` now, plus the original's numbers, `card_floor` and `content_bottom`.

    After the commit: the recipient's notification.
  - **`RecipientValues`** for a template: the recipient's `full_name`, job title's name, unit's name and `employee_number`. A missing one is `''`.

- [ ] **Step 1: Write the failing tests**
  - `test_an_hr_manager_sends_an_upload_to_an_employee`: 201 with the copy; `kind` 2, status 4, `sent_at` set, `recipient_id`, `filing_type`, no approval row, `original.pdf` stored, the recipient notified with the exact title and body.
  - `test_an_hr_manager_sends_a_template_with_the_recipients_chips`: the printed original carries the recipient's name and employee number (pdftotext, skipped where absent, as piece 3's); the صادر number and reference come from the sender's إدارة.
  - `test_a_recipient_with_no_job_title_or_unit_still_receives_a_template` (Review Focus 4)
  - `test_only_hr_managers_send`: a manager outside HR gets 403 and nothing is written.
  - `test_the_recipient_rules`: self, not Active, no account, an inactive account and a missing one, each with its copy.
  - `test_the_filing_type_must_be_one_offered`: 4 (ShamCash) and 99 are refused.
  - `test_a_template_not_for_employees_is_refused`
  - `test_approvers_import_number_and_attachments_are_refused`
  - `test_a_failure_leaves_nothing`: a print failure leaves no row, no folder, and the counter and sequence unchanged.
- [ ] **Step 2:** Run the file. Expect FAIL. Run `CreateDocumentTest` and `CreateFromTemplateTest`: they pass before the move.
- [ ] **Step 3:** Move the original-producing code into `DocumentOriginals`, then run those two files again: they still pass, unedited. Then build sending.
- [ ] **Step 4:** Run the files; PASS. Run the gates.

---

### Task 3: Printing the employee's signature

**Files:**
- Create: `resources/views/documents/employee-signature.blade.php`.
- Modify: `Pdf/DocumentPrinter.php`, which draws `MarkKind::EmployeeSignature` marks with the new view and keeps cards exactly as they are.
- Test: `tests/Feature/Documents/DocumentPrinterTest.php` (new tests only)

**Interfaces:**
- Consumes: piece 2's `DocumentPrinter::print(Document): string`, `CardLayout::place(int, float, float, float $floor, ?float $contentBottom)`, `Signatures::bytes()`.
- Produces: `DocumentPrinter::print()` also prints the employee's signature mark, which carries `employee_name`, `signature_id`, `marked_at`, `confirmed_by`, and no stamp or unit. The signature image reaches mPDF through `imageVars`, as the cards' do.

- [ ] **Step 1: Write the failing tests**
  - `test_the_employees_signature_prints_on_the_last_page`: same page count as the original, and the text «قرأت ووافقت», the name and «رقم المستند: {id}» extract from it (pdftotext, skipped where absent).
  - `test_the_signature_block_follows_the_text_rule`: on an upload (`content_bottom` null) the block stays on the last page; on a template document whose `content_bottom` lies below the block's top, the block takes a new page.
  - `test_cards_print_as_before`: piece 2's card tests are unchanged and pass.
  - `test_no_decrypted_signature_is_left_on_disk`
- [ ] **Step 2:** Run the file. Expect FAIL.
- [ ] **Step 3:** Implement. Then write one sample PDF (an upload and a template with the letterhead's footer, each signed) into the plan's workspace for the controller's visual check, as pieces 2–3 did.
- [ ] **Step 4:** Run the file; PASS. Run the gates.

---

### Task 4: Filing on the record

**Files:**
- Create: `Services/RecordFiling.php`.
- Test: `tests/Feature/Documents/RecordFilingTest.php`

**Interfaces:**
- Consumes: Task 1's columns and types.
- Produces: `RecordFiling` (final) `file(Document $document): EmployeeDocument`, called inside the answer's transaction (Task 6). It writes the row of Global Constraints ("Filing on the record"):
  - from the printed copy when the document is Signed;
  - from the original when it is Refused;
  - anything else throws `LogicException`.

  `size_bytes` is read from the disk.

- [ ] **Step 1: Write the failing tests**
  - `test_a_signed_document_is_filed_from_its_printed_copy`: every column of the row, `issued_at` the Damascus day of `signed_at` (set `Carbon::setTestNow` near midnight to pin the timezone).
  - `test_a_refused_document_is_filed_from_the_copy_as_sent`
  - `test_a_pending_document_is_not_filed`: throws.
  - `test_the_filed_row_reads_its_document`: `document()` with the refusal reason.
- [ ] **Step 2:** Run the file. Expect FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the file; PASS. Run the gates.

---

### Task 5: Seeing, the boxes, the thread and deleting

**Files:**
- Modify:
  - `Policies/DocumentPolicy.php`:
    - `view` admits the recipient of a sent document;
    - new `comment` ability: `view`, but never the recipient;
    - `delete` is unchanged (the creator; the system administrator through the super-admin bypass); `DocumentDeletion` decides when;
    - new `answer` ability: the recipient, ToEmployee.
  - `routes.php`: the two comment routes take `can:comment,document`.
  - `Services/DocumentDeletion.php`: a ToEmployee document is soft-deleted directly (with `deleted_by`) while AwaitingSignature, under a lock on its row; otherwise 422 with the copy. Piece 2's path for documents between managers is unchanged.
  - `Controllers/DocumentController.php`, `Requests/DocumentsIndexRequest.php`: box `mine` (مستنداتي): ToEmployee documents whose `recipient_id` is the caller's employee, newest first, with the existing `status` and `search` filters.
  - `Resources/DocumentResource.php`, which adds:
    - `recipient` `{id, full_name}`, `filing_type` `{value, label}`;
    - `sent_at`, `signed_at`, `refused_at`, `refusal_reason`;
    - `can.comment`, `can.answer` (the recipient, while AwaitingSignature);
    - `can.delete` for a ToEmployee document: the sender or the system administrator, while AwaitingSignature.
- Test: `tests/Feature/Documents/DocumentsToEmployeesSeeingTest.php`

**Interfaces:**
- Consumes: Task 2's sent documents (or rows made directly in the tests).
- Produces: the policy abilities `view`, `comment`, `delete` and `answer` (Task 6's routes take `can:answer,document`); the `mine` box; the resource fields.

- [ ] **Step 1: Write the failing tests**
  - `test_the_recipient_sees_the_document_and_its_file`
  - `test_the_mine_box_lists_what_was_sent_to_me`: with the status filter; another employee's documents never appear.
  - `test_the_senders_outgoing_box_shows_who_answered`: recipient and status in each row.
  - `test_the_recipient_is_kept_out_of_the_thread` (Review Focus 5): GET and POST comments are 403 for the recipient; the sender and `documents.view` read and write.
  - `test_the_sender_or_the_administrator_deletes_before_an_answer`: soft-deleted with `deleted_by`, gone from every box; the recipient's show then answers 410 «حُذف هذا المستند.».
  - `test_an_answered_document_is_not_deleted`: signed and refused, each 422 with the copy.
  - `test_documents_between_managers_are_unchanged`: piece 2's seeing and deleting tests pass unedited.
- [ ] **Step 2:** Run the file. Expect FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the file; PASS. Run the gates.

---

### Task 6: Signing and refusing

**Files:**
- Create: `Services/DocumentAnswers.php`, `Requests/SignDocumentRequest.php`, `Requests/RefuseDocumentRequest.php`.
- Modify:
  - `Controllers/DocumentController.php` (`sign`, `refuse`), `routes.php`;
  - `app/Shared/Support/NotificationEventCatalogue.php` (`document.signed`, `document.refused`).
- Test: `tests/Feature/Documents/AnswerDocumentTest.php`

**Interfaces:**
- Consumes:
  - Task 3's printer, Task 4's `RecordFiling`, Task 5's `answer` ability;
  - piece 1's `Signatures::current(Employee): ?EmployeeSignature`, `SignatureRequiredException` (428);
  - piece 2's `Confirmation` and its request rules, which the two new requests share.
- Produces:
  - **Routes**, both `whereNumber`, `withTrashed` (a deleted document answers 410 to its recipient), `can:answer,document`:
    - `POST /api/documents/{document}/sign`: `confirmed_by`, `password`;
    - `POST /api/documents/{document}/refuse`: `reason` (3–500, trimmed), `confirmed_by`, `password`.
  - **`DocumentAnswers::sign(Document $document, User $by, Confirmation $confirmedBy, ?string $password): Document`.** In order:
    1. status AwaitingSignature (422);
    2. the caller's current signature (428 `signature_required`);
    3. the password when `confirmed_by` is 2.

    Then one transaction:
    1. lock the document row and check the status again;
    2. add the mark (position 1, `signature_id` the current version);
    3. print, and set `printed_path`; a failed print rolls everything back and deletes the new file, as piece 2;
    4. status Signed with `signed_at`;
    5. `RecordFiling::file()`;
    6. after the commit, the sender's notification.
  - **`DocumentAnswers::refuse(Document $document, User $by, string $reason, Confirmation $confirmedBy, ?string $password): Document`.** The same checks, without the signature. Then one transaction: lock, check again, status Refused with `refused_at` and `refusal_reason`, `RecordFiling::file()`, and after the commit the sender's notification. Nothing is printed.
  - **`DocumentResource`'s `can.answer`** turns false after either answer.

- [ ] **Step 1: Write the failing tests**
  - `test_the_recipient_signs`: 200 with the copy. Status 5, `signed_at`, one mark of kind 3 naming the signature version and `confirmed_by`, `printed_path` set, one filing row from the printed copy, and the sender notified with the exact title and body.
  - `test_the_first_signature_is_drawn_first`: with no signature, 428 and nothing written; after `POST /api/me/signature`, the same request signs.
  - `test_a_password_confirmation_is_checked`: a wrong password gives 422 «كلمة المرور غير صحيحة.» and nothing is written.
  - `test_the_recipient_refuses_with_a_reason`: 200. Status 6, `refused_at`, `refusal_reason`, no mark, no printed copy, one filing row from the original, and the sender notified with the reason.
  - `test_a_reason_is_required`: empty, 2 characters and 501 characters, each with the copy.
  - `test_only_the_recipient_answers`: the sender and `documents.view` get 403; another employee gets 403.
  - `test_an_answer_is_final` (Review Focus 1): sign then sign, sign then refuse, refuse then sign — each second request gives 422 «هذا المستند ليس بانتظار توقيعك.», with one mark, one filing row and one notification. Then the second phone: call `DocumentAnswers::sign()` with a model instance loaded BEFORE the first answer (stale, still AwaitingSignature); the check under the row lock refuses it.
  - `test_a_delete_and_a_signature_do_not_both_win` (Review Focus 2): with both models loaded before either acts, sign then delete, and delete then sign; the second gets its 422 (deletion's copy, or «هذا المستند ليس بانتظار توقيعك.»), and no deleted document is filed.
  - `test_a_print_failure_rolls_the_signature_back`: bind a `DocumentPrinter` that throws. Status 4, no mark, no filing row, no file left.
  - `test_a_document_between_managers_cannot_be_signed_here`: 403.
- [ ] **Step 2:** Run the file. Expect FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the file; PASS. Run the gates.

---

### Task 7: The record's filed documents

**Files:**
- Create: `Controllers/FiledDocumentController.php`, `Resources/FiledDocumentResource.php`.
- Modify: `app/Modules/HR/routes.php` (beside piece 1's signature routes).
- Test: `tests/Feature/Documents/FiledDocumentsTest.php`

**Interfaces:**
- Consumes: Task 1's types and scope; `EmployeePolicy::view`; `RequestRowScopes`.
- Produces:
  - **`GET /api/hr/employees/{employee}/filed-documents`** (`can:view,employee`): every live `employee_documents` row of that employee, newest `issued_at` first (then id), paginated (`ApiRespond::perPage`). The rows that `readsThroughSalary()` are left out unless the caller's `hr.employees.viewSalary` grant reaches the employee. **Each row:**
    - `id`, `type` `{value, label}`, `title`, `issued_at`;
    - `original_name`, `mime_type`, `size_bytes`;
    - `document`: `{id, status {value, label}}` with `refused` (bool) and `refusal_reason`, or null for a row not filed from a document;
    - `refused_label`: «مرفوض» when refused, else null.
  - **`GET /api/hr/employees/{employee}/filed-documents/{employeeDocument}/file`** (`can:view,employee`, `scopeBindings`, so another employee's row is a 404): streams the row's `file_path` from the `local` disk with `Content-Type` its `mime_type`, `Content-Disposition: inline`, and `Cache-Control: private, no-store`. A salary-gated row without the reach answers 403 «لا تملك صلاحية الاطلاع على هذه الوثيقة.».

- [ ] **Step 1: Write the failing tests**
  - `test_hr_reads_an_employees_filed_documents`: a signed and a refused document filed, plus an imported ID image, all listed with their fields, the refused one with «مرفوض» and its reason.
  - `test_salary_rows_need_the_salary_reach` (Review Focus 3): a reader with `hr.employees.view` but no reaching `viewSalary` sees neither the contract, the appointment, the promotion nor the Sham Cash row, and gets 403 on each file by id; with the reach, sees them.
  - `test_another_employees_row_is_not_found`
  - `test_a_reader_out_of_reach_is_refused`: 403 with the policy's copy.
  - `test_the_file_streams_privately`: the headers.
- [ ] **Step 2:** Run the file. Expect FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the file; PASS. Run the gates.

---

### Task 8: Docs, Postman, the work order

- [ ] **Step 1:** `docs/API_DOCUMENTATION.md`:
  - a new §20.17 *Documents to employees*: sending, the `mine` box, signing and refusing, the 428 then draw then resend flow, deleting, the thread rule, the statuses;
  - §8 (HR): the filed-documents endpoints and the salary rule;
  - the resource's new fields;
  - the notification types;
  - quick-reference rows.
- [ ] **Step 2:** Postman: a folder "Documents - to employees" with every new request, keeping the TAB formatting. Validate the JSON.
- [ ] **Step 3:** Work order §8: a dated entry (the day it is built) on piece 4 and the calls below.
- [ ] **Step 4:** `docs/START_HERE.md`: piece 4's line.
- [ ] **Step 5:** Run the gates. Stop.

---

## Calls made in this plan, for the owner to check

1. **The filing types offered when sending** are Contract, the four new ones and Other. The ID images, certificate, Sham Cash photo, personal photo and CV are intake files, not documents an employee signs.
2. **A document to an employee carries no وارد number and no supporting attachments.** The design gives them to documents between managers (§7.1, §7.5) and lists neither for sending (§9.1).
3. **The employee's signature block is drawn in a frame of the cards' size**, centred at the foot of the last page, with the cards' new-page rules, piece 3's footer floor, and the text rule. The design fixes what it shows (§11.4), not its frame.
4. **Sending has its own endpoint**, `POST /api/documents/to-employee`, rather than sharing `POST /api/documents` with documents between managers: the two take different fields and refuse different things.
5. **The filed list shows every row of the record**, imported intake files included, behind the record's rules, as §9.4's "the Sham Cash photo as today" implies.
6. **Comments are refused to the recipient**, whatever else they hold, as call 5 of the design says.
7. **A recipient who stops being Active after a document is sent** can still answer while they can sign in. The checks at sending are the design's (D18.2); nothing in the design takes a sent document back.
8. **The filed list's paths are `/api/hr/employees/{employee}/filed-documents…`**, beside piece 1's signature routes on the HR employee, rather than spec §16's `/api/employees/{id}/filed-documents` — the record's routes live under `/api/hr`.
