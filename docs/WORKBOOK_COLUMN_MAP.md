# The workbook column map — what the importer reads, and where it goes

> **Written 2026-10-10** for work order §4.1, from the October files the import
> reads (work order §8):
>
> - `Program_HR_10_2026.xlsx` — `قاعدة البيانات` (185 active), `إنهاء عمل` (216)
> - ~~`فصل الانتاجية/Program_HR_9_2026-انتاجية.xlsx` — `قاعدة البيانات` (52 active)~~
>   ⚙️ **Not read** (project owner, 2026-10-14): the production division was
>   transferred to صلد (HR's September report). **The import is the main
>   sheet's 185 people.** Counts below that say "237" or "active sheets
>   combined" include the 52 and are kept for the record.
> - ⚙️ **Added 2026-10-14 (HR, work order §8):** `استقبال وتعديل شركة صرح/مستقبل صرح 9-2026.xlsx`
>   — the KoboToolbox «استمارة بيانات الموظفين» export, 296 records, joined on
>   `_id` = `ID_Sarah` (232 of the 236 active with an `ID_Sarah` are in it). It
>   fills the fields §2 marks **صرح**.
>
> ⚙️ **Leavers are not imported** (HR, 2026-10-14): `إنهاء عمل` is not read, and
> every leaver-only row below is for reference.
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
| `ID_Sarah` | 236/237 active, unique | ✅ **The import key**, as `kobo:<value>` in `employee_import_sources`. HR confirmed (2026-10-14) it is the Kobo `_id` of the employee's record and never changes |
| `الرقم الوظيفي` (`M024333`) | 108/237, 127/216 leavers | Not imported (decision 11). Too sparse to key on |
| `التسلسل` | all | Row order. Not stable across HR's edits; not a key |

✅ **Settled 2026-10-14.** The one active row with no `ID_Sarah`, and the 4 whose
`ID_Sarah` is not in the صرح file, are **reported, not keyed some other way** —
HR adds the number, and the next run picks them up. The 58 names that appear on
both an active sheet and `إنهاء عمل` no longer matter: leavers are not imported.

## 2. Identity

| Column | Filled | → ERP | Rule |
|---|---|---|---|
| `الإسم الثلاثي` | all | `first_name`, `middle_name`, `last_name` | 320 of 453 names have 3 words. **133 have 4–6** (`خلدون قرنفل علي قرنفل`), 3 have 2. Rule: first word, second word, rest as `last_name`; report every non-3-word name for HR to check. Leavers are often a kunya only (`أبو إبراهيم عندان`) |
| `اللقب` | 76/237 | `nickname` | ✅ Column added 2026-10-14 (needed in v1). Trimmed; blank → null |
| `الجنس` | active only | `gender` | `ذكر` / `انثى`. Leavers: `Column4` holds it, unnamed |
| صرح `تاريخ التولد` | 232/232 matched | `birth_date` | ✅ A full date for every matched employee, production included — **preferred over the workbook** |
| `مواليد_دقيقة` | main sheet only | `birth_date` | Fallback when the صرح record is missing |
| `المواليد` | all | — | Year only. Used to cross-check the full date; a disagreement is reported |
| صرح `الرقم الوطني في الهوية` | 232/232 | `national_id` | ⚠️ **207 have 10 digits** where a Syrian national number has 11 — Excel dropped a leading `0`. 9 have 11, and 10 are shorter (9 or 6 digits). **2 values are shared by two people.** ✅ **The importer restores the leading `0` on the 10-digit ones** (2026-10-14); the shorter ones and the duplicates are reported |
| صرح `اسم الام` | 232/232 | `mother_name` | |
| صرح `الصورة الشخصية_URL` | 231/232 | — | **Not imported** (2026-10-14): hosted on `kf.kobotoolbox.org`, and no KoboToolbox API token is available. Employees add their photo from the app |
| `المحافظة` | all active | `residence_governorate` (+ `address`) | **47 distinct values, not 14**: governorates mixed with towns and full street addresses (`حلب - منطقة الباب - شارع الكورنيش…`). Match a leading governorate name (`حماه` = `حماة`, `ادلب` = `إدلب`), put the whole string in `address`, report what matches nothing (`عندان`, `فاس`, `عمان. الكرك`) |
| `الحالة الاجتماعية` | most | `marital_status` | `أرملة`/`مطلقة` arrive feminine. **3 rows hold phone numbers** — a shifted row; report |
| `عدد الزوجات` | most | `wives_count` | |
| صرح `عدد الأبناء الذكور` · `عدد الأبناء الاناث` | 194/232 | `sons_count` · `daughters_count` | ✅ The split the workbook lacked. Blank on 38 → null, not 0 |
| `عدد الأبناء` | most | — | One number; cross-check only — the صرح split wins |
| `معرف / رقم  التواصل` | all active | `phone` | 235 digits, 2 usernames (`alhmdllorbalalmeen`) → report, do not store as a phone |

## 3. Employment

| Column | Filled | → ERP | Rule |
|---|---|---|---|
| `أسماء الإدارات` · `القسم` · `المكتب` | all · 224 · 153 | `department_id` | Resolved against the seeded tree, by code. See `hr-review/org-tree-review-2026-10-10.xlsx`: 6 active rows need a unit HR has not confirmed; 70 resolve through the drop rules |
| `المسمى الوظيفي` | all | `job_title_id` | Dedup by name into `job_titles` (86 distinct on the main sheet alone) |
| `السوية التنظيمية` | all | `organizational_grade` | 1–5 |
| `نوع النشاط` | all | `activity_type` | `هندسي / مالي / إداري / صناعي / أمني` |
| `الحالة` (active) | all | `employment_type`, `status = active` | `متعاقد` / `جماعة` / `يحتاج تثبيت` |
| `الحالة` (leavers) | all | — | Not imported (HR, 2026-10-14). For the record: `إنهاء عمل` 148, `نقل لصلد` 52, `نقل لاصل` 15, `إجازة بدون راتب` 1 |
| `تاريخ التعيين` | all | `hire_date` | Several are `YYYY-01-01` — likely year-only data typed as a date. Imported as given; the opening posting follows it |
| `تاريخ انهاء العمل` | 100/216 leavers | `termination_date` | Half the leavers have none |
| `مكان العمل` | 86/237 | `workplace` | Free text |
| `مكان الدوام` | 35/237 | `workplace` if `مكان العمل` is blank | Overlaps `مكان العمل`; `-` means blank |
| `مكان الراتب` | most | `payroll_location` | ✅ Column added 2026-10-14 (needed in v1). The payroll cost centre (`الآليات الثقيلة`, `مكتب رئيسي - هندسي`, project sites); free text, pay-gated |
| `مكان العمل بداية التعيين` | 118/237 | opening posting `legacy_workplace` | |
| `المدير المباشر بداية التعيين` | 156/237 | opening posting `legacy_manager_name` | |
| `المسمى الوظيفي بداية التعيين` | 125/237 | opening posting `legacy_job_title` | ✅ ⚙️ **Option B (project owner, 2026-10-15):** one opening posting with **today's** title; the starting title is kept beside it as text, like the two columns above — not a second posting, because the workbook never says when the title changed. Not deduped into `job_titles`. (Supersedes the 2026-10-14 reading, which put it in `job_title_id`.) |
| `تاريخ الإنذار` · `رقم الإنذار` | 2 rows | `employee_warnings` | `نوع الإنذار` is empty everywhere |
| `ملاحظات` | 67 leavers | — | Report only |

## 4. Classification — the pay criteria

| Column | → ERP | Rule |
|---|---|---|
| `الخبرة` | `experience_level` | ⚠️ **Three kinds of value in one column:** `خبرة 1`–`خبرة 5`, bare numbers `0`–`4`, and — for engineers — the engineering rank again (`ممارس`, `مشارك2`). Plus 108 `#N/A` across the three sheets. Map `خبرة N` and `N`; ignore a rank here when `الرتبة الهندسية` holds it; report the rest |
| `الرتبة الهندسية` | `engineering_rank` | ✅ **Settled 2026-10-14:** مشارك1–4 approved beside مشارك, استشاري kept, ركن retired; `EngineeringRank` now matches (مشارك1–4 = 10–13). Active employees hold only approved ranks |
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

1. **A personal photo?** ~~No~~ ⚙️ **Yes, in the صرح file** (2026-10-14): 231 of 232
   matched employees have one, on KoboToolbox — but **no API token is available
   to download them**, so none is imported. **The app needs a photo upload
   path from day one** (work order §8).
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
| ~~1~~ | ~~What is `ID_Sarah`, and how are leavers keyed?~~ ✅ Kobo `_id`, stable; leavers not imported (2026-10-14) | — |
| ~~2~~ | ~~The engineering-rank sub-levels~~ ✅ مشارك1–4 approved (2026-10-14) | — |
| 3 | What `التمييز بحسب التقييم` holds | HR |
| ~~4~~ | ~~Year-only birth dates~~ ✅ The صرح file has full dates | — |
| ~~5~~ | ~~`عدد الأبناء` cannot be split~~ ✅ The صرح file splits it | — |
| ~~6~~ | ~~`اللقب` and `مكان الراتب`~~ ✅ Needed in v1 — `nickname`, `payroll_location` added (2026-10-14) | — |
| ~~7~~ | ~~The opening posting's title~~ ✅ The title they started with (2026-10-14) | — |
| ~~8~~ | ~~10-digit national IDs~~ ✅ Restore the leading `0` (2026-10-14) | — |
| ~~9~~ | ~~A KoboToolbox API token~~ ✅ None — no photos imported; upload from the app (2026-10-14) | — |
