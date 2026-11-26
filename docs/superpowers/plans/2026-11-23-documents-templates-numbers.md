# Documents — piece 3, templates and numbers: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A manager creates a document between managers from one of the company's templates. They fill the form, and the server prints the page with the letterhead, the values, a صادر number from their إدارة's register and a reference. The administrator builds the templates and their categories, and previews them. Each إدارة has its سجل الصادر.

**Architecture:**
- **Templates** are rows (`document_templates`, `document_template_categories`). A template's page is either:
  - sanitised HTML with placeholder chips, printed by mPDF inside the letterhead; or
  - a hand-made Blade layout (`layout_key`).
- **Creating from a template** reuses piece 2's flow. Inside the creation transaction, the server claims the صادر number (a locked counter per إدارة) and the reference (a locked sequence per prefix and year). It then prints the original from the template, and submits the same route.
- **Pieces 1 and 2 stay as they are**, except:
  - the create request accepts a template instead of a file;
  - the card block can sit above the letterhead's footer;
  - the lists search the new numbers.

**Tech Stack:**
- Laravel 12, PHP 8.2;
- PHPUnit (sqlite in memory), Pint;
- mPDF 8.3 (piece 1);
- **new: `symfony/html-sanitizer` ^7.4**.

**Spec:** [`docs/superpowers/specs/2026-11-22-documents-design.md`](../specs/2026-11-22-documents-design.md) ("spec §n"): §3.2, §5, §6.1, §6.3, §7.1, §7.7, §8, §11.2, §14.4, §16, §17 piece 3. It builds on piece 1 ([plan](2026-11-22-documents-foundations.md)) and piece 2 ([plan](2026-11-22-documents-between-managers.md)), both on `main` (merge `03ba35b`).

## Global Constraints

- **Prerequisite:** pieces 1 and 2 are on `main` (`03ba35b`). Branch `documents-templates` from `main`. If they are not there, stop and ask.
- **No commit, stage, reset, stash or push without the owner's explicit go-ahead.** Leave changes in the working tree; `git add -N` new files.
- Another agent may edit this tree: run `git status` and re-read a file before editing it.
- **Untouched:**
  - every file in `app/Modules/Approvals/`, `ManagementChain`, and the Leave and Scheduling modules;
  - piece 2's decision, deletion, closing and attention code, except where a task names it.

  Every existing test passes unchanged. `CardLayout::place()`'s new `floor` argument defaults to piece 2's 6 mm, and an upload without a file still answers «الملف مطلوب.».
- **Workflow 4 only.** A `for_employees` template is not offered for a document between managers, and creating one from it is refused (Task 6). Sending to an employee is piece 4. Piece 3 makes the printing, numbering and recipient placeholders ready for it:
  - `TemplatePrinter` takes recipient values;
  - `ExportNumbers` and `ReferenceNumbers` know nothing of approvals.
- **Migrations:** pieces 1–2 are merged, so nothing is edited in place. Piece 3 adds new migrations in `database/migrations/Documents/`, dated `2026_11_24_…`.
- **Who:**
  - Reading templates and categories: any authenticated account.
  - Writing them, and previewing: `documents.templates.manage` (route middleware `permission:documents.templates.manage`, as the letterhead's).
  - The next صادر number: a unit's manager (for their own إدارة).
  - سجل الصادر: the إدارة's own manager (`UnitManagers::unitOf($caller)` is that unit), or `documents.view`.
- **Field types**, legacy's keys, so the import (piece 8) maps one to one. `FieldType` is a string-backed enum:

  | Case | Value | Value accepted |
  |---|---|---|
  | `Text` | `text` | string ≤ 500 |
  | `Textarea` | `textarea` | string ≤ 5000; line breaks kept |
  | `Number` | `number` | numeric, kept as the trimmed string typed |
  | `Date` | `date` | `Y-m-d`, printed `Y/m/d` |
  | `Select` | `select` | one of `options` |
  | `Table` | `table` | 0–100 rows; a row is an object keyed by column key, each cell a string ≤ 500. Unknown keys are dropped, a missing key is `''` |

  A field is `{key, label, type, required, options?, columns?}`:
  - `key` matches `^[a-z][a-z0-9_]{0,49}$` and is unique in the template;
  - `label` is 1–100 characters;
  - `options` has 1–50 strings of ≤ 100 characters (select only);
  - `columns` has 1–10 items `{key, label}`, with the same key rule (table only);
  - a template has 0–50 fields.

  A required table needs at least one row. An optional empty value prints nothing.
- **Placeholders.** A chip is stored as `<span data-placeholder="KEY">label</span>`. The text inside is the editor's label, ignored when printing. Keys:
  - `field.<key>`: one of the template's fields;
  - `system.export_number`, `system.import_number`, `system.reference`, `system.date` (the creation day, `Y/m/d`, Asia/Damascus), `system.creator_name`, `system.creator_unit`;
  - only when `for_employees`: `recipient.name`, `recipient.job_title`, `recipient.unit`, `recipient.employee_number`.

  Every value is escaped. A table field prints as a table: a header row of the column labels, then the rows. The table replaces its `<p>` when the chip is that paragraph's only content; otherwise it goes right after the paragraph.
- **The page's HTML whitelist** (the sanitiser's; everything else is removed, text kept):
  - **Elements:**
    - text: `p`, `h1`–`h4`, `strong`, `b`, `em`, `i`, `u`, `br`, `hr`;
    - lists: `ul`, `ol`, `li`;
    - tables: `table`, `thead`, `tbody`, `tr`, `th`, `td`;
    - `span`, for chips;
    - `div`, for page breaks.
  - **Attributes:**
    - `style` and `dir` (`rtl`/`ltr`) on `p`, `h1`–`h4`, `li`, `table`, `th`, `td`, `span`, `div`;
    - `colspan` and `rowspan` (1–20) on `th`/`td`;
    - `data-placeholder` on `span`;
    - `class` on `div`, kept only when it is exactly `page-break` (printed as mPDF's `<pagebreak />`).
  - **`style` keeps only two properties:**
    - `text-align` ∈ {`right`, `left`, `center`, `justify`};
    - `font-size` from `8pt` to `36pt`.
  - **Never:** `img`, links, `script`, `style` or `link` elements, any `url(`, or mPDF's own tags. The sanitiser removes them; it does not escape them.
  - **The page:** at most 200 KB (204800 bytes) before sanitising.
- **Printing a template** (spec §11.2):
  - mPDF, A4, RTL, `dejavusans` 11pt, line-height 1.6 (legacy's template pages). Only the template pages: piece 2's cards keep mPDF's automatic Arabic font, XB Riyaz (`PdfSession`'s `autoLangToFont => true`), which loses some letters when copied or searched (ruling R9 revised; the cards' font is the owner's follow-up);
  - margins 18 mm left and right;
  - **top margin:** 20 mm, or 45 mm with a header image (header 8 mm from the top, image ≤ 174 × 30 mm);
  - **bottom margin:** 20 mm, or 30 mm with a footer image (footer 6 mm from the bottom, image ≤ 174 × 18 mm);
  - tables full width, cells with a 0.3 mm `#555555` border and 1.5 × 2 mm padding, header cells `#F0F0F0`.

  The letterhead frames every page when `show_letterhead` is on and that image is uploaded. Images reach mPDF through `imageVars`, in a `PdfSession` (piece 1).
- **Cards above the footer:** a template document printed with a footer image stores `card_floor = 32` (mm; ruling R14, was 28). The printer passes it to `CardLayout`, so the card block's bottom sits 32 mm from the page's foot instead of 6 mm. Every other document keeps `card_floor` null, meaning piece 2's 6 mm. The 55% rule is unchanged. The value is stored, so a rebuild draws the same picture.
- **Numbers** (spec §8.4):
  - **صادر:** from `document_counters` for the creator's إدارة (`UnitManagers::registerOf`), claimed in the creation transaction under `lockForUpdate`, with legacy's `DocumentCounterService` rules:
    - with no counter row, or `current_value` 0, a typed number is required;
    - a typed number already on any document of that register, deleted ones included, is refused;
    - a typed number above the counter moves the counter to it;
    - a typed number below the counter is taken as it is;
    - with nothing typed, the number is `current_value + 1`.

    Range 1–999999999. The DB `unique(register_unit_id, export_number)` (piece 2) is the last guard: a violation answers the "used" copy.
  - **Reference:** `sprintf('%s-%d-%04d', $prefix, $year, $seq)`, from `document_reference_sequences` per (`prefix`, `year`). The year is `now()`'s (app timezone Asia/Damascus). It is claimed under `lockForUpdate`, after the صادر number.
  - Only template documents get either (D3.3). Uploads keep both null.
- **Every new model:** `HasAuditLog` and soft deletes where spec §6 says (templates and categories), plus registration in three places:
  - a morph alias;
  - a label in `AuditTargetStub::TYPE_LABELS`;
  - an entry in `AuditLogDetailResource::TYPE_VIEW_PERMISSIONS`.

  | Alias | Label | Permissions |
  |---|---|---|
  | `document_template` | «قالب مستند» | `['documents.view']` (the gate admits read permissions only: `AuditTargetStubTest`) |
  | `document_template_category` | «تصنيف قوالب المستندات» | `['documents.view']` (the gate admits read permissions only: `AuditTargetStubTest`) |
  | `document_counter` | «عدّاد سجل الصادر» | `['documents.view']` |
  | `document_reference_sequence` | «تسلسل مراجع المستندات» | `['documents.view']` |
- **Arabic copy, exact:**
  - **Templates (manage):**
    - «تم إنشاء القالب.» (201)
    - «تم تحديث القالب.» (200)
    - «تم حذف القالب.» (200)
    - «اسم القالب مطلوب.»
    - «اسم القالب لا يزيد على 150 حرفاً.»
    - «يوجد قالب بهذا الاسم.»
    - «التصنيف مطلوب.»
    - «التصنيف المختار غير موجود.»
    - «بادئة المرجع من 2 إلى 10 أحرف إنجليزية كبيرة.»
    - «اكتب صفحة القالب أو اختر تخطيطاً جاهزاً.» (neither given)
    - «اكتب صفحة القالب أو اختر تخطيطاً جاهزاً، لا كليهما.» (both given)
    - «التخطيط المختار غير موجود.»
    - «صفحة القالب لا تزيد على 200 كيلوبايت.»
    - «لا تزيد حقول القالب على 50.»
    - «مفتاح الحقل يبدأ بحرف إنجليزي صغير ويتكوّن من أحرف إنجليزية صغيرة وأرقام و _ فقط.»
    - «مفتاح الحقل «{key}» مكرر.»
    - «تسمية الحقل مطلوبة.»
    - «نوع الحقل غير مدعوم.»
    - «حقل القائمة «{label}» يحتاج إلى خيار واحد على الأقل.»
    - «حقل الجدول «{label}» يحتاج إلى عمود واحد على الأقل.»
    - «العنصر «{key}» في صفحة القالب غير معرّف.»
    - «استُخدم هذا القالب في مستندات، فلا يُحذف. يمكنك إيقافه بدلاً من ذلك.» (422)
  - **Categories:**
    - «تم إنشاء التصنيف.» (201)
    - «تم تحديث التصنيف.» (200)
    - «تم حذف التصنيف.» (200)
    - «اسم التصنيف مطلوب.»
    - «يوجد تصنيف بهذا الاسم.»
    - «لا يُحذف تصنيف فيه قوالب.» (422)
  - **Creating from a template:**
    - «أرفق ملفاً أو اختر قالباً، لا كليهما.»
    - «الملف مطلوب.» (unchanged: neither a file nor a template)
    - «القالب المختار غير موجود أو غير مفعّل.» (legacy's)
    - «هذا القالب للمستندات المرسلة إلى الموظفين.»
    - «رقم الصادر للمستندات المنشأة من قالب فقط.»
    - «هذا أول مستند صادر في الإدارة، يرجى إدخال رقم الصادر لتحديد نقطة البداية.» (legacy's)
    - «رقم الصادر {n} مستخدم في سجل هذه الإدارة.»
    - «رقم الصادر يجب أن يكون بين 1 و 999999999.»
    - «حقل «{label}» مطلوب.»
    - «حقل «{label}» يجب أن يكون رقماً.»
    - «حقل «{label}» يجب أن يكون تاريخاً بصيغة سنة-شهر-يوم.»
    - «القيمة المختارة في «{label}» غير صالحة.»
    - «حقل «{label}» يجب أن يكون جدولاً.»
    - «حقل «{label}» لا يزيد على {max} حرف.»
    - «جدول «{label}» لا يزيد على 100 صف.»
  - **سجل الصادر:**
    - «لا يطّلع على سجل الصادر إلا مدير الإدارة أو من يملك صلاحية عرض المستندات.» (403)
    - «هذه الوحدة لا تملك سجل صادر.» (422)
    - «محذوف» (the label on a deleted row)
- **Deploy:** `composer install` (the new package), `migrate`. Nothing new on the server.
- **Gates at the end of every task:** `php artisan test` (the full suite, run alone, never two at once) and `vendor/bin/pint --test`, both clean.

## Review Focus

1. **A page carrying markup aimed at the printer.** For example `<img src="/etc/passwd">`, `style="background:url(file:///…)"`, `<script>`, mPDF's `<annotation>` or `<barcode>`, or `<a href>`. Expected: all are gone after saving, nothing outside the whitelist reaches mPDF, and no file is read. Tested in Task 2.
2. **Values typed with characters that mean something in HTML or Blade.** For example `<b>`, `&`, `{{ $x }}`, `@php`, or Arabic mixed with Latin and digits. Expected: they print as typed, never as markup, in a chip, a table cell and a hand-made layout alike. Tested in Task 4.
3. **Two managers of one إدارة creating at the same moment.** Either the register is cold and both type the same first number, or neither types one. Expected: one gets the number, and the other gets the "used" copy or the next number. The counter never goes backwards, and no number is printed twice. Tested in Task 5 (sequential claims, plus a forced unique violation) and Task 6.
4. **A template edited or deactivated between the creator opening the form and sending it.** Expected:
   - the values are checked against the template as it is at sending;
   - the version recorded is the one whose page was printed;
   - a deactivated template is refused with its copy.

   Tested in Task 6.
5. **The year turning over in Damascus time.** A reference claimed at 23:59 on 31 December and at 00:01 on 1 January comes from two different yearly sequences, the second starting at `0001`. Tested in Task 5.

---

## File map

| File | Task | What it holds |
|---|---|---|
| `database/migrations/Documents/2026_11_24_100000_create_document_template_categories_table.php`, `…_100100_create_document_templates_table.php` | 1 | categories and templates |
| `app/Modules/Documents/Models/DocumentTemplate.php`, `DocumentTemplateCategory.php` | 1 | models |
| `app/Modules/Documents/Enums/FieldType.php` | 1 | the six field types |
| `app/Modules/Documents/Templates/TemplateFields.php` | 2 | a template's fields, read |
| `app/Modules/Documents/Templates/TemplateBody.php` | 2 | sanitising the page, finding its chips |
| `app/Modules/Documents/Templates/Placeholders.php` | 2 | the allowed keys |
| `app/Modules/Documents/Templates/FieldValues.php` | 2 | checking and normalising a creator's values |
| `app/Modules/Documents/Templates/StyleAttributeSanitizer.php`, `PageBreakClassSanitizer.php` | 2 | the sanitiser's two filters |
| `app/Modules/Documents/Controllers/DocumentTemplateController.php`, `TemplateCategoryController.php` | 3 | the API |
| `app/Modules/Documents/Requests/SaveTemplateRequest.php`, `SaveTemplateCategoryRequest.php`, `TemplatesIndexRequest.php` | 3 | validation |
| `app/Modules/Documents/Resources/DocumentTemplateResource.php` | 3 | the shape |
| `app/Modules/Documents/Pdf/TemplateHtml.php` | 4 | page → final HTML (pure) |
| `app/Modules/Documents/Pdf/TemplatePrinter.php`, `RenderedTemplate.php` | 4 | final HTML → PDF in the letterhead |
| `app/Modules/Documents/Support/SystemValues.php`, `RecipientValues.php` | 4 | the system and recipient values |
| `resources/views/documents/template.blade.php`, `resources/views/documents/layouts/purchase_request.blade.php` | 4 | the page frame; legacy's طلب شراء |
| `database/migrations/Documents/2026_11_24_110000_create_document_counters_table.php`, `…_110100_create_document_reference_sequences_table.php` | 5 | the registers |
| `app/Modules/Documents/Models/DocumentCounter.php`, `DocumentReferenceSequence.php` | 5 | models |
| `app/Modules/Documents/Services/ExportNumbers.php`, `ReferenceNumbers.php` | 5 | claiming numbers |
| `database/migrations/Documents/2026_11_24_120000_add_template_columns_to_documents_table.php` | 6 | `card_floor`, an index on `template_id` |
| `app/Modules/Documents/Controllers/ExportRegisterController.php` | 5, 7 | the next number; سجل الصادر |
| `config/documents.php` | 4 | `layouts` |

---

### Task 1: Templates' data

**Files:**
- Create:
  - the two migrations of the file map;
  - `Models/DocumentTemplate.php`, `Models/DocumentTemplateCategory.php`;
  - `Enums/FieldType.php`.
- Modify:
  - `app/Providers/AppServiceProvider.php` (the morph map);
  - `app/Modules/Auth/Resources/AuditTargetStub.php`;
  - `app/Modules/Auth/Resources/AuditLogDetailResource.php`.
- Test: `tests/Feature/Documents/TemplateModelsTest.php`

**Interfaces:**
- Produces:
  - **`document_template_categories`:** `id`, `name` string(100), `sort` unsignedSmallInteger default 0, timestamps, softDeletes.
  - **`document_templates`:**
    - `id`;
    - `category_id` (FK categories, restrictOnDelete);
    - `name` string(150);
    - `description` string(1000) nullable;
    - `is_active` bool default true;
    - `for_employees` bool default false;
    - `reference_prefix` string(10);
    - `fields` json;
    - `body` longText nullable;
    - `layout_key` string(50) nullable;
    - `show_letterhead` bool default true;
    - `version` unsignedInteger default 1;
    - `legacy_id` unsignedBigInteger nullable unique;
    - timestamps, softDeletes.
  - **`DocumentTemplate`:**
    - casts: `fields` array, booleans, `version` int;
    - `category(): BelongsTo`;
    - `documents(): HasMany` (`Document`, `template_id`, trashed documents included through `withTrashed()` where asked);
    - `scopeActive(Builder)`;
    - `isInUse(): bool` (any document, deleted included, has this `template_id`).
  - **`DocumentTemplateCategory`:** `templates(): HasMany`.
  - **`FieldType`:** string-backed, the six cases of Global Constraints.

- [ ] **Step 1: Write the failing tests**
  - `test_a_template_belongs_to_its_category_and_casts_its_fields`
  - `test_a_template_in_use_counts_deleted_documents`: a document soft-deleted with this `template_id` still makes `isInUse()` true.
  - `test_templates_and_categories_are_audited_under_their_aliases`: create one of each, then assert an `audit_logs` row with `auditable_type` `document_template` and `document_template_category`, and the labels «قالب مستند» and «تصنيف قوالب المستندات» from `AuditTargetStub`.
  - `test_the_active_scope_leaves_out_inactive_and_deleted_templates`
- [ ] **Step 2:** Run `php artisan test --filter=TemplateModelsTest`. Expect FAIL: the classes do not exist.
- [ ] **Step 3:** Write the migrations, the models (`HasAuditLog`, `SoftDeletes`), the enum and the three registrations, with the table of Global Constraints.
- [ ] **Step 4:** Run the filter again; PASS. Run the gates.

---

### Task 2: The page and the values

**Files:**
- Create:
  - `Templates/TemplateFields.php`, `Templates/TemplateBody.php`, `Templates/Placeholders.php`, `Templates/FieldValues.php`;
  - `Templates/StyleAttributeSanitizer.php`, `Templates/PageBreakClassSanitizer.php`.
- Modify: `composer.json` and `composer.lock` (`composer require symfony/html-sanitizer:^7.4`).
- Test: `tests/Unit/Documents/TemplateBodyTest.php`, `tests/Unit/Documents/FieldValuesTest.php`, `tests/Unit/Documents/TemplateFieldsTest.php`

**Interfaces:**
- Consumes: `FieldType` (Task 1).
- Produces:
  - **`TemplateFields`** (final readonly):
    - `static fromArray(array $fields): self`, which trusts input already validated;
    - `all(): array`;
    - `keys(): array<string>`;
    - `get(string $key): ?array`;
    - `problems(array $fields): array<string>`: the Arabic messages for a raw schema (Global Constraints: key rule, duplicates, labels, types, options, columns, count), empty when valid.
  - **`TemplateBody`** (final):
    - `sanitize(string $html): string`, through `HtmlSanitizer` with the whitelist, `StyleAttributeSanitizer` and `PageBreakClassSanitizer`;
    - `placeholders(string $html): array<string>`, the distinct `data-placeholder` values in document order.
  - **`Placeholders`** (final):
    - `const SYSTEM`, `const RECIPIENT` (Global Constraints);
    - `static allowed(TemplateFields $fields, bool $forEmployees): array<string>`;
    - `static unknown(array $found, TemplateFields $fields, bool $forEmployees): array<string>`.
  - **`FieldValues`** (final):
    - `check(TemplateFields $fields, array $values): array`, which returns the normalised values keyed by field key, or throws `ValidationException` with messages under `field_values.{key}` (the copy of Global Constraints);
    - normalising means: strings trimmed, numbers kept as the trimmed string, dates `Y-m-d`, table rows reduced to the column keys, empty optional values stored as `null`.

- [ ] **Step 1: Write the failing tests**
  - `TemplateBodyTest`:
    - `test_the_whitelist_is_kept`: headings, bold/italic/underline, lists, a bordered table with `colspan`, `text-align: center` and `font-size: 14pt` survive.
    - `test_markup_aimed_at_the_printer_is_removed` (Review Focus 1): `<img src="/etc/passwd">`, `style="background:url(file:///etc/passwd)"`, `<script>`, `<annotation content="x">`, `<barcode code="1">` and `<a href="http://x">t</a>` are all gone; `t` stays as text; no `url(` remains.
    - `test_style_keeps_only_alignment_and_a_sane_font_size`: `font-size: 72pt; color: red; text-align: center` becomes `text-align: center`.
    - `test_a_page_break_div_survives_and_other_classes_do_not`
    - `test_chips_are_found_in_order_once_each`
  - `FieldValuesTest`:
    - one test per rule of the field-type table, each asserting the exact copy;
    - `test_table_rows_keep_only_their_columns`;
    - `test_an_optional_empty_value_is_null`;
    - `test_a_required_table_needs_a_row`.
  - `TemplateFieldsTest`: one test per `problems()` message; `test_placeholders_unknown_to_the_template` (a `recipient.name` chip is unknown unless `for_employees`).
- [ ] **Step 2:** Run the three tests. Expect FAIL.
- [ ] **Step 3:** Run `composer require symfony/html-sanitizer:^7.4`, then implement. Build the sanitiser config with `allowElement()` for each element and its attributes; do not use `allowSafeElements()`.
- [ ] **Step 4:** Run the tests; PASS. Run the gates.

---

### Task 3: Templates and categories, the API

**Files:**
- Create:
  - `Controllers/DocumentTemplateController.php`, `Controllers/TemplateCategoryController.php`;
  - `Requests/SaveTemplateRequest.php`, `Requests/SaveTemplateCategoryRequest.php`, `Requests/TemplatesIndexRequest.php`;
  - `Resources/DocumentTemplateResource.php`.
- Modify: `app/Modules/Documents/routes.php`.
- Test: `tests/Feature/Documents/TemplatesApiTest.php`, `tests/Feature/Documents/TemplateCategoriesApiTest.php`

**Interfaces:**
- Consumes: Task 1's models; Task 2's `TemplateFields::problems`, `TemplateBody::sanitize`/`placeholders`, `Placeholders::unknown`.
- Produces, in the documents route group before the `/{document}` routes (all with `whereNumber`):
  - **Reading** (any authenticated account):
    - `GET /api/documents/template-categories`: every live category, by `sort` then `name`, with `templates_count` of active templates.
    - `GET /api/documents/templates`: paginated with the module's per-page rule. Filters: `category_id`, `search` (name, `like`, escaped as the documents list does), `for_employees` (bool).
      - Without `documents.templates.manage`: active only, and the page fields (`body`, `layout_key`, `show_letterhead`, `reference_prefix`, `in_use`) are left out.
      - With it: also `status` = `active` | `inactive` | `all` (default `all`), and the page fields.
    - `GET /api/documents/templates/{template}`: the same shape. An inactive template is a 404 without the manage permission.
  - **Writing** (`permission:documents.templates.manage`):
    - `POST /api/documents/templates` → 201 «تم إنشاء القالب.»
    - `PUT /api/documents/templates/{template}` → 200 «تم تحديث القالب.», `version` + 1 on every update
    - `DELETE /api/documents/templates/{template}` → soft delete when `! isInUse()`, else 422
    - the same three under `/api/documents/template-categories`. A category with any live template, active or not, is refused 422.
  - **`SaveTemplateRequest`:**
    - `name` (required, ≤ 150, unique among live templates);
    - `category_id` (an existing live category);
    - `description` (≤ 1000);
    - `is_active`, `for_employees`, `show_letterhead` (bool);
    - `reference_prefix` (`^[A-Z]{2,10}$`);
    - `fields` (array; `TemplateFields::problems` added in `after()`);
    - exactly one of `body` (string ≤ 204800 bytes) or `layout_key` (a key of `config('documents.layouts')`).

    The stored `body` is `TemplateBody::sanitize()`'s output. Any chip `Placeholders::unknown()` reports refuses the save, one message per key.
  - **`DocumentTemplateResource`:**
    - `id`, `name`, `description`;
    - `category` `{id, name}`;
    - `is_active`, `for_employees`, `version`;
    - `fields`;
    - the manage-only fields above, `in_use` being `isInUse()`.

- [ ] **Step 1: Write the failing tests**
  - `test_anyone_signed_in_lists_active_templates_without_their_pages`
  - `test_a_template_manager_lists_all_with_pages_and_in_use`
  - `test_filters_by_category_search_and_for_employees`
  - `test_creating_a_template_stores_a_sanitised_page` (Review Focus 1 through HTTP)
  - `test_an_unknown_chip_is_refused`: `field.missing` and `recipient.name` on a non-employee template each give «العنصر «…» في صفحة القالب غير معرّف.»
  - `test_page_or_layout_exactly_one`: both copies.
  - `test_every_update_bumps_the_version`
  - `test_a_used_template_is_not_deleted_but_an_unused_one_is`
  - `test_writing_needs_the_permission`: 403 for a manager without it.
  - Categories: create, rename, the duplicate name, delete refused while it holds a template, delete when empty.
- [ ] **Step 2:** Run the two files. Expect FAIL.
- [ ] **Step 3:** Implement. `config('documents.layouts')` comes from Task 4. Until then, add the key with `['purchase_request' => 'طلب شراء']` in this task, and Task 4 adds the view.
- [ ] **Step 4:** Run the files; PASS. Run the gates.

---

### Task 4: Printing a template, and the preview

**Files:**
- Create:
  - `Pdf/TemplateHtml.php`, `Pdf/TemplatePrinter.php`, `Pdf/RenderedTemplate.php`;
  - `Support/SystemValues.php`, `Support/RecipientValues.php`;
  - `resources/views/documents/template.blade.php`, `resources/views/documents/layouts/purchase_request.blade.php`.
- Modify:
  - `config/documents.php` (`layouts`);
  - `Controllers/DocumentTemplateController.php` (`preview`);
  - `routes.php`.
- Test: `tests/Unit/Documents/TemplateHtmlTest.php`, `tests/Feature/Documents/TemplatePrinterTest.php`, `tests/Feature/Documents/TemplatePreviewTest.php`

**Interfaces:**
- Consumes: Task 1–2 classes; piece 1's `Letterhead::pathOf(LetterheadPart)`, `PdfWorkspace::run`, `PdfSession::mpdf`/`path`.
- Produces:
  - **`SystemValues`** (final readonly):
    - `?int $exportNumber`, `?int $importNumber`, `?string $reference`;
    - `CarbonImmutable $date`;
    - `string $creatorName`, `string $creatorUnit`;
    - `toArray(): array<string, string>`, keyed by the `system.*` keys, the date as `Y/m/d` in Asia/Damascus, null as `''`.
  - **`RecipientValues`** (final readonly): `name`, `jobTitle`, `unit`, `employeeNumber` (strings); `toArray()` keyed by the `recipient.*` keys.
  - **`TemplateHtml`** (final):
    - `render(DocumentTemplate $template, array $fieldValues, SystemValues $system, ?RecipientValues $recipient): string`;
    - the final HTML handed to mPDF, built from the `template.blade.php` frame (the Global Constraints' CSS);
    - a page template: every chip replaced by its escaped value (a table field as a table), every `div.page-break` as `<pagebreak />`;
    - a layout template: the Blade view `documents.layouts.{layout_key}`, given `$template`, `$fields` (the schema), `$values` and `$system` (the `system.*` array), and `$recipient` (array or null). It escapes with `{{ }}` only.
  - **`RenderedTemplate`** (final readonly): `string $path` (a file in the print's session), `?int $cardFloor` (32 when a footer image was printed, else null; R14).
  - **`TemplatePrinter`** (non-final, so a test can make it fail): `print(DocumentTemplate $template, array $fieldValues, SystemValues $system, ?RecipientValues $recipient, PdfSession $session): RenderedTemplate`. It sets the letterhead (`SetHTMLHeader`/`SetHTMLFooter` with `var:` images) and the margins of Global Constraints, then writes the HTML.
  - **`POST /api/documents/templates/preview`** (`permission:documents.templates.manage`): the same body as `SaveTemplateRequest`, but nothing is saved; an unsaved `DocumentTemplate` is filled from it. It answers the PDF inline: `Content-Type: application/pdf`, `Content-Disposition: inline; filename="preview.pdf"`, `Cache-Control: private, no-store`.

    **Sample values:**
    - text «نص تجريبي»; textarea «سطر أول» newline «سطر ثانٍ»; number «1250»; date today;
    - select: its first option;
    - table: two rows with «قيمة» in every cell;
    - system: صادر 123, وارد 45, reference `{PREFIX}-{year}-0001`, today, the caller's name (or «اسم المنشئ») and unit (or «اسم الوحدة»);
    - recipient (when `for_employees`): «اسم الموظف», «المسمى الوظيفي», «الوحدة», «0000».
  - **`purchase_request.blade.php`:** legacy's طلب شراء, ported. It has:
    - a meta block (رقم الصادر, رقم الوارد when there is one, المرجع, التاريخ);
    - the template's name as the title and the creator's unit under it;
    - the fields `vendor_name`, `total_amount` + `currency`, `delivery_date`, `items` (table, its columns' labels as headers), and `justification` (line breaks kept).

    Legacy's empty signature boxes are not ported: the cards replace them.

- [ ] **Step 1: Write the failing tests**
  - `TemplateHtmlTest`:
    - `test_each_chip_prints_its_value`: a field, each `system.*` key, and a recipient key when given.
    - `test_values_print_as_typed_never_as_markup` (Review Focus 2): `<b>x</b>`, `a & b`, `{{ $x }}`, `@php` and «مبلغ 1,250 USD» appear escaped in a chip, in a table cell, and through the `purchase_request` layout.
    - `test_a_table_chip_alone_in_a_paragraph_replaces_it` and `test_a_table_chip_inside_text_follows_the_paragraph`
    - `test_a_page_break_becomes_mpdf_pagebreak`
    - `test_an_empty_optional_value_prints_nothing`
  - `TemplatePrinterTest`:
    - `test_a_page_prints_as_a_pdf`: a valid PDF with ≥ 1 page, read with FPDI as piece 2's `pagesOf()` does.
    - `test_the_letterhead_frames_the_page_and_sets_the_card_floor`: with header and footer uploaded, `cardFloor` is 32; with only a header it is null; with `show_letterhead` off it is null.
    - `test_two_page_breaks_make_three_pages`
    - `test_no_image_is_left_on_disk`: `pdf-work` is empty after the print.
  - `TemplatePreviewTest`: a manager of templates gets a PDF inline with the three headers; anyone else gets 403; an unknown chip is 422 as when saving.
- [ ] **Step 2:** Run the three files. Expect FAIL.
- [ ] **Step 3:** Implement. Walk the sanitised HTML with `DOMDocument` (UTF-8, `LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD`) to replace the chips; never with string replacement.
- [ ] **Step 4:** Run the tests; PASS. Run the gates. Then render one Arabic template with the letterhead (a two-column table, a page break, a long paragraph) and look at it with the browser pane's pdf.js, as piece 2's cards were checked. Report what it looks like.

---

### Task 5: صادر numbers and references

**Files:**
- Create:
  - the two migrations of the file map;
  - `Models/DocumentCounter.php`, `Models/DocumentReferenceSequence.php`;
  - `Services/ExportNumbers.php`, `Services/ReferenceNumbers.php`;
  - `Controllers/ExportRegisterController.php` (`next` now; Task 7 adds `index`).
- Modify:
  - `routes.php`;
  - the morph map and the two audit files (Global Constraints' table).
- Test: `tests/Feature/Documents/ExportNumbersTest.php`, `tests/Feature/Documents/ReferenceNumbersTest.php`, `tests/Feature/Documents/NextExportNumberTest.php`

**Interfaces:**
- Produces:
  - **`document_counters`:** `id`, `unit_id` (FK departments, unique, restrictOnDelete), `current_value` unsignedInteger default 0, timestamps.
  - **`document_reference_sequences`:** `id`, `prefix` string(10), `year` unsignedSmallInteger, `current_value` unsignedInteger default 0, timestamps, `unique(prefix, year)`.
  - **`ExportNumbers`** (not final, so Task 6's race test can bind a subclass; `claim` must run inside a transaction):
    - `next(Department $register): ?int`: `current_value + 1`, or null while cold.
    - `isUsed(Department $register, int $number): bool`: on any document of the register, `withTrashed`. It needs no transaction; Task 6 pre-checks with it.
    - `claim(Department $register, ?int $typed): int`: the rules of Global Constraints, under `lockForUpdate` of the counter row (created at 0 when missing, then re-read locked).
    - It throws `DocumentRouteException` with:
      - «هذا أول مستند صادر في الإدارة، يرجى إدخال رقم الصادر لتحديد نقطة البداية.»
      - «رقم الصادر {n} مستخدم في سجل هذه الإدارة.» (a number on any document of the register, `withTrashed`)
  - **`ReferenceNumbers`** (final): `claim(string $prefix): string`, under `lockForUpdate` of the (`prefix`, `now()->year`) row, created at 0 when missing.
  - **`GET /api/documents/numbers/next`** (a unit's manager; else 403 «لا يُنشئ المستندات إلا مدير وحدة تنظيمية.»): `{data: {register: {id, name}, next: int|null, first_required: bool}}`.

- [ ] **Step 1: Write the failing tests**
  - `ExportNumbersTest`:
    - `test_a_cold_register_needs_a_typed_number`
    - `test_the_first_typed_number_seeds_the_register`: type 186, then the next claim is 187.
    - `test_a_higher_typed_number_moves_the_counter`
    - `test_a_lower_typed_number_is_taken_and_the_counter_stays`
    - `test_a_used_number_is_refused_even_when_its_document_is_deleted`
    - `test_registers_are_apart`: two إدارات each have their own 1.
    - `test_claims_in_turn_never_repeat` (Review Focus 3): ten claims in a row are distinct and increasing.
  - `ReferenceNumbersTest`:
    - `test_the_format_and_the_count_per_prefix`: `RE-2026-0001`, `RE-2026-0002`, `PR-2026-0001`.
    - `test_the_year_turns_in_damascus_time` (Review Focus 5): `Carbon::setTestNow('2026-12-31 23:59', 'Asia/Damascus')` gives `RE-2026-…`; `2027-01-01 00:01` gives `RE-2027-0001`.
  - `NextExportNumberTest`:
    - cold → `next` null and `first_required` true;
    - seeded → `next` 187;
    - a section manager sees the register of the إدارة above the section;
    - a non-manager gets 403.
- [ ] **Step 2:** Run the three files. Expect FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the files; PASS. Run the gates.

---

### Task 6: Creating a document from a template

**Files:**
- Create: the `add_template_columns_to_documents_table` migration: `card_floor` unsignedTinyInteger nullable after `printed_path`, and `index('template_id')`.
- Modify:
  - `Requests/CreateDocumentRequest.php`, `Support/CreateDocumentInput.php`, `Services/DocumentCreation.php`;
  - `Pdf/CardLayout.php` (`place(int $count, float $pageWidth, float $pageHeight, float $floor = self::BOTTOM_MARGIN): CardPlacement`; the block's bottom is `$floor` from the page's foot), `Pdf/DocumentPrinter.php` (passes `$document->card_floor ?? CardLayout::BOTTOM_MARGIN`);
  - `Models/Document.php` (fillable/casts; `template(): BelongsTo`, `withTrashed`);
  - `Resources/DocumentResource.php`, `Controllers/DocumentController.php` (search), `Requests/DocumentsIndexRequest.php` if needed.
- Test: `tests/Feature/Documents/CreateFromTemplateTest.php`; update `CreateDocumentTest`, `CardLayoutTest`, `DocumentListsTest`.

**Interfaces:**
- Consumes: Tasks 1–5.
- Produces:
  - **`POST /api/documents`**, unchanged for uploads, plus:
    - `template_id` (prohibited with `file`);
    - `field_values` (object);
    - `export_number` (integer 1–999999999, prohibited without `template_id`).

    `file` becomes `required_without:template_id` with its copy «الملف مطلوب.».
  - **`CreateDocumentInput`:** `?UploadedFile $file`, `?int $templateId`, `array $fieldValues`, `?int $exportNumber`. The existing parameters keep their names and order. The new ones are added before `attachments`, with defaults.
  - **`DocumentCreation::create`**, for a template, in this order:
    1. Before any write, refuse:
       - the unit, approvers and وارد checks of piece 2;
       - the template: live and active, else «القالب المختار غير موجود أو غير مفعّل.»; `for_employees`, else «هذا القالب للمستندات المرسلة إلى الموظفين.»;
       - `FieldValues::check` against its fields;
       - a typed صادر number already used (`ExportNumbers::isUsed`), or a cold register with none typed (`ExportNumbers::next` is null), so the refusal comes before the transaction.
    2. One transaction:
       1. re-read the template (the version and page used are the ones read here);
       2. `ExportNumbers::claim`, then `ReferenceNumbers::claim($template->reference_prefix)`;
       3. `TemplatePrinter::print` inside `PdfWorkspace::run`, storing its file as `documents/{folder}/original.pdf` (a write the disk refuses throws);
       4. create the row with:
          - `origin` Template;
          - `template_id`, `template_version`;
          - `field_values` (normalised);
          - `export_number`, `reference`;
          - `card_floor`;
          - `uploaded_path` null;
       5. the attachments, then `submit` (piece 2's route).

       A throw anywhere removes the folder and stored attachments, as piece 2 does. The counter and sequence roll back with the transaction. A `UniqueConstraintViolationException` on `export_number` answers «رقم الصادر {n} مستخدم في سجل هذه الإدارة.».
  - **`DocumentResource`** adds:
    - `export_number`, `reference`;
    - `template` (`{id, name, version}` or null; a deleted template still shows);
    - `field_values`.
  - **The lists' `search`:**
    - digits match `import_number` or `export_number`;
    - any text also matches `reference` (`like`, escaped) besides the title.

- [ ] **Step 1: Write the failing tests**
  - `CreateFromTemplateTest`:
    - `test_a_manager_creates_a_document_from_a_template`: 201 «تم إرسال المستند للاعتماد.». `origin` 2, `export_number` the claimed one, `reference` `RE-{year}-0001`, `template_version` the template's, `original.pdf` exists, `uploaded_path` null, the route as piece 2's.
    - `test_the_first_document_of_a_register_needs_its_number`, `test_a_used_number_is_refused_and_nothing_is_written`: no row, no folder, the counter unchanged.
    - `test_a_file_and_a_template_together_are_refused`, `test_an_export_number_without_a_template_is_refused`
    - `test_an_inactive_or_employee_template_is_refused`
    - `test_field_values_are_checked_and_stored_normalised`
    - `test_an_edit_after_the_form_was_opened_uses_the_current_version` (Review Focus 4): save the template between two requests; the document records the new version. Then deactivate it; the next request is refused.
    - `test_a_print_failure_rolls_the_numbers_back`: bind a `TemplatePrinter` that throws; the counter, the sequence and the disk are unchanged.
    - `test_a_unique_violation_on_the_export_number_answers_its_copy` (the race of Review Focus 3): bind an `ExportNumbers` whose `isUsed` answers false and whose `claim` returns a number already on a document of the register. The answer is «رقم الصادر {n} مستخدم في سجل هذه الإدارة.», and no row or folder is left.
    - `test_cards_print_above_the_footer`: a template document printed with a footer has `card_floor` 32. `CardLayoutTest` gains `test_a_floor_raises_the_block`.
  - `DocumentListsTest`: `test_search_finds_by_export_number_and_reference`.
  - `CreateDocumentTest`: the existing «الملف مطلوب.» test still passes; the upload flow is unchanged.
- [ ] **Step 2:** Run the files. Expect FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the files; PASS. Run the gates.

---

### Task 7: سجل الصادر

**Files:**
- Modify: `Controllers/ExportRegisterController.php` (`index`), `routes.php`.
- Test: `tests/Feature/Documents/ExportRegisterTest.php`

**Interfaces:**
- Produces: **`GET /api/documents/registers/{department}`** (`whereNumber`; a deleted unit is a 404 at binding).
  - **When refused:**
    - a unit that is not a register (level `Division` or `GeneralAdministration`) → 422 «هذه الوحدة لا تملك سجل صادر.»;
    - a caller who is neither that unit's own manager (`unitOf($caller)?->is($department)`) nor a holder of `documents.view` → 403 with its copy.
  - **The list:** every document of that register with an `export_number`, deleted ones included, newest number first, paginated.
  - **Each row:**
    - `export_number`, `reference`, `title`, `created_at`;
    - `creator` `{id, full_name}`;
    - `status` `{value, label}`;
    - `deleted` (bool), and `deleted_label` «محذوف» when deleted, else null.
  - `search` matches the title or a number, as the lists do.

- [ ] **Step 1: Write the failing tests**
  - `test_the_division_manager_reads_the_register_with_deleted_rows_marked`
  - `test_a_section_manager_under_it_is_refused`
  - `test_documents_view_reads_any_register`
  - `test_a_section_is_not_a_register`
  - `test_a_closed_document_shows_as_not_completed`: status 7, «لم يُستكمل».
- [ ] **Step 2:** Run the file. Expect FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the file; PASS. Run the gates.

---

### Task 8: Docs, Postman, the work order

- [ ] **Step 1:** `docs/API_DOCUMENTATION.md`:
  - a new §20.15 *Templates and categories*: every endpoint of Tasks 3–4, the field schema, the chip format and its keys, the whitelist, the preview;
  - a new §20.16 *Numbers*: the next number, the rules of صادر and the reference, سجل الصادر;
  - §20.5 (creating) gains the template variant;
  - §20.6 and the list's search, the new numbers;
  - the resource's new fields;
  - quick-reference rows.
- [ ] **Step 2:** Postman: a folder "Documents - templates and numbers" with every new request, keeping the TAB formatting. Validate the JSON.
- [ ] **Step 3:** Work order §8: a dated entry (the day it is built) on piece 3 and the calls listed below.
- [ ] **Step 4:** `docs/START_HERE.md`: piece 3's line.
- [ ] **Step 5:** Run the gates. Stop.

---

## Calls made in this plan, for the owner to check

1. **A `for_employees` template is for HR's sending only.** It is not offered between managers, and creating such a document from one is refused. The spec says the flag "puts it on HR's send screen and allows the recipient's placeholders". A recipient chip on a document between managers would print empty.
2. **Cards sit above the letterhead's footer** on a template document (32 mm instead of 6 mm). Otherwise every card would cover the company's footer.
3. **`symfony/html-sanitizer`** cleans the page. mPDF reads local files and URLs from images and CSS, so the page is whitelisted by a maintained library rather than by hand.
4. **Every edit bumps the version**, activating and deactivating included.
5. **A template needs a category, and names are unique**, as are category names.
6. **Field types keep legacy's keys** (`text`, `textarea`, `number`, `date`, `select`, `table`), so legacy's 20 templates move one to one. Table rows are stored keyed by column, not by position as legacy did.
7. **Empty optional values print nothing**; numbers print as typed; dates print `Y/m/d`.
8. **Template pages print in DejaVu Sans**, as legacy's template pages do, not Amiri (ruling R9 revised). **Piece 2's cards do not:** they keep mPDF's automatic Arabic font, XB Riyaz (`PdfSession`'s `autoLangToFont => true`), which loses some letters when the text is copied or searched. Moving the cards to DejaVu Sans is a change to piece 2's printer, not made in this plan; it is the owner's follow-up.
9. **Endpoint paths** sit under `/api/documents/…` (`templates`, `template-categories`, `numbers/next`, `registers/{unit}`), beside the stamps and the letterhead, not at spec §16's `/api/document-templates`.
10. **Any signed-in account reads the active templates** (they are forms, not records); writing and preview need `documents.templates.manage`.
