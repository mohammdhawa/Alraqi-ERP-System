# The Kobo Id as the Employee Number — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every employee's number — their sign-in — is their KoboToolbox id: required at creation, digits only, never changed.

**Architecture:** The `Employee` model drops its `EMP-…` generator and guards the rule itself (a backstop for every writer); the employee request validates it for the API; the importer writes the row's `ID_Sarah` as the number and adopts a hand-added person who already holds it; a one-off command renumbers development data. The dashboard's employee form and sign-in page follow.

**Tech Stack:** Laravel 12, PHP 8.3, PHPUnit feature tests, Pint · React 18, `node --test`.

**Spec:** [`docs/KOBO_EMPLOYEE_NUMBER_DESIGN.md`](../../KOBO_EMPLOYEE_NUMBER_DESIGN.md) (approved 2026-10-24).

## Global Constraints

- **No git writes.** Each task ends at an uncommitted checkpoint (memory: committing is the owner's decision); re-check `git status` before editing shared files.
- The rule: `employee_number` = digits only, 1–20 (`/^\d{1,20}$/`), unique across all employees including soft-deleted, required at creation, never changed.
- Exact messages (spec §7): «رقم كوبو مطلوب.» · «رقم كوبو يتكوّن من أرقام فقط.» · «رقم كوبو مستخدم لموظف آخر.» · «لا يمكن تغيير الرقم الوظيفي بعد إنشائه.»
- House style as the employee import plan: `declare(strict_types=1)`, docblocks that say why and cite the spec (§n, K1–K3), Arabic client messages, every new guard mutated once to prove a test fails.
- After each task: the whole suite (`php artisan test`) and `vendor/bin/pint --test` green; dashboard tasks: `npm test` and eslint on changed files.

## Review Focus

1. **A dev seeder re-run over employees that already have `EMP-…` numbers** — must not try to change them (the guard would throw); seeding twice changes nothing. Test in Task 1.
2. **A number typed with spaces or a leading zero** (`" 582974643 "`, `0582974643`) — spaces are trimmed by the framework; a leading zero is refused, because `0582974643` and the import's `582974643` would be two people. Test in Task 2 (see Task 2's ruling).
3. **Sign-in with the Kobo id** — the account of an employee numbered `582974643` signs in with exactly that; an `EMP-…` login is gone for renumbered people. Test in Task 2.
4. **An import row whose id belongs to a soft-deleted, hand-added person** — adopted and restored, not created beside them. Test in Task 3.
5. **Renumbering onto a number someone else already holds** — that person is skipped and reported, nothing crashes, the rest are renumbered. Test in Task 4.

---

## File map

| File | Change |
|---|---|
| `app/Modules/HR/Models/Employee.php` | drop the generator; guard required / digits / never-changed; `renumberTo()` for the command |
| `app/Modules/HR/Exceptions/EmployeeNumberException.php` (new) | the guard's 422 refusals |
| `database/factories/EmployeeFactory.php` | a unique digit number per employee |
| `database/seeders/DevDepartmentSeeder.php` | an invented Kobo id, set only when the employee is created |
| `app/Modules/HR/Requests/EmployeeRequest.php` | create: required digits, unique; update: unchanged or refused |
| `app/Modules/HR/Import/EmployeeImporter.php` | number = `ID_Sarah`; adopt a hand-added holder; refuse a holder known under another key |
| `app/Console/Commands/RenumberFromKobo.php` (new) | `hr:renumber-from-kobo {--dry-run}` |
| `docs/API_DOCUMENTATION.md` (employees), `docs/EMPLOYEE_APP_V1_WORK_ORDER.md` (§4.10 line, §8 entry) | docs |
| `erp-dashboard`: `src/sections/hr/employeePayload.js` (+ test), `EmployeeFormModal.jsx`, `src/pages/Signin.jsx`, `src/redux/actions/authActions.jsx`, `src/data/employeeOption.js` | the form, the sign-in hint, comments |

---

### Task 1: The model's rule, the factory and the seeder

**Files:**
- Create: `app/Modules/HR/Exceptions/EmployeeNumberException.php`
- Modify: `app/Modules/HR/Models/Employee.php`, `database/factories/EmployeeFactory.php`, `database/seeders/DevDepartmentSeeder.php`
- Test: `tests/Feature/HR/EmployeeNumberTest.php` (new); update tests that relied on generated numbers (`tests/Feature/HR/EmployeeTest.php` §"auto-assigns", `tests/Feature/Auth/AccountModelTest.php` `EMP-…` values, and any other test the suite shows failing on a missing number)

**Interfaces:**
- Produces: `EmployeeNumberException` (implements `DomainException`, 422) with `required()`, `notDigits()`, `immutable()`; `Employee::renumberTo(string $number): void` — saves a new number past the never-changed guard (for Task 4 only); the factory's `employee_number` — `'7'.str_pad(<per-process counter>, 8, '0', STR_PAD_LEFT)`.

- [ ] **Step 1: Write the failing tests** in `EmployeeNumberTest` (model level — direct `Employee::create` / `update`, no HTTP):
  - `test_an_employee_cannot_be_created_without_a_number` — `Employee::factory()->make(['employee_number' => null])->save()` throws `EmployeeNumberException` «رقم كوبو مطلوب.»
  - `test_a_number_must_be_digits` — `'EMP-00042'` and a 21-digit string throw «رقم كوبو يتكوّن من أرقام فقط.»; `'582974643'` saves.
  - `test_a_number_never_changes` — create with `'582974643'`, then `update(['employee_number' => '582974644'])` throws «لا يمكن تغيير الرقم الوظيفي بعد إنشائه.»; saving another field with the number untouched works.
  - `test_an_existing_non_digit_number_can_still_be_saved` — an employee whose stored number is `'EMP-00001'` (set with `DB::table('employees')->update(...)`, as a development row from before) saves a phone change without refusal: the digits rule applies at creation and renumbering only.
  - `test_renumber_to_is_the_one_way_past_the_guard` — `renumberTo('582974644')` saves it; `renumberTo('EMP-1')` throws «رقم كوبو يتكوّن من أرقام فقط.»
  - `test_the_factory_gives_unique_digit_numbers` — three factory employees: all match `/^\d{1,20}$/`, all distinct.
  - `test_the_dev_seeder_gives_digit_numbers_and_leaves_existing_ones` *(Review Focus 1)* — seed `DevDepartmentSeeder` twice: every employee's number matches `/^\d{1,20}$/` and the second run changes none; then an employee whose number was set to `'EMP-00001'` with `DB::table` (a seeded row from before this change) survives a re-seed unchanged.
- [ ] **Step 2: Run** `php artisan test tests/Feature/HR/EmployeeNumberTest.php` — FAIL.
- [ ] **Step 3: Implement.** Remove `NUMBER_PREFIX`, `NUMBER_WIDTH`, `nextEmployeeNumber()`, `formatNumber()` and the `creating` assignment; in `creating`, refuse a blank or non-digit number; in `updating`, refuse `isDirty('employee_number')` unless `renumberTo()` set its private flag (the `correct()` pattern); `renumberTo()` checks digits itself. **Never check digits on every save**: development rows numbered `EMP-…` before this change must stay editable. Factory adds the number. The seeder sets `employee_number` only when the record is new (`firstOrNew` by email, then fill; the invented id from a fixed base plus the person's position, e.g. `'9100'.str_pad(<index>, 5, '0')`).
- [ ] **Step 4: Run** the whole suite; update every test that now fails only because it relied on a generated number (state a digit number); re-run until green; Pint.
- [ ] **Step 5: Checkpoint.**

### Task 2: The API's rule, and sign-in

**Files:**
- Modify: `app/Modules/HR/Requests/EmployeeRequest.php`, `docs/API_DOCUMENTATION.md` (the employee section's `employee_number`)
- Test: `tests/Feature/HR/EmployeeTest.php`, `tests/Feature/Auth/AccountModelTest.php`

**Interfaces:**
- Consumes: Task 1's model rule.
- Produces: create — `employee_number` `required`, `string`, `regex:/^[1-9]\d{0,19}$/`, `unique:employees,employee_number`; update — `sometimes` plus a closure rule: a value other than the stored one → «لا يمكن تغيير الرقم الوظيفي بعد إنشائه.»

> **Task 2: Ruling (planned):** a leading zero is refused with «رقم كوبو يتكوّن من أرقام فقط.» — Kobo ids never start with 0, and `0582974643` would not match the import's `582974643`, making two people. The spec's "digits only, 1–20" is kept; this narrows it by one character class. *(Review Focus 2)*

- [ ] **Step 1: Write the failing tests** in `EmployeeTest` (replace `test_store_auto_assigns_an_employee_number` and `test_a_supplied_employee_number_is_respected`):
  - `test_store_requires_the_kobo_id` — no `employee_number` → 422 `errors.employee_number.0` «رقم كوبو مطلوب.»
  - `test_store_takes_digits_only` — `'EMP-00042'`, `'0582974643'`, a 21-digit value → 422 «رقم كوبو يتكوّن من أرقام فقط.»; `' 582974643 '` → 201 with `data.employee_number` `'582974643'` (trimmed).
  - `test_store_refuses_a_number_another_employee_holds` — including a soft-deleted holder → 422 «رقم كوبو مستخدم لموظف آخر.»
  - `test_update_refuses_a_new_number_and_accepts_the_same` — PATCH a different number → 422 «لا يمكن تغيير الرقم الوظيفي بعد إنشائه.»; PATCH the same number with another field → 200.
  - In `AccountModelTest`: `siteWorker()` defaults to `'582974643'`; `test_signs_in_with_the_kobo_id` *(Review Focus 3)* — login `'582974643'` → 200; login `'EMP-00042'` → 401.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** the rules and messages (`required`, `regex`, `unique` with the spec's text); update the API doc.
- [ ] **Step 4: Run** — PASS; suite and Pint.
- [ ] **Step 5: Checkpoint.**

### Task 3: The import writes and adopts the number

**Files:**
- Modify: `app/Modules/HR/Import/EmployeeImporter.php` (`importOne()`)
- Test: `tests/Feature/HR/Import/EmployeeImporterTest.php`

**Interfaces:**
- Consumes: `EmployeeRowMapper::koboId()` (digits of `ID_Sarah`), `EmployeeImportSource::employeeFor()` / `remember()`.

- [ ] **Step 1: Write the failing tests:**
  - `test_a_new_person_is_numbered_by_their_kobo_id` — import `row(582974643)` → `Employee::sole()->employee_number === '582974643'`; the credentials row carries it.
  - `test_a_hand_added_person_with_that_number_is_adopted` — `Employee::factory()->create(['employee_number' => '582974643'])`, then import the row (dry run, then real) → `Employee::count() === 1`, outcome `updated`, the `kobo:582974643` key now points at that employee; no new account if they had one.
  - `test_a_soft_deleted_hand_added_person_is_adopted_and_restored` *(Review Focus 4)* — same, the holder soft-deleted → outcome `restored`, one employee.
  - `test_a_holder_known_under_another_key_is_refused_not_merged` — an employee imported from `kobo:111` whose number is set (via `renumberTo`) to `'582974643'`; a row `582974643` → outcome `failed` with an issue naming the conflict; `Employee::count()` unchanged.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement.** Creating: `employee_number` = the row's Kobo digits. Matching: `$existing = employeeFor(key) ?? Employee::withTrashed()->where('employee_number', <digits>)->first()`; a holder found by number who already has any `employee_import_sources` row → throw a `DomainException` (422) «الرقم الوظيفي <digits> مسجّل لموظف مستورد من سطر آخر — لم يُستورد هذا السطر.» so the row reads `failed` with that message.
- [ ] **Step 4: Run** — PASS; suite and Pint.
- [ ] **Step 5: Checkpoint.**

### Task 4: `hr:renumber-from-kobo`

**Files:**
- Create: `app/Console/Commands/RenumberFromKobo.php`
- Test: `tests/Feature/HR/RenumberFromKoboTest.php` (new)

**Interfaces:**
- Consumes: `Employee::renumberTo()` (Task 1), `AuthService::revokeAllUserTokens(int $userId)`.
- Produces: `hr:renumber-from-kobo {--dry-run}` — one transaction; for each `kobo:<id>` source whose employee's number ≠ `<id>`: skip (and report) when another employee holds `<id>`, else `renumberTo(<id>)` and end that employee's account sessions. Prints «Renumbered N, already numbered M, skipped K (number taken).» `--dry-run` prints the same and writes nothing.

- [ ] **Step 1: Write the failing tests:** `test_imported_people_get_their_kobo_id_and_lose_their_sessions` (an employee with key `kobo:582974643` and number `'700000001'`, a user with a refresh token → number becomes `'582974643'`, token count 0); `test_people_without_a_kobo_key_are_left_alone`; `test_running_twice_changes_nothing`; `test_a_dry_run_writes_nothing`; `test_a_taken_number_is_skipped_and_reported` *(Review Focus 5)* (another employee already numbered `'582974643'` → that one skipped, output names the count, others renumbered).
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** — PASS; suite and Pint. Run it with `--dry-run` on the development database and report the counts; run it for real only if the owner says so.
- [ ] **Step 5: Checkpoint.**

### Task 5: The dashboard

**Files (erp-dashboard):**
- Modify: `src/sections/hr/employeePayload.js` (+ `.test.js`), `src/sections/hr/EmployeeFormModal.jsx`, `src/pages/Signin.jsx`, `src/redux/actions/authActions.jsx`, `src/data/employeeOption.js`

**Interfaces:**
- Consumes: Task 2's API rules and messages.

- [ ] **Step 1: Write the failing tests** in `employeePayload.test.js`: create without a number → `errors.employee_number` `["رقم كوبو مطلوب."]`; `'EMP-1'` and `'0582974643'` → `["رقم كوبو يتكوّن من أرقام فقط."]`; `'582974643'` → no error, sent; an edit payload never contains `employee_number` (even when the form holds one).
- [ ] **Step 2: Run** `npm test` — FAIL.
- [ ] **Step 3: Implement.** The form: on create the field is «رقم كوبو (الرقم الوظيفي)», required, `inputMode='numeric'`; on edit read-only with the hint «لا يتغيّر بعد الإنشاء». Sign-in placeholder `582974643 / name@example.com`; the two comments and `employeeOption.js`'s describing `EMP-…` updated.
- [ ] **Step 4: Run** `npm test`, eslint on the changed files, a development build — PASS. Browser check against the development API with a test account (as in the import plan): add an employee with and without a valid number, open an existing one (read-only number), the sign-in placeholder. Screenshot each.
- [ ] **Step 5: Checkpoint.**

### Task 6: Record it, and the final review

- [ ] **Step 1:** Work order: a dated §8 entry (what changed, K1–K3, the assumptions, the test counts) and one line under §4.10 — the app's login field takes the Kobo id (digits, numeric keyboard). Spec status line → built.
- [ ] **Step 2:** One fresh reviewer (most capable model) over both repositories' diffs with the spec, this plan and its Review Focus; re-grade; fix Critical and Important ones RED→GREEN; ledger the minors.
- [ ] **Step 3:** Report, with every ruling and deferred minor.
