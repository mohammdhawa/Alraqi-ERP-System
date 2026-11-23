# Documents — design

> **Status:** design, written 2026-11-22 from the closed decision round,
> **approved by the owner on 2026-11-22**. Not built.
> **The decisions it builds on:** every `Answer:` line of
> [`DECISION_ROUND_DOCUMENTS.md`](../../DECISION_ROUND_DOCUMENTS.md) (D1–D32,
> closed 2026-11-22), referred to here as **D*n***, and the carried items of its
> §I, referred to as **I.*n***. Where this design and the round disagree, the
> round wins and this file is wrong.
> **Decided while designing (2026-11-22):** templates are made with **a page
> editor on the dashboard** (§3.2, option A). How documents use the approvals
> engine is the designer's call, bound by the owner's instruction to leave the
> engine alone (§3.1). The owner's answers to the builder check, and his
> clarification of **four separate workflows** (§1.4), are in §19. That
> clarification revises D19.1, D25, D30.1 and I.1 of the round: **a document
> HR sends to an employee never goes through approvals**, and **managers sign
> in order only**.
> **Built as eight pieces** (§17), each with its own plan.

---

## 1. Intent

**What it is for.** The ERP replaces legacy's document module entirely, and
legacy's decided documents move across (2026-11-21). A document is a PDF that
managers sign: a purchase request, an invoice, a memo, an HR request, uploaded or
filled from one of the company's forms. A document can also be **sent to one
employee** (a contract, an appointment decision, a work certificate, a warning),
who reads it and signs «قرأت ووافقت» or refuses with a reason. Every signature is
**printed on the PDF**: a manager's with their unit's official stamp, an
employee's at the bottom. The same release replaces legacy's broadcast with
**announcements**.

**Who it is for.**

- **Managers** at every level, who create documents, name the managers who must
  sign, and sign others' documents.
- **المدير العام**, whose decision is final on every document between managers.
- **HR's managers**, who send documents to employees.
- **Every employee**, who receives documents in the app and signs them.
- **The system administrator**, who uploads the units' stamps, builds the
  templates, can delete a signature or a pending document, and alone reassigns a
  document's pending step.

**Why now.** The owner ruled on 2026-11-21 that the release waits for document
approvals, with HR and the performance evaluation. Legacy is retired at the
release, so nothing of its document module can be left behind.

**What success looks like.**

- A manager uploads a PDF or fills a template on their phone, names three
  managers, in the order they sign, and sends it. Each signs from the app with a fingerprint;
  after each decision the PDF carries a new card with the unit's stamp and the
  manager's signature. المدير العام decides last, and the creator is told.
- A manager whose unit has no stamp yet is told so when they try to sign, and
  their step waits until the administrator uploads it.
- HR sends Ahmed his contract. Ahmed taps «قرأت ووافقت», confirms with his face,
  and draws his signature the first time. The contract is printed with his
  signature and appears on his record under «عقد العمل».
- The 66 or so decided legacy documents are in the ERP with their printed PDFs.
  Each of the 20 people on them sees their own, and صادر numbers and references
  carry on from the paper register.
- No file is reachable by link. Nobody can download anyone else's signature.

### 1.1 Goals

- Everything the round decided, in the employee app and on the dashboard (D12).
- Legacy's behaviour wherever the round did not change it (I.1–I.8).
- Templates the administrator builds and edits without a developer or a release
  (D8, D32.3).

### 1.2 Non-goals

Each of these is a ruling, not an omission:

- A check code or QR code on signed PDFs, or a public check page (D20.2).
- Circulars to many employees. A document goes to **one** employee (D18.2,
  D20.1). Company-wide messages are announcements (§12).
- Templates limited to some units (D32.2), or fixed approval routes per template
  (D5.3).
- Templates that change the employee's record when approved, such as a transfer
  moving the employee. They stay documents; the HR round decides (D9.1).
- Payroll, السلف, العهد (2026-11-21). The pay templates stay documents (D9.3).
- Word files (I.12).
- Approvals on a document HR sends to an employee, and a stamp or HR's signature
  on one (§1.4, 2026-11-22).
- Managers signing in parallel (§1.4, 2026-11-22).
- Any change to how the engine decides, to `ManagementChain`, to decision 15
  (D28), or to the leave and shift swap workflows. The one addition to the
  approvals module is §7.4's visibility rule, which leave and swaps do not use.

### 1.3 Anti-goals

The work fails, even if it runs, if:

- a signature or stamp can be printed without the person's confirmation, or
  copied out of the ERP as a file;
- a later change of stamp, signature or manager alters a PDF already signed
  (I.10);
- leave or shift swaps behave differently afterwards (D28);
- a numbered document loses its number from the register (D31.3);
- sending an HR document to an employee starts a management approval, or asks
  the sender to name an approver (§1.4).

### 1.4 Four workflows, kept apart (the owner, 2026-11-22)

| | Workflow | Where | What it is |
|---|---|---|---|
| **1** | **An HR document to an employee**: an employment confirmation, a warning, an official notification | Documents (§9), **outside the approvals engine** | One of HR's managers sends it **directly** to one employee. No manager approves it, المدير العام included. The employee signs «قرأت ووافقت» (or refuses with a reason), and that completes it. The signed document is kept, filed on their record. **Only the employee's signature is printed** |
| **2** | **Leave requests** | Leave, built | The employee's direct manager, then the HR manager. **Unchanged** |
| **3** | **Shift swap requests** | Scheduling, built | As built. **Unchanged** |
| **4** | **A document needing management approvals**: a resource or supply request, a recruitment request, an operational advance, any administrative request | Documents (§7), **on the approvals engine** | A manager uploads or creates it and names the managers who must review, approve and sign it. They sign **one after another, in the order named**, and المدير العام signs last and decides. Then it is fully approved |

**An employee's acknowledgment is not a manager's approval.** Workflow 1 never
touches the engine. Its signature is a mark on the document (§9.2), not an
approval step.

---

## 2. Constraints

- **How the approvals engine decides, `ManagementChain`, and the leave and shift
  swap workflows are not changed** (D28; "Do not modify the previously
  implemented approval logic" and "Do not modify the existing leave or shift
  swap workflows", 2026-11-22). Documents use the engine as it is. The one
  addition to the approvals module is a visibility rule a subject may declare
  (§7.4, the owner's answer to §19 Q2), which leave and swaps do not declare, so
  nothing they do changes.
- **Legacy is the foundation** (2026-10-16): what it does is carried unless the
  round changed it (the round's §I).
- **One API for the app and the dashboard**, Arabic messages, per
  `docs/API_BUILD_STANDARD.md`. Every model uses `HasAuditLog`.
- **Files live on the private disk** and are streamed only to those who may see
  them (I.7). Signatures and stamps are also **encrypted** there (I.9).
- **Laravel 12 modular monolith.** Documents is a new module,
  `app/Modules/Documents`; announcements a new module, `app/Modules/Announcements`.
  The ERP has no PDF library today. This adds `mpdf/mpdf`, which bundles FPDI.
- **The one-unit rule** (D27): a person manages at most one unit, so "the
  manager's unit" is always one unit.

---

## 3. Approaches

### 3.1 Documents and the approvals engine (designer's call)

The engine was designed with documents as its second consumer
(`APPROVALS_ENGINE_DESIGN.md`). It has named approvers, parallel stages, the
advisory rejection rule and a decisive last step. What documents add is work
**between** steps: the missing-stamp hold (D21.2), the first signature (D24),
the confirmation (D16), and printing a card after each decision. The engine has
no hook for that.

| | Approach | Trade-off |
|---|---|---|
| **Chosen** | **Wrap the engine, unchanged.** Documents have their own approve and reject endpoints, which run the checks, call `ApprovalService::act()`, and print the card in the same transaction. An observer in the Documents module, on the engine's `ApprovalStep` model, refuses a decision on a document step made any other way, so the general approval endpoints cannot skip the checks | Nothing in how the engine decides changes. It depends on the engine saving steps through the model, which it does on purpose for the audit trail (C13), and a test pins it. The visibility rule of §7.4 is the one addition to the module |
| Set aside | A small optional hook inside the engine (`beforeStepDecided` / `afterStepDecided` on `Approvable`) | Cleaner code, but it changes the approval code the owner said to leave alone |
| Set aside | Legacy's own `document_workflows` tables, ported | A second engine and a second inbox. The rules the round settled for the engine (Q2 skips, reassignment, the inbox) would have to be built again |

### 3.2 Templates (the owner's pick, 2026-11-22)

| | Approach | Trade-off |
|---|---|---|
| **A, chosen** | **A page editor on the dashboard.** The administrator writes each form's page: the company's letterhead fixed, the body as formatted text with the fields placed in it, item tables as blocks. The server prints it | The administrator adds and changes forms without a developer, and the release does not wait for the forms (D32.1). A form with many boxes matches only as closely as tables allow. A form the editor cannot draw can have a hand-made layout instead, as legacy's طلب شراء has |
| B | A hand-made layout per form, from the company's Word or PDF files (legacy's way) | An exact match, but every new form or change needs a developer and a release |
| C | The blank form as background, with fields placed on it by position | Looks exactly like the paper, but text that grows cannot flow onto a next page |

### 3.3 The PDF library (designer's call)

Legacy uses three libraries: dompdf, mPDF for templates, and TCPDF with FPDI for
the cards. **This design uses mPDF alone.** It shapes Arabic and lays it out
right to left, renders the template pages from HTML, turns photos into PDFs, and
imports the pages of an uploaded PDF (through its bundled FPDI) to print marks on
them. Its limit is §11.5.

### 3.4 Legacy's decisions on moved documents (designer's call)

A moved document's approvals could become engine rows, or stay a **read-only
history** on the document. **History is chosen** (§14.3). Engine steps need a
live employee and a route, while some of legacy's people have left and are kept
by name only (D1). The documents screens read both.

---

## 4. Concepts

- **Document between managers** (code: *internal*; workflow 4 of §1.4): created
  by a manager, signed in order by the managers they name, decided by المدير
  العام.
- **Document to an employee** (code: *to employee*; workflow 1): created by one
  of HR's managers for one employee, sent directly, never through approvals, then
  signed or refused by the employee.
- **Original**: the uploaded PDF, the photo turned into a PDF, or the PDF printed
  from a template when the document is created. Never changed.
- **Printed copy**: the original with every **mark** printed on it. This is what
  everyone sees and downloads.
- **Mark**: one thing printed on a document. An **approval card** or a
  **rejection card** (a manager's decision, with the unit's stamp and the
  manager's signature), or the **employee's signature** («قرأت ووافقت»).
- **The decider**: the step whose decision ends the approval (the engine's
  `decisive` step): المدير العام. Only documents between managers have
  approvals.
- **A unit's إدارة**: the unit itself when it is an إدارة (level `Division`) or
  الإدارة العامة; otherwise the إدارة above it. It owns the صادر register (§8.4).
- **A unit's manager**: the employee in `departments.manager_id` of an active
  unit. **المدير العام** is the manager of the root unit (level
  `GeneralAdministration`). **HR** is the unit with code
  `config('leave.hr_unit_code')` (the code leave uses) and every unit under it.
  **The HR manager** is that unit's own manager.

---

## 5. Who may do what

| Act | Who | Ruling |
|---|---|---|
| Create a document between managers | A unit's manager, at any level, المدير العام included | D4 |
| Be named as an approver (documents between managers only) | A unit's manager who is active, has an active account, and is neither the creator nor المدير العام: he is always added last | D5.1, D21.3 |
| Send a document to an employee | The HR manager, or the manager of any unit under HR. Directly, with no approver | D29 (kept 2026-11-22), §1.4 |
| Decide a step | Its approver (the engine's rule: it is a turn, not a permission) | — |
| Sign or refuse a document sent to them | Its one recipient | D11, D17 |
| See a document | Its creator; anyone holding a step on it (decided or skipped steps included); its recipient, once it is sent to them; holders of `documents.view` | I.5 |
| Comment | Anyone who can see it, except its recipient | I.3; the recipient part is a call, §18 |
| Delete a document before its first signature | Its creator, or the system administrator | D7, D31.1 |
| Reassign a document's pending step | **The system administrator only**, not `approvals.reassign` | §19 Q3 (2026-11-22) |
| Build templates, categories, the letterhead | `documents.templates.manage` | D32.3 |
| See every document and the statistics | `documents.view`, company-wide | I.5, I.8 |
| Upload or replace a unit's stamp | **The system administrator only** | D23.1 |
| Delete an employee's signature | **The system administrator only**, never HR | D15 |
| Send announcements | The system administrator and the HR manager | D26.1 |
| Run the legacy import | The system administrator (a command) | — |

**"The system administrator"** is the built-in `super_admin` role
(`User::isSuperAdmin()`). The three rights marked "only" are **not permissions**.
They are checked with `isSuperAdmin()`, so no role editor can ever give them to
HR. The two new permissions, `documents.view` and `documents.templates.manage`,
are company-wide (not on `PermissionReach`'s allow-list) and no seeded role
carries them, as with `scheduling.*`.

---

## 6. Data model

All models use `HasAuditLog`. Documents, comments, templates, announcements and
the signature and stamp versions also use soft deletes.

### 6.1 `documents` (morph alias `document`, implements `Approvable`)

| Column | |
|---|---|
| `kind` | `DocumentKind`: Internal = 1, ToEmployee = 2 |
| `origin` | `DocumentOrigin`: Uploaded = 1, Template = 2, Legacy = 3 |
| `title`, `description` | description nullable |
| `creator_id` | the employee who created it |
| `creator_unit_id` | the unit they managed at creation: the "from" shown |
| `register_unit_id` | that unit's **إدارة** (§4): whose صادر register numbered it (§8.4) |
| `created_by` | the account |
| `template_id`, `template_version`, `field_values` (json) | template documents only |
| `export_number` | صادر, nullable; template documents only (D3.3). `unique(register_unit_id, export_number)` |
| `import_number` | وارد, nullable, typed by hand; `unique(register_unit_id, import_number)`, as legacy |
| `reference` | e.g. `RE-2026-0006`, nullable, unique; template documents only |
| `status` | `DocumentStatus` (below) |
| `recipient_id` | to-employee only |
| `filing_type` | `EmployeeDocumentType`, to-employee only (D20.3) |
| `sent_at` | when it reached the recipient |
| `signed_at`, `refused_at`, `refusal_reason` | the recipient's answer |
| `decided_at` | when the approval ended |
| `original_path`, `printed_path` | files on the private disk; `printed_path` null until the first mark |
| `deleted_by` | with `deleted_at` (D31.2: gone from lists, kept for the audit trail) |
| `legacy_id`, `legacy` (json) | moved documents only (§14): legacy's numbers with its department's name, and its sequential or parallel mode |

`DocumentStatus`: Pending = 1 (approvals running), Approved = 2 (final),
Rejected = 3, AwaitingSignature = 4 (with the recipient), Signed = 5,
Refused = 6. The flows:

```
between managers (workflow 4):  Pending ──► Approved | Rejected
to an employee   (workflow 1):  AwaitingSignature ──► Signed | Refused
```

A document between managers has `status` written only by
`onApprovalDecided()` (the engine's rule). A document to an employee has no
approval: its `status` is written by sending and by the employee's answer.

### 6.2 `document_marks`

What is printed on a document, in order.

| Column | |
|---|---|
| `document_id`, `position` | the order of printing |
| `kind` | `MarkKind`: ApprovalCard = 1, RejectionCard = 2, EmployeeSignature = 3 |
| `approval_step_id` | the cards only |
| `employee_id`, `employee_name` | the signer, with the name as printed |
| `unit_id`, `unit_name` | the cards only: the unit the manager managed when signing, with the name as printed |
| `stamp_id` | the cards only: the **version** of the unit's stamp printed (§10.2) |
| `signature_id` | the **version** of the signature printed (§10.1) |
| `decisive` | printed blue, as legacy's chief card |
| `note` | the manager's note, on the card |
| `confirmed_by` | `Confirmation`: Biometric = 1, Password = 2 (D16) |
| `marked_at` | |

The printed copy is always rebuilt from the original and these rows (§11.3).
Rows are never changed or deleted.

### 6.3 The others

| Table | Columns, in short |
|---|---|
| `document_comments` | `document_id`, `employee_id`, `body` (I.3) |
| Supporting attachments | the shared `attachments` table through `AttachmentStore`, attachable = the document, up to 10 (I.4) |
| `document_legacy_steps` | moved documents only: `document_id`, `position`, `role` (manager / chief), `legacy_name`, `employee_id` nullable, `decision`, `note`, `signed_at` (§14.3) |
| `document_templates` | `name`, `category_id`, `description`, `is_active`, `for_employees`, `reference_prefix`, `fields` (json), `body` (sanitised HTML) or `layout_key` (a hand-made layout), `show_letterhead`, `version`, `legacy_id` |
| `document_template_categories` | `name`, `sort` |
| `document_counters` | `unit_id` unique (an إدارة, or الإدارة العامة), `current_value`: the صادر register's counter |
| `document_reference_sequences` | `prefix`, `year`, `current_value`, unique (`prefix`, `year`), as legacy |
| `document_letterhead` | one row: the header image, the footer image, uploaded by `documents.templates.manage` |
| `employee_signatures` | `employee_id`, `path` (encrypted file), `drawn_on` (app / dashboard), `created_at`; `deleted_at`, `deleted_by`, `delete_reason`. At most one live row per employee |
| `unit_stamps` | `department_id`, `path` (encrypted file), `uploaded_by`, `created_at`, `replaced_at` (null = the current one). Versions are never deleted |
| `employee_documents` (HR, exists) | gains `document_id` nullable: the document filed (§9.4). `EmployeeDocumentType` gains قرار تعيين = 9, قرار ترقية = 10, شهادة عمل = 11, إنذار = 12 (D20.3) |
| `announcements`, `announcement_recipients` | §12 |

---

## 7. Documents between managers (workflow 4)

### 7.1 Creating

The creator (a unit's manager) gives:

- a title, an optional description, an optional وارد number;
- **either** a file (a PDF, or a JPG/PNG photo turned into a one-page PDF;
  anything else refused with «يُرجى رفع الملف بصيغة PDF أو صورة.», I.12) **or**
  a template and its field values (§8);
- the approvers, **in the order they sign**: one or more unit managers (§5),
  each once. There is no parallel choice (§1.4, 2026-11-22, revising I.1);
- up to 10 supporting attachments.

On creation, in one transaction:

1. The original is stored: the file as uploaded, or the template's PDF printed
   now (legacy prints before the approvals start). A template document claims
   its صادر number and its reference here (§8.4).
2. The approval is submitted with the route:

   ```
   sequential(Advisory,
              named₁, named₂, …,                  // in the order named
              unitManager(root code)->decisive()) // المدير العام, always last (D5.2)
   ```

   The engine resolves and freezes every step (its §9 Q3), refuses a route with
   nobody to ask, and notifies the first approvers.
3. A file stored before a failure is deleted (`AttachmentStore`'s rule).

**When المدير العام is the creator** (D6.2, D30.2), the same route is used. The
engine skips his own step as the requester's (`SkipReason::Requester`), and with
no decider left it approves once every named manager has acted, rejections
recorded and changing nothing. That is exactly D30.2
(`APPROVALS_ENGINE_DESIGN.md`, "For November"). He must name at least one
manager. With none, the engine refuses: «لا يوجد من يعتمده».

### 7.2 Deciding

`POST /api/documents/{document}/decision` with `decision` (approve / reject), an
optional `note`, and the confirmation (§10.3). In order:

1. **Turn.** The caller holds the current pending step. The engine checks this
   again; this check comes first only so the next messages make sense.
2. **The caller's unit.** The unit they manage **now**. If they manage none, the
   decision is refused: «لا تدير وحدة تنظيمية حالياً، فلا يمكنك التوقيع على
   المستندات. يُرجى مراجعة مدير النظام لإعادة إسناد الخطوة.» Nobody signs or
   stamps for a unit they do not manage (D27.3, documents only).
3. **The stamp.** The unit has a current stamp. If not, the decision is refused
   with «لم يُرفع الختم الرسمي لوحدتك «…» بعد، ولا يمكن التوقيع قبل رفعه. تم
   إبلاغ مدير النظام.» The step stays pending (D21.2), and the system
   administrators are notified once per unit until a stamp is uploaded.
4. **The signature.** The caller has a current signature. If not, the answer is a
   distinct error (`signature_required`), the client opens the pad, stores the
   drawing (§10.1), and sends the decision again (D14, D24).
5. **Confirmation** (§10.3, D16.2).
6. **In one transaction:** open the guard for this step (§7.3), call
   `ApprovalService::act()`, add the mark (an approval or a rejection card: the
   rejection one carries the stamp and signature too, D22), and rebuild the
   printed copy (§11.3). If printing fails, the whole decision rolls back and the
   new file is deleted, as legacy's decision does.
7. **Words** (I.6, legacy's): the engine tells the next approvers through
   `describeForApprover()`: «مستند جديد بانتظار موافقتك» when submitted,
   «دورك للمراجعة» on their turn, and «جميع المدراء وافقوا — قرارك مطلوب» when
   the current step is the decider's. A manager's rejection that does not end it
   tells the creator «تم رفض المستند من مدير» (sent by this endpoint, since the
   engine tells the subject only at the end). `onApprovalDecided()` tells the
   creator «تمت الموافقة على مستندك» or «تم رفض مستندك».

A note is optional on both decisions, as legacy.

### 7.3 The guard on the engine's steps

The Documents module registers an observer on `ApprovalStep` (`updating`). For a
step whose approval's subject is a `document`:

- **A decision** (pending → approved or rejected) is allowed only while §7.2
  holds the guard open for that step id. Otherwise it throws `ApprovalException`
  «يُتَّخذ القرار في المستندات من شاشة المستند.» The engine's transaction rolls
  back, and the general endpoint answers 422.
- **A reassignment** (`approver_id` changes, through the general reassign
  endpoint) is refused unless the account doing it is **the system
  administrator** (§19 Q3, 2026-11-22; `approvals.reassign` alone is not
  enough), and the new approver is a unit's manager (D21.3). The decider's step
  may go only to **the root unit's current manager** (D5.2). That is the fix when
  المدير العام changes while documents are pending, since the engine froze them
  on the old one.
- Closing steps as skipped (the engine's `close()`) is not a decision and passes.

Piece 1 changed no file of the Approvals module; the 2026-11-23 addendum does
(`ApprovalService` for the cancel, `SkipReason` for case 6), by the owner's ruling
(§7.8). A test pins the engine's saving of steps
through the model, which the observer relies on.

### 7.4 The Approvable side

- `approvalRequester()`: the creator.
- `canBeApproved()`: always null. The checks are §7.2's.
- `onApprovalDecided()`: Approved means Approved; Rejected means Rejected.
  Only documents between managers have an approval. Cancelled means the creator withdrew through the general
  `/withdraw`, which is only possible before any decision, the same condition as
  deleting, so it **is** a deletion (§7.6). The administrator's close (§7.8) is the other
  Cancelled, and it is not a deletion: the document is status 7, kept on record.
- `approvalSummary()` / `approverRowDetails()`: the title, the kind, the creator
  and their unit; the row says when the approver's unit has no stamp, so the
  manager knows before tapping.

Documents therefore also appear in the engine's inbox («بانتظار قراري» and the
decided tab), and the tap opens the document screen.

They also appear in the engine's **other** screens, which read every subject
type:

- HR's list of all approvals, `GET /api/approvals` (`approvals.view`, in reach);
- the approval screen, `GET /api/approvals/{id}`, with every step, its approver
  and its note;
- the audit log's `approval_step` rows (`auth.audit.view`).

Left alone, a holder of `approvals.view` would read a document's title, its
creator, its chain and the notes there. **The owner ruled they must not**
(§19 Q2, 2026-11-22), as I.5 says. So the approvals module gains **one
optional rule a subject may declare**, `RestrictsApprovalVisibility`:

- the subject says who besides the parties may read its approvals: for
  `document`, holders of `documents.view`;
- `ApprovalPolicy::view` and HR's list (`GET /api/approvals`) consult it, and for
  such a subject let through only the requester, a step's holder, and those it
  names;
- **leave and shift swaps do not declare it**, so their screens, lists and every
  approval they make behave exactly as built. Their tests prove it;
- nothing in how the engine submits, decides, withdraws or reassigns changes.

The audit log shows every record to its readers (`auth.audit.view`, an
administrator's view), and is not counted.

### 7.5 Comments and attachments

As legacy (I.3, I.4): any person who can see the document (§5) comments, and all
of them see every comment. Supporting attachments, up to 10, are added by the
creator at creation and served as the document is.

### 7.6 Deleting

`DELETE /api/documents/{document}` by its creator or the system administrator,
while **nobody has signed it**: no step approved or rejected, and no employee
answer (D7, D31.1). Steps skipped at submission are not signatures.

- **Both** close the approval through the engine's own `withdraw()`. For the
  administrator it is called with the creator's account, since the engine lets
  only the requester withdraw and is not changed. The approval ends Cancelled,
  with its pending steps closed (`ClosedByCancellation`). `onApprovalDecided()`
  then soft-deletes the document, with `deleted_by` the account that asked. The
  audit trail records the administrator as the actor. This keeps the engine's
  rule that only `ApprovalService` writes an approval. Soft-deleting the
  approval from Documents was considered and dropped: it would leave a trashed
  approval still Pending.
- The document is gone from every list (D31.2). Its صادر number stays used, and
  the register shows it «محذوف» (D31.3, §8.4).
- An earlier notification that points at it opens «حُذف هذا المستند.», not an
  error.

There is no editing. A pending document is deleted and made again (legacy).

### 7.7 Lists

Every list paginates and searches by title, number and reference.

- **الواردة**: documents where I hold a step, pending first, then those I decided,
  and moved documents where I am in the history (§14.3).
- **الصادرة**: documents I created.
- **مستنداتي**: documents sent to me (§9).
- **All documents** (`documents.view`): filter by kind, status, unit, creator,
  date and template.

### 7.8 Closing a stuck document: «لم يُستكمل» (the owner, 2026-11-23)

The engine freezes a route when the document is sent, so a change of المدير
العام while documents are pending needs the system administrator.

- **When the new المدير العام is not on the document:** the administrator
  reassigns the decisive step to him (§7.3).
- **When he holds a named step not yet signed:** the administrator first
  moves that step to his former unit's new manager, then gives him the
  decisive step.
- **When he already signed a named step, or created the document:** the
  engine cannot give him the decisive step. The administrator **closes the
  document as «لم يُستكمل»** (not completed), with a reason. The closed
  document keeps its signatures, cards, printed copy and numbers on record
  and stays in every list; its approval ends Cancelled; its creator is told,
  and sends a new document. A closed document's وارد number is free again
  for the document sent in its place. Its صادر number is not (D31.3).
- The administrator may close any pending document this way. It is the
  engine's one further addition for documents: an administrator's
  cancellation that, unlike the requester's withdrawal, is allowed after
  decisions. Leave and shift swaps never call it.

**Documents needing attention:** a list for the administrator of the pending
documents no one can move as they stand: the decisive step held by someone
who is no longer المدير العام, or the current step held by someone who
manages no active unit or whose employee record was deleted. A deleted
المدير العام reads as a vacant post. Each row says which, so the administrator applies
the fix above or closes the document.

---

## 8. Templates

### 8.1 What a template is

A name, a **category** (D32.2), a description, active or not, and
**`for_employees`**, which puts it on HR's send screen and allows the recipient's
placeholders. It also has a **reference prefix** (e.g. `RE`), its **fields**,
and its **page**.

The **fields** are legacy's six types: text, long text, number, date, a list to
choose from (options), and a table (columns), each with a label, a key and a
required flag.

The **page** is the body the administrator writes in the editor, with
`show_letterhead`. Instead of a body, a template can name a **hand-made layout**
(`layout_key`, a Blade view in the module), legacy's escape hatch for a form the
editor cannot draw.

### 8.2 The page editor (dashboard)

The administrator writes the page in a rich-text editor: headings, paragraphs,
bold, italic and underline, alignment, lists, font size, tables with borders, a
line, a page break. **Placeholders** are dropped in as chips:

- the template's own fields; a table field becomes a table with its columns and
  the rows the creator filled;
- the system values: صادر number, وارد number, reference, date, the creator's
  name and unit;
- with `for_employees`, the recipient's name, job title, unit and employee
  number.

The letterhead (a header and a footer image, uploaded once) frames every page
when `show_letterhead` is on. **Preview** prints the page as a PDF with sample
values. The server stores the body as HTML **sanitised to the editor's
whitelist**, and renders it with mPDF.

Editing a template bumps its `version` and changes only documents made
afterwards: a document keeps its values and the PDF printed when it was made. A
template that has been used is never deleted, only deactivated.

### 8.3 Choosing and filling

The creator filters by category and searches by name (D32.2), fills a form drawn
from the fields (app and dashboard), and the server prints the original at
creation. طلب إجازة is inactive: its old documents move, no new ones are made
(D9.2). The pay templates and the HR templates stay ordinary documents (D9.1,
D9.3).

### 8.4 Numbers

- **صادر** (D3.2, D3.3): only template documents, from **the register of the
  creator's إدارة** (§4; §19 Q1, 2026-11-22), as legacy: a section's or an
  office's manager numbers from their إدارة's register, and المدير العام from
  الإدارة العامة's own. A register's **first** number is typed by hand, from the
  paper register, and the counter carries on from it (legacy's cold-start rule). After
  that the next number is offered, and the creator may type another when the
  register is ahead: a number already used in that register is refused, a higher
  one moves the counter forward, and a lower one is taken as it is (legacy's
  `DocumentCounterService`). A deleted document's number is never reused (D31.3).
  The dashboard's **سجل الصادر** for an إدارة lists every number with its date,
  title, creator and status, «محذوف» included. The إدارة's manager and
  `documents.view` see it.
- **وارد**: an optional number typed by hand, no counter (D3).
- **Reference**: template documents only, `PREFIX-YEAR-SEQ` per template prefix
  and year, claimed under a lock (legacy's `generateDocumentNumber`). The
  import carries legacy's sequences on (D3.1).
- Numbers are printed where the template's page places them.

---

## 9. Documents to employees (workflow 1)

**This workflow never touches the approvals engine** (§1.4, the owner,
2026-11-22). It has no approval, no approver to name, and no route. The
engine's rule that skips the sender's own step therefore never applies to it.
That ruling revises the round:

- **D19.1**: no longer "straight or through approvals". Always directly.
- **D25**: a document to an employee carries **only the employee's signature**.
  No stamp, and no signature of the sender, is printed on it (§19 Q5,
  2026-11-22).
- **D30.1** has no case left: there is no approver on such a document.
- **D29 stands**: the HR manager and the managers of HR's sections send.

### 9.1 Sending

One of HR's managers (§5) creates the document by upload or from a
`for_employees` template, and gives:

- **the recipient**: one active employee with an active account, not the sender
  (D18.2);
- **the filing type** (D20.3), from `EmployeeDocumentType`.

On sending, the document is AwaitingSignature with `sent_at`, and the recipient
is told. Sending is not a signature: it needs no stamp, no drawn signature of
the sender, and no fingerprint. A template document takes its صادر number and
reference as any other (§8.4), from the sender's إدارة.

### 9.2 The employee's side

The notification «مستند جديد بانتظار توقيعك» opens the document. Two buttons:

- **«قرأت ووافقت»**: there is no forced reading (D16.3). The first time, the
  employee draws a signature (D14). The signature is confirmed (§10.3, D16.1).
  The signature block is printed (§11.4) and the status becomes Signed. The
  document is filed (§9.4), and the sender is told «وقّع … على المستند».
- **«رفض»**: a reason is required (D17), confirmed the same way (a call, §18).
  Nothing is printed. The status becomes Refused, which is closed (I.13): HR
  sends a new document if one is needed. The document is filed marked refused,
  and the sender is told, with the reason.

### 9.3 Deleting

Until the employee signs or refuses, by the sender or the system administrator
(D7, D31). There is no approval to close: the document is soft-deleted, gone
from every list, its صادر number never reused.

### 9.4 Filing on the record (D20.3)

At signing or refusal, an `employee_documents` row is written: the employee, the
type HR picked, the title, the file (the printed copy when signed, the copy as
sent when refused), `issued_at` = the day of the answer, and `document_id`.
Refused and its reason are read through the document.

The ERP has the `employee_documents` table but no screen listing it. This work
adds a **read-only list of an employee's filed documents** on the dashboard's
employee page: open, download, «مرفوض» with the reason. The HR round may extend
it.

**Who reads it: the record's rules**, not §5's. D20.3's own example is "HR
opening Ahmed's record sees it without going to the documents screens", so a
filed document is read by whoever may read that employee's record
(`hr.employees.view`, in reach). D20.3 makes that the one exception to I.5. The
row points at the document's printed file; nothing is copied.

**What states pay sits behind `hr.employees.viewSalary` too** (§19 Q6,
2026-11-22): the Sham Cash photo as today, and **عقد العمل, قرار تعيين and
قرار ترقية**. `EmployeeDocumentType` says which types these are, and the list
leaves them out for a reader without the permission.

---

## 10. Signatures, stamps and confirmation

### 10.1 Signatures

- **Drawn** on a pad in the app or on the dashboard (D24), the first time one is
  needed (D14). It is stored as a PNG, **encrypted** with the app key on the
  private disk (I.9).
- **One live signature per employee.** Its owner can see it (app «توقيعي»,
  read-only) and cannot delete, replace or redraw it (D15). Nobody can download
  anyone else's.
- **The system administrator deletes** one, from the employee's page on the
  dashboard, with a reason. The row is soft-deleted and kept with its dates. The
  owner draws a new one at their next signing (D15).
- A mark points at the version it printed, so a deletion changes no PDF (I.10).

### 10.2 Stamps

- **Every unit has its own** (the owner's rules, §H of the round). The system
  administrator uploads and replaces it on the dashboard (D23.1). A PNG with
  transparency is advised. It is stored encrypted, and versions are kept.
- **The company's seal is الإدارة العامة's stamp** (D23.2). Nothing separate is
  printed.
- The dashboard lists **the units without a stamp**, so every stamp can be
  uploaded before the release (D21.2).
- Legacy's 20 section stamps are offered during the import (§14.5).

### 10.3 Confirming a signature (D16)

Every mark (an approval, a rejection, «قرأت ووافقت») and an employee's refusal
is confirmed:

- **In the app**: the phone's fingerprint or face (`local_auth`), or the account
  password on a phone with neither. The request says which (`confirmed_by`), and
  a password is verified by the server.
- **On the dashboard**: the password, verified by the server.

The server cannot see a fingerprint. A biometric confirmation is enforced by the
app and recorded as the app reports it. This is the limit of D16 on any phone,
not a choice.

---

## 11. Printing

### 11.1 Uploads

PDFs are kept as they are, or converted when they must be (§11.5). Photos
(JPG/PNG) become a one-page A4 PDF on upload. Everything else is refused (I.12).
Size limit: a call, §18. **Every PDF is opened by the printing routine when it is
uploaded**, so no signature can later fail on its file.

### 11.2 Template documents

mPDF prints the template's page with the letterhead and the values, A4, in an
Arabic font with full shaping (legacy uses Amiri), when the document is created.
That PDF is the original.

### 11.3 The printed copy

It is rebuilt from **the original plus every mark**, each time a mark is added.
The original's pages are imported as they are, and the marks are drawn on the
last page, or on a new page when they do not fit. A mark names the exact stamp
and signature versions it printed, so a rebuild draws the same picture, and a
later change of stamp, signature, manager or unit name changes nothing already
signed (I.10). The original is kept beside it (I.2). Images reach mPDF in memory
(`imageVars`). mPDF still writes a PNG with transparency, and its mask, to its
temp directory while it prints, and pad signatures and transparent stamps are
such PNGs. So **each print gets its own temp directory on the private disk**,
deleted when the print ends, success or failure. A decrypted image never
outlives the print that used it.

### 11.4 What a mark looks like

- **Cards** (I.2, D22): legacy's card geometry (45 × 25 mm, 5 mm apart, centred
  rows at the foot of the last page, a new page when the block would rise above
  55% of the page). Each card has the unit's stamp, the manager's signature, the
  name, the unit, the date and the note. A rejection is red and a decider's card
  blue, as legacy. Cards print in step order, the decider last.
- **The employee's signature** (D11): at the foot of the last page, «قرأت
  ووافقت», the signature, the name, the date and time, with the same rule for a
  new page. A document to an employee has no cards (§9), so it is the only mark
  on one.
- **The system number** (a call, §18): every card and the signature block also
  print «رقم المستند: {id}», so HR can find any paper by its number (D20.2),
  uploaded documents included, since those have no صادر number (D3.3).

### 11.5 PDFs the printing cannot open

FPDI's free parser, which legacy uses too, cannot open some PDFs: those saved
with compressed cross-reference streams, common from Word's "Save as PDF" and
from scanners. In legacy, approving such a document fails. Here the upload check
of §11.1 catches it, and **the server converts it** (§19 Q4, 2026-11-22):

- `qpdf` (or Ghostscript) rewrites the file without the compressed
  cross-reference streams. The rewritten file looks the same and becomes the
  original that prints. The file as uploaded is kept beside it.
- If the conversion fails, or the result still cannot be opened, the upload is
  refused: «تعذّر تجهيز هذا الملف للتوقيع. يُرجى حفظه من جديد بصيغة PDF (مثل
  Microsoft Print to PDF) ثم رفعه.»
- **The production server must have `qpdf` or Ghostscript installed.** This is a
  deployment requirement of piece 1 (§17). The tool's path is configuration.

---

## 12. Announcements (D26)

- **Who sends**: the system administrator and the HR manager, on the dashboard
  (the dashboard only is a call, §18).
- **To whom**: every active account, the managers only (the manager of any
  active unit), or one unit **with every unit under it** (D26.2, §K). The
  recipients are fixed when it is sent.
- **What**: a title, a text, and up to 10 files and images (D26.3). Since nothing
  is printed on them, any common type is accepted.
- **Delivery**: a notification to each recipient, as a push and in the list. The
  tap opens the announcement: the text, the images shown, the files to open.
  `announcement_recipients` holds who may open it.
- **Afterwards**: no editing. The sender or the system administrator can delete
  one, and it is gone from the recipients' lists. Each sender sees what they
  sent, with the number of recipients.
- Legacy's broadcasts do not move.

---

## 13. One unit per manager (D27.1, D27.2)

- **Departments**: saving a unit with a manager who already manages another
  unit, active or not, is refused, naming that unit, on every write path. It is a model
  guard, like `DepartmentHierarchyGuard`.
- **The employee import** does not name unit managers today. It keeps
  «المدير المباشر بداية التعيين» as free text (`legacy_manager_name`) and never
  sets `departments.manager_id`. So D27.2 (the first row wins, the second post is
  left vacant) has nothing to apply to yet. It applies the day the import names
  managers, which is HR work, not this work.
- **Existing data**: before the guard is switched on, a check lists everyone who
  manages more than one unit, for HR to fix.
- `ManagementChain`, decision 15 and leave's routing are not touched (D28).

---

## 14. Moving legacy's documents

### 14.1 How it runs

An artisan command, run at the switch on a **fresh copy** of legacy's database
and its public-disk files. There is no date yet (D13), and the import is the
same either way. A **dry run** reports what would move and every problem; the
real run is idempotent by `legacy_id`.

### 14.2 People (D1)

The dry run writes the list of the legacy accounts on documents (about 20) to a
file. HR fills in each one's ERP employee number. Someone who has left is left
blank and kept **by name only**. The real run reads that file and refuses to
start while a row is unclear. A file rather than a screen is a call, §18.

### 14.3 What moves

- **Only decided, non-deleted documents** (D2). The owner deletes the pending
  ones in legacy first.
- Title, description, origin, template and values, status, dates; the
  **original and the printed PDF**, onto the private disk (I.7). They are
  **never printed again** (I.11), and `origin` is Legacy. Legacy's sequential or
  parallel mode is kept in `legacy` (2 of 92 were parallel). New documents have
  no parallel mode.
- **Numbers**: the صادر and وارد numbers as printed, with the legacy department's
  name, in `legacy`. They count in no ERP register (D3.2). The reference goes
  into `reference`.
- **The decisions**, as a read-only history (`document_legacy_steps`, §3.4): the
  legacy name, the matched employee, manager or chief, the decision, the note,
  the date.
- Comments (with the matched author, or the name) and attachments.
- **Who sees a moved document**: its matched creator, the matched people in its
  history, and `documents.view`.

### 14.4 Templates and sequences

Legacy's 20 templates move with their fields, a category inferred from their
`type`, and their prefix. Each gets **a starting page** built from its fields
(the title, then a "label: value" line per field, tables as tables). The
administrator edits these into the company's forms when the forms arrive
(D32.1). طلب شراء keeps its own layout, ported as a hand-made layout. طلب إجازة
moves inactive. The reference sequences are set from legacy's
`document_number_sequences` (D3.1). Legacy's صادر counters are not moved (D3.2).

### 14.5 Stamps

The dry run lists legacy's sections with a stamp (20 of 50). The system
administrator names the ERP unit for each, in the same file, and the real run
uploads each as that unit's first stamp version (I.2, D23.1). A section left
unnamed is skipped.

---

## 15. Screens

Each screen is described here by what it shows. The dashboard and app pieces
(§17) may get short specs of their own, as work shifts did.

### 15.1 The app (`alraqi-erp-app`)

- **Managers**: «المستندات» with «الواردة» and «الصادرة»; creating by upload
  (file, photo, camera) or template (category filter, search, the form), the
  approvers in the order they sign (المدير العام added last, shown), the وارد
  number, and the صادر number offered or typed. **The document screen** shows the printed PDF, the details, the
  steps with their decisions and notes, comments, attachments, approve or reject
  with a note (confirmation, then the pad the first time), and delete while
  allowed. The approvals screen («بانتظار قراري») opens a document's screen for a
  `document` subject.
- **HR's managers**: «إرسال مستند إلى موظف»: the employee and the filing type,
  by upload or a `for_employees` template. There is no approver to choose (§9),
  and the list of what they sent shows who has signed, refused, or not answered.
- **Every employee**: «مستنداتي» (awaiting my signature, signed, refused), the
  document screen with «قرأت ووافقت» and «رفض», and «توقيعي» (view only).
- **Announcements**: opened from the notifications list.
- **New packages**: a PDF viewer, a signature pad, `local_auth`.

### 15.2 The dashboard (`erp-dashboard`)

- **The managers' screens** of 15.1 and HR's send screen (D12).
- **Administration**: all documents (`documents.view`), the statistics (by
  status, activity, the latest, I.8), سجل الصادر per إدارة, templates
  (categories, the list, the page editor with placeholders and preview, the
  fields), the letterhead, the units' stamps (upload, replace, units without
  one), and announcements (compose with the audience and attachments; the sent
  list).
- **The employee page**: the filed documents (§9.4), and the signature's delete
  button for the system administrator (§10.1).

---

## 16. API outline

Shapes follow `API_BUILD_STANDARD.md`. This is the set, not the contract.

| Endpoint | |
|---|---|
| `GET/POST /api/documents`, `GET/DELETE /api/documents/{id}` | the lists (§7.7, by `box`), create (§7.1, §9.1), show, delete |
| `GET /api/documents/{id}/file` | the printed copy, or the original if nothing is printed yet |
| `POST /api/documents/{id}/decision` | §7.2 |
| `POST /api/documents/{id}/sign`, `/refuse` | §9.2 |
| `GET/POST /api/documents/{id}/comments`; `GET .../attachments/{a}/file` | §7.5 |
| `GET /api/documents/numbers/next` | the next صادر number of the caller's إدارة, or "type the first" |
| `GET /api/document-templates` (filter, search); CRUD under `documents.templates.manage`; `POST .../preview` | §8 |
| `GET /api/me/signature` (own, image), `POST /api/me/signature` | §10.1 |
| `DELETE /api/employees/{id}/signature` | the system administrator |
| `GET/PUT /api/units/{id}/stamp`, `GET /api/units/without-stamp` | the system administrator |
| `GET /api/employees/{id}/filed-documents` | §9.4 |
| `GET/POST/DELETE /api/announcements`, `GET /api/me/announcements/{id}` | §12 |

---

## 17. Pieces and order

Each piece gets its own plan, and is built and reviewed before the next.

1. **Foundations**: signatures, stamps (versions, encryption, the administrator's
   upload, units without one), the letterhead, the one-unit rule (§13), mPDF,
   and the upload check with `qpdf` conversion (§11.5; the server needs `qpdf`
   or Ghostscript).
2. **Documents between managers, by upload** (workflow 4): the document, the
   route, the decision flow and the guard, the visibility rule in the approvals
   module (§7.4), printing and marks, comments, attachments, deleting, the
   lists, the words.
3. **Templates and numbers**: templates and categories, the sanitised page and
   its rendering, the hand-made layout, صادر per إدارة, وارد and the reference,
   سجل الصادر.
4. **Documents to employees** (workflow 1): sending directly, signing and
   refusing, filing, the employee page's list with its salary rule.
5. **Announcements.**
6. **The dashboard's screens**, the page editor included.
7. **The app's screens.**
8. **The legacy import.**

---

## 18. Calls made while designing, for checking

1. **The engine is wrapped, not changed**, with an observer as the lock on its
   general endpoints (§3.1, §7.3).
2. **mPDF alone** for all printing (§3.3).
3. **Legacy's decisions move as a history**, not as engine approvals (§3.4).
4. **Deleting by the administrator** goes through the engine's `withdraw()` on
   the creator's behalf (§7.6), and **a general `/withdraw`** of a document
   counts as deleting it (§7.4).
5. **The recipient does not see or write comments**: comments are the senders'
   and approvers' conversation (§5).
6. **A refusal is confirmed** like a signature (§9.2).
7. **Every mark prints the system number** of the document (§11.4).
8. **`documents.templates.manage` is a permission**, not "the system
   administrator only" as stamps are: D32.3 says "the admin" without "only", and
   the archive's staff may need it.
9. **`for_employees` on templates** decides whether a template appears on HR's
   send screen (§8.1).
10. **Announcements are sent from the dashboard only**, and can be deleted, not
    edited (§12).
11. **The people of the import are matched in a file**, not on a screen (§14.2).
12. **A read-only list of filed documents** on the employee page is built here
    (§9.4).
13. **The missing-stamp notification** goes to the system administrators once
    per unit until a stamp is uploaded (§7.2).
14. **Size limits**: 20 MB per uploaded file and per attachment.
15. **The decider's step goes only to the root unit's current manager** when it
    is reassigned (§7.3).
16. **Each print has its own private temp directory**, deleted at the end
    (§11.3).
17. **Filed documents are read under the record's rules**, and the filed row
    points at the document's file (§9.4).

## 19. The builder check's questions — answered 2026-11-22

Raised by the builder check of 2026-11-22 and answered by the owner the same
day. Nothing is left open in this design. The company's forms (D32.1) are still
awaited, and they are not a decision.

| | Question | Answer |
|---|---|---|
| Q1 | Which register a صادر number comes from | **The creator's إدارة, as legacy** (المدير العام: الإدارة العامة's own). Sections and offices share their إدارة's register (§8.4) |
| Q2 | Whether `approvals.view` holders see document chains on the engine's general screens | **Hidden**, through one optional visibility rule in the approvals module that leave and swaps do not declare (§7.4) |
| Q3 | Who reassigns a document's pending step | **The system administrator only** (§5, §7.3) |
| Q4 | A PDF the printing cannot open | **Converted on the server** with `qpdf` or Ghostscript, refused if that fails (§11.5). The production server must have one installed |
| Q5 | The HR manager sending a document that needs a stamp | **No such case: the owner's clarification of four workflows** (§1.4). A document HR sends to an employee never goes through approvals, needs no approver, and **carries only the employee's signature**. HR's managers keep sending (D29). This revises D19.1, D25 and D30.1 (§9) |
| Q6 | Filed contracts and decisions behind the salary permission | **Yes**: عقد العمل, قرار تعيين and قرار ترقية, beside Sham Cash, behind `hr.employees.viewSalary` (§9.4) |
| — | Raised by the clarification: managers in parallel | **In order only.** The managers sign one after another, then المدير العام. This revises I.1 (§7.1) |
| — | Raised by piece 2's final review (2026-11-23): a document stuck after a promotion to المدير العام | **The administrator closes it as «لم يُستكمل»**, the creator sends it again, and a list shows the documents needing attention (§7.8). This adds one administrator's cancellation to the approvals engine, used only by documents |
| — | Raised by piece 2's final review (2026-11-23): inactive units | **Documents ignore them:** a manager whose only unit is inactive does not create, is not named and does not sign; a waiting step whose approver's unit is deactivated is reassigned by the administrator. The one-unit-per-manager rule still counts inactive units (§4, §13) |
| — | Raised by piece 2's final review (2026-11-23): an approver whose employee record was deleted | **Flagged on the attention list:** HR's delete is soft and leaves him as his unit's manager, so he cannot sign; the document is listed as `approver_manages_no_unit` for the administrator to reassign or close (§7.8) |

## 20. Left to the builder

The card's exact drawing within legacy's geometry; the Arabic font; the editor
library and the sanitiser's whitelist; the error code names; the notification
and audit event names (`NotificationEventCatalogue`, `AuditEventCatalogue`); the
endpoint shapes; pagination sizes; how the starting pages of the moved templates
are laid out; the dry run's report format.
