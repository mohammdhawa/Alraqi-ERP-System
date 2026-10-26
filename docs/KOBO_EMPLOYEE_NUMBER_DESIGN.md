# The Kobo id as the employee number — the design

> **Status: built, 2026-10-24** (plan `docs/superpowers/plans/2026-10-24-kobo-employee-number.md`;
> `hr:renumber-from-kobo` not yet run on any database). Agreed with the
> project owner in conversation the same day, part by part: every employee has
> one, it never changes, approach A, part 1 (the rule), part 2 (the dashboard and
> the app), part 3 (errors and testing).
>
> Read with [`EMPLOYEE_IMPORT_UPLOAD_DESIGN.md`](EMPLOYEE_IMPORT_UPLOAD_DESIGN.md)
> (the import that writes it), [`WORKBOOK_COLUMN_MAP.md`](WORKBOOK_COLUMN_MAP.md)
> §1 (`ID_Sarah`) and [`API_BUILD_STANDARD.md`](API_BUILD_STANDARD.md).

---

## 1. What changes

Every employee's number — `employees.employee_number`, **the identity they sign
in with** (account model, 2026-09-23) — becomes their KoboToolbox id, the
`ID_Sarah` of HR's workbook (e.g. `582974643`). Generated numbers (`EMP-00027`)
go. HR knows people by this id, and the id never changes (HR, 2026-10-14).

## 2. Decided

| # | Question | Answer |
|---|---|---|
| K1 | Someone without a Kobo id? | **Every employee must have one.** Added by hand → the Kobo id is required; an import row without one stays skipped |
| K2 | Can the number change after creation? | **Never.** A wrong one = delete the employee and add them again — deleting frees the number (2026-10-24) |
| K3 | Approach | **A** — the number is the Kobo id; the import keeps matching people through `employee_import_sources` (`kobo:<id>`) and writes the same id into `employee_number` when it creates someone. Rejected: matching by `employee_number` itself (B — rewires matching and restore logic just reviewed twice; a bug there duplicates people); a separate `kobo_id` column (C — two columns that must always agree) |

**Assumed, confirm at review:**

1. No real employee exists on a production server yet (the real import, Batch
   4, has not run), so no one loses an `EMP-…` login they use. Only development
   databases hold `EMP-…` numbers.
2. The development seeders get invented Kobo ids for their test staff.

Decisions 10–11 stand: the importer's own key stays in `employee_import_sources`
beside the domain. Decision 10 kept a *legacy system's* identifier out of the
domain; the Kobo id is HR's own number for a person, and now the domain's.

## 3. The rule

`employee_number` = the Kobo id: **digits only, 1–20**, unique across all
employees (soft-deleted ones included), **required at creation, never changed**.

- `Employee`'s automatic numbering (`NUMBER_PREFIX`, `NUMBER_WIDTH`,
  `nextEmployeeNumber()`) is removed.
- The model refuses a create without a number and any change to it (a backstop
  for every writer — seeders, scripts, the importer — beside the request's
  validation, as the classification guard does).

## 4. Where the number comes from

- **The import** (`EmployeeImporter`): a new person's number is the row's
  `ID_Sarah`, as the digits `EmployeeRowMapper::koboId()` already reads. A row
  without one stays `skipped`.
- **Adopting a person added by hand.** When a row's `kobo:<id>` key is not yet
  in `employee_import_sources` but an employee already has that number, the
  import **adopts** that employee: records the key and updates them as an
  existing person (outcome `updated`), instead of creating a duplicate. The
  report's changes list shows what it changed. Only the same person
  (project owner, 2026-10-24, after the final review): the holder must be live,
  unknown to the import, and carry the row's name, compared folded
  (`ArabicText::key`, so أحمد and احمد are one name). Otherwise the row is
  refused (`failed`), never merged — adopting hands the row the holder's
  record, history and account.
- **Deleting frees the number.** A soft-deleted employee's number becomes
  `<number>-deleted-<id>`, so K2's "delete and add again" can give the right
  number to its owner, by hand or by import. A restore takes the number back,
  and is refused while someone else holds it.
- **Added by hand** (`POST /api/hr/employees`): `employee_number` required,
  digits only, unique.
- **Never changed** (`PUT/PATCH /api/hr/employees/{id}`): a different
  `employee_number` → 422 «لا يمكن تغيير الرقم الوظيفي بعد إنشائه.»; the same
  value sent back is accepted (a form that re-sends it is harmless).

## 5. Existing development data — `hr:renumber-from-kobo`

A one-off command: every employee with a `kobo:<id>` key in
`employee_import_sources` gets `<id>` as their number — inside one transaction,
under the model's change guard lifted for this command alone — and every
session of their account ends (their login changed). Employees with no such
key (hand-added test data) keep their number. It reports how many it renumbered
and skipped, and does nothing for an employee whose number already is the id.
A `--dry-run` reports without writing.

The development seeders give every test employee an invented Kobo id, so
`migrate:fresh --seed` follows the rule.

## 6. The dashboard and the app

- **Employee form** (`EmployeeFormModal`, `employeePayload.js`): on create,
  «رقم كوبو (الرقم الوظيفي)», required, digits only, checked before sending with
  the server's messages; on edit, shown read-only with «لا يتغيّر بعد الإنشاء»
  and never sent.
- **Sign-in page**: the placeholder becomes `582974643 / name@example.com`;
  comments describing `EMP-…` are updated.
- Everywhere else the number shows as the server sends it (pickers «الاسم — …»,
  tables, the audit log): no change; comments and test fixtures naming the
  `EMP-…` format are updated where they describe it.
- **The Flutter app** (`doc_approval`) is not touched: it is not yet re-pointed
  at the ERP. Work order §4.10 gains one line — the login field takes the Kobo
  id: digits, a numeric keyboard.

## 7. Errors

| Case | Response |
|---|---|
| create without a number | 422 «رقم كوبو مطلوب.» |
| not digits, or more than 20 | 422 «رقم كوبو يتكوّن من أرقام فقط.» |
| a number another live employee holds (a deleted one has freed it) | 422 «رقم كوبو مستخدم لموظف آخر.» |
| a change on update, by the API or any other writer | 422 «لا يمكن تغيير الرقم الوظيفي بعد إنشائه.» |
| an import row whose Kobo id belongs to someone added by hand, live, with the row's name | adopted — updated, key recorded, no duplicate |
| … under another name | refused for that row (`failed`): «الرقم الوظيفي … مسجّل للموظف «…»، والاسم في الملف «…» — لم يُستورد هذا السطر. صحّح الرقم أو الاسم ثم أعد الاستيراد.» |
| … deleted before deleting freed numbers | refused for that row (`failed`): «الرقم الوظيفي … مسجّل لموظف محذوف (#…) — لم يُستورد هذا السطر.» |
| restoring someone whose number another employee took meanwhile | refused: «الرقم الوظيفي … صار لموظف آخر — لا يُستعاد هذا الموظف.» (an import row: `failed`) |
| an import row whose number belongs to someone the import knows under another key | refused for that row (`failed`), never merged; cannot occur under A, pinned by a test |

## 8. Testing

- The rules of §3 and §7 through the API, and the model's backstop through a
  direct create and update.
- An imported person's number equals their `ID_Sarah`; a hand-added person with
  that number is adopted, not duplicated, by a dry run and a real run.
- Sign-in with the Kobo id works.
- `hr:renumber-from-kobo`: renumbers imported people, ends their sessions,
  leaves others alone, is idempotent, and `--dry-run` writes nothing.
- The seeders produce employees with digit numbers and a working sign-in.
- The employee factory gives every employee a unique digit number; every test
  that relied on generated `EMP-…` numbers is updated to state one.
- Dashboard: `node --test` for the form's payload and its checks; the form and
  the sign-in page checked in the browser.
- Each new guard mutated and seen to fail its test.

## 9. Out of scope

Showing a separate Kobo id field (it is the number now); the Flutter app's
changes (§4.10); renumbering anything on a production server (none exists —
assumption 1).
