# The workbook column map — what the importer reads, and where it goes

> **Written 2026-10-10** for work order §4.1, from the October files the import
> reads (work order §8):
>
> - `Program_HR_10_2026.xlsx` — `قاعدة البيانات` (185 active), `إنهاء عمل` (216)
> - `فصل الانتاجية/Program_HR_9_2026-انتاجية.xlsx` — `قاعدة البيانات` (52 active)
>
> Header row is row 3 of each sheet, and there is an empty column A. **Read
> columns by header name, never by position**: the three sheets do not share a
> column order, and `إنهاء عمل` has six unnamed columns (`Column1`–`Column6`,
> `عمود1`) the other two lack. Counts below are *filled / rows*, active sheets
> combined (237) unless a sheet is named.

---

## 1. The import key

| Column | Filled | Use |
|---|---|---|
| `ID_Sarah` | 236/237 active, unique · **absent from `إنهاء عمل`** | **The proposed source key for active rows**, as `id_sarah:<value>` in `employee_import_sources` |
| `الرقم الوظيفي` (`M024333`) | 108/237, 127/216 leavers | Not imported (decision 11). Too sparse to key on |
| `التسلسل` | all | Row order. Not stable across HR's edits; not a key |

⚠️ **Open:** what `ID_Sarah` is, whether it is stable, and how leavers are keyed —
the leavers sheet has no `ID_Sarah`, no national ID, and 76 rows without a unit.
**58 names appear on both an active sheet and `إنهاء عمل`** (rehires, or
duplicates); the importer must report each pair, never merge by name silently.

## 2. Identity

| Column | Filled | → ERP | Rule |
|---|---|---|---|
| `الإسم الثلاثي` | all | `first_name`, `middle_name`, `last_name` | 320 of 453 names have 3 words. **133 have 4–6** (`خلدون قرنفل علي قرنفل`), 3 have 2. Rule: first word, second word, rest as `last_name`; report every non-3-word name for HR to check. Leavers are often a kunya only (`أبو إبراهيم عندان`) |
| `اللقب` | 76/237 | — | The kunya (`أبو عبد الرحمن`). **No column for it.** Decide whether it matters to HR — it is how half the site staff are addressed |
| `الجنس` | active only | `gender` | `ذكر` / `انثى`. Leavers: `Column4` holds it, unnamed |
| `مواليد_دقيقة` | main sheet only | `birth_date` | A full date |
| `المواليد` | all | `birth_date` year | Production and leavers carry **only the year**. Do not invent `YYYY-01-01`; leave `birth_date` null and report — or decide a year-only rule |
| `المحافظة` | all active | `residence_governorate` (+ `address`) | **47 distinct values, not 14**: governorates mixed with towns and full street addresses (`حلب - منطقة الباب - شارع الكورنيش…`). Match a leading governorate name (`حماه` = `حماة`, `ادلب` = `إدلب`), put the whole string in `address`, report what matches nothing (`عندان`, `فاس`, `عمان. الكرك`) |
| `الحالة الاجتماعية` | most | `marital_status` | `أرملة`/`مطلقة` arrive feminine. **3 rows hold phone numbers** — a shifted row; report |
| `عدد الزوجات` | most | `wives_count` | |
| `عدد الأبناء` | most | `sons_count`? | One number; the ERP splits sons and daughters. **Cannot be split** — decide: store in neither, or add a total |
| `معرف / رقم  التواصل` | all active | `phone` | 235 digits, 2 usernames (`alhmdllorbalalmeen`) → report, do not store as a phone |

## 3. Employment

| Column | Filled | → ERP | Rule |
|---|---|---|---|
| `أسماء الإدارات` · `القسم` · `المكتب` | all · 224 · 153 | `department_id` | Resolved against the seeded tree, by code. See `hr-review/org-tree-review-2026-10-10.xlsx`: 6 active rows need a unit HR has not confirmed; 70 resolve through the drop rules |
| `المسمى الوظيفي` | all | `job_title_id` | Dedup by name into `job_titles` (86 distinct on the main sheet alone) |
| `السوية التنظيمية` | all | `organizational_grade` | 1–5 |
| `نوع النشاط` | all | `activity_type` | `هندسي / مالي / إداري / صناعي / أمني` |
| `الحالة` (active) | all | `employment_type`, `status = active` | `متعاقد` / `جماعة` / `يحتاج تثبيت` |
| `الحالة` (leavers) | all | `status` | `إنهاء عمل` 148, `نقل لصلد` 52, `نقل لاصل` 15, `إجازة بدون راتب` 1 — **waiting on HR** (review file, questions 2–3) |
| `تاريخ التعيين` | all | `hire_date` | Several are `YYYY-01-01` — likely year-only data typed as a date. Imported as given; the opening posting follows it |
| `تاريخ انهاء العمل` | 100/216 leavers | `termination_date` | Half the leavers have none |
| `مكان العمل` | 86/237 | `workplace` | Free text |
| `مكان الدوام` | 35/237 | `workplace` if `مكان العمل` is blank | Overlaps `مكان العمل`; `-` means blank |
| `مكان الراتب` | most | — | **Payroll cost centre** (`الآليات الثقيلة`, `مكتب رئيسي - هندسي`, project sites). **No ERP column.** Stage 4 needs it; not v1 |
| `مكان العمل بداية التعيين` | 118/237 | opening posting `legacy_workplace` | |
| `المدير المباشر بداية التعيين` | 156/237 | opening posting `legacy_manager_name` | |
| `المسمى الوظيفي بداية التعيين` | 125/237 | — | The opening posting takes today's `job_title_id` from the model hook. A different first title is lost unless the importer writes the opening posting's title from this column — decide |
| `تاريخ الإنذار` · `رقم الإنذار` | 2 rows | `employee_warnings` | `نوع الإنذار` is empty everywhere |
| `ملاحظات` | 67 leavers | — | Report only |

## 4. Classification — the pay criteria

| Column | → ERP | Rule |
|---|---|---|
| `الخبرة` | `experience_level` | ⚠️ **Three kinds of value in one column:** `خبرة 1`–`خبرة 5`, bare numbers `0`–`4`, and — for engineers — the engineering rank again (`ممارس`, `مشارك2`). Plus 108 `#N/A` across the three sheets. Map `خبرة N` and `N`; ignore a rank here when `الرتبة الهندسية` holds it; report the rest |
| `الرتبة الهندسية` | `engineering_rank` | ⚠️ **`مشارك2`, `مشارك3`, `مشارك4` exist; the enum has one `مشارك`.** The rank carries pay, so the sub-levels cannot be folded. The April intake form lists 12 ranks against the enum's 9. **Blocks importing engineers' ranks** — ask HR for the rank list and its rate table |
| `سنوات الخبرة الصناعية` | `industrial_experience_years` | |
| `الخطورة` | `has_hazard_pay` | `خطورة` → true, blank → false |
| `الندرة` · `إزدواجية العمل` | `has_scarcity_pay` · `has_dual_role_pay` | Empty on the active sheets |
| `التمييز بحسب التقييم` | `distinction_grade`? | ⚠️ Fractions (`57.14…`, `362.85…`) on 29 rows — a computed amount, not a grade. Do not import into the integer column; ask what it is |
| `حالة الراتب ( نظام / إتفاق )` | `salary_mode` | `نظام رواتب` / `اتفاق` |
| `الراتب المتفق عليه` | `agreed_salary` | 107/237; required when the mode is `اتفاق` — the guard enforces it |
| `رقم الشام كاش` | `sham_cash_account` | All active values are 16 digits; **2 values are shared by two people** — the column is unique, so report both |
| `المرحلة الدراسية` | `education_level` | 13 cases match. **3 rows hold a date** (`2025-06-01`) — Excel ate the value; report |
| `المؤهل العلمي` · `الاختصاص الدقيق` | `qualification` · `specialization` | |
| `تاريخ التخرج الدقيق` | `graduation_date` | 31 rows |

**Not imported — derived or computed:** `سنوات القدم`, `سنة_القدم_احتساب الي`
(both from `hire_date`), every `قيمة …` column, `أساس الراتب`, both
`إجمالي الراتب` columns, and the one-off monthly items (`بدل السكن`,
`منحة رئاسية`, `إضافي`, `مكافئة`, `بدل ولادة`, `حسم`). They are payroll output;
the rate table and calculator are Stage 4. They stay useful as the
**reconciliation target**: the Stage 4 calculator must reproduce
`إجمالي الراتب` for the same month.

## 5. The three source questions — `EMPLOYEE_APP_REQUIREMENTS.md` §7.2

1. **A personal photo?** **No.** No sheet carries one. The app needs a photo
   upload path from day one, or home shows no photo.
2. **Contract start and end dates?** **No, for almost everyone.** No column on
   the active sheets. `إنهاء عمل` has a column headed `تاريخ نهاية العقد`, but
   it holds salary modes and leave notes, not dates. The separate sheet
   `نموذج العقود` lists about 20 contracts (type, months, start, end) — too few
   to seed contract records from. **قرب انتهاء العقد cannot fire for anyone at
   import.**
3. **More than one certificate, or a prior-experience list?** **No.** One
   education record per person (level, qualification, specialization,
   graduation date) and no experience history. Typed documents are enough for
   §5.8; no second table.

## 6. Decisions this map needs

| # | Question | Who |
|---|---|---|
| 1 | What is `ID_Sarah`, and how are leavers keyed? | HR / project owner |
| 2 | The engineering-rank sub-levels (`مشارك2`–`4`) — the full list and their rates | HR |
| 3 | What `التمييز بحسب التقييم` holds | HR |
| 4 | Year-only birth dates: null, or a stated convention? | project owner |
| 5 | `عدد الأبناء` cannot be split into sons and daughters | project owner |
| 6 | `اللقب` and `مكان الراتب` have no column — needed in v1? | project owner |
| 7 | The opening posting's title from `المسمى الوظيفي بداية التعيين`, or today's? | project owner |
