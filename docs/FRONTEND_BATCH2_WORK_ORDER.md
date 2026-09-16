# Frontend Batch 2 — the prompt

**Give the agent one file:** the current `API_DOCUMENTATION.md` from this repo
(`docs/API_DOCUMENTATION.md`, 1236 lines, revision 2026-09-13). It must replace
`erp-dashboard/API_DOCUMENTATION.md`, which is a **stale 779-line copy** that
predates both the departments `sector` work and all of Batch 2. An agent reading
the stale copy will implement the wrong API and be confident about it.

```powershell
Copy-Item "C:\Users\M.Hawa\Desktop\work\alraqi-erp-system\docs\API_DOCUMENTATION.md" `
          "C:\Users\M.Hawa\Desktop\work\erp-dashboard\API_DOCUMENTATION.md" -Force
```

Nothing else needs to be shared — the prompt below carries everything the
contract does not, and the agent reads the rest of the code itself.

---

## The prompt

```text
Act as a senior React frontend engineer working directly in:
C:\Users\M.Hawa\Desktop\work\erp-dashboard

The backend shipped "Batch 2": it restructured the HR employee resource and added three resources beside it. Migrate this dashboard to match. Implement the work in the repository — do not merely describe it, and do not paste whole files or a large diff into chat.

## Authoritative contract

API_DOCUMENTATION.md at the repo root. Confirm it is the 1236-line revision dated 2026-09-13 before reading it; if it is the older 779-line file, STOP and tell me, because that copy is stale and describes the pre-Batch-2 API. Read §1 Pagination, §2 RBAC model, and all of §8 HR. The contract is authoritative: never invent an endpoint, query parameter, response field or permission.

## Ground rules

- Inspect the current implementation before editing. Preserve correct existing behaviour and naming; repair or finish it rather than building a parallel module beside it.
- Stay with the existing stack: React 18, Vite, the Redux action/reducer conventions in src/redux, Axios, React Bootstrap, react-select, SweetAlert2, react-toastify. Do not add a dependency unless it is genuinely necessary and you explain why first.
- Reuse the existing shell: PageHeader, DataTableServer, TablePaginationServer, Loader, ProtectedElement/ProtectedRoute, utils/toast, utils/apiHelpers (parseApiError, fetchAllPages), sections/departments/useResourceOptions. New Redux slices follow the departments pair and get registered in src/redux/Store.jsx.
- Enum fields arrive as an int plus an Arabic `{field}_label`. RENDER THE SERVER'S LABEL. A static option list is allowed to populate a form input, never as a display mapping.
- Gated fields are ABSENT from the payload, not null. A field the user cannot read must not be rendered AND must not be submitted — sending a blank input for a hidden field destroys data the editor was never allowed to see.
- Not every optional field is nullable. Omit a field rather than sending null unless the contract marks it nullable.
- All dates are date-only YYYY-MM-DD. Never parse them through Date or moment; a timezone shift moves a birthday.
- Decimals (agreed_salary) stay strings. Validate with string operations; never round-trip through a float.
- Keep Arabic/RTL and the dashboard's visual language. Show the server's Arabic messages. Read the document as UTF-8; do not copy mojibake from a mis-decoded terminal.
- Treat server data as untrusted. Render plain text; never dangerouslySetInnerHTML.
- Scope edits to HR plus the smallest shared change needed. Do not touch the departments section (already correct for sector/level), auth, roles or notifications unless an item below names them, and do not refactor the leftover legacy sections (advertisements, vehicles, packages, subscribers, faqs, complaints).
- Handle loading, empty, error, retry and permission-denied states. Prevent double submission and stale-response updates.
- There is no test runner configured. Do not add one. Run `npm run build` and `npm run lint`, and separate new failures from pre-existing ones.

## What changed, and what it breaks today

The employee shape changed:
- `name` became `first_name` + `middle_name` (اسم الأب) + `last_name` (الكنية), plus a read-only `full_name`.
- `job_title` (free string) became `job_title_id` plus an embedded `job_title: {id, name}`.
- `salary` is GONE. Pay is computed from criteria; only the negotiated track stores `agreed_salary`.
- `status: "active"` became `status: 1` with `status_label: "نشط"`. The old "inactive" is now `2` موقوف (SUSPENDED — a different meaning; do not carry the old label over). `3` is منتهي الخدمة.
- 41 new columns: demographics, contact, military service, education, employment, the classification axes and the pay reference.

`GET /api/auth/me` and `/api/auth/users` still return `name` — that is an ACCOUNT's display name and did not change. Only the employee resource split. Make sure you can tell the two apart before editing anything.

Currently broken, all confirmed: src/sections/hr/Employees.jsx columns `name`, `job_title`, `salary`, the `قسم #{id}` department badge and the string-keyed STATUS_BADGES; src/sections/hr/EmployeeFormModal.jsx emptyForm and its payload, which submits name/job_title/salary/status:"active" and none of the four now-required create fields, so every create and update is a 422; src/sections/accounts/UserFormModal.jsx line 20, whose employee picker reads `e.name` and degrades every option to "موظف #id".

What already works in those files and must survive: permission-gated column building; the "don't render, don't send" rule for gated fields; omitting `employee_number` when blank so the server generates it; string-only decimal handling; verbatim YYYY-MM-DD rendering; the last-page repair after a delete. All correct — they just need to cover more fields.

## Field-level gating (widened)

`hr.employees.viewIdentity` gates `national_id`.
`hr.employees.viewSalary` now gates `salary_mode(_label)`, `agreed_salary`, `sham_cash_account` AND all nine classification axes: `activity_type(_label)`, `organizational_grade`, `experience_level`, `engineering_rank(_label)`, `industrial_experience_years`, `has_hazard_pay`, `has_scarcity_pay`, `has_dual_role_pay`, `distinction_grade`.

That is thirteen form inputs, not two. Gate on the permission array from login/me. The axes are gated with the amount because they are the inputs the salary is computed from.

## Build these, in this order

**1. Permissions catalogue and navigation.**
Extend src/data/permissionsCatalog.js from 16 to all 28 permissions, keeping the hasPermission convention. The new twelve, with the exact Arabic labels from the backend seeder (the API document lists machine names only and no endpoint serves a label — use these, do not invent alternatives):
hr.employees.viewSalary عرض رواتب الموظفين · hr.employees.viewIdentity عرض أرقام هوية الموظفين · hr.job_titles.view عرض المسميات الوظيفية · hr.job_titles.create إنشاء مسمى وظيفي · hr.job_titles.update تعديل مسمى وظيفي · hr.job_titles.delete حذف مسمى وظيفي · hr.assignments.view عرض سجل التعيين · hr.assignments.create تسجيل تعيين أو نقل · hr.warnings.view عرض الإنذارات · hr.warnings.create إصدار إنذار · hr.warnings.update تعديل إنذار · hr.warnings.delete حذف إنذار.
Mark the two field-level ones as field-level in the matrix data — they gate fields, not routes, and an admin granting them should see that. Add routes and sidebar entries under the existing "الموارد البشرية" group for hr/job-titles (hr.job_titles.view) and hr/employees/:id (hr.employees.view, route only, no sidebar entry). Never call a /permissions endpoint; none exists.
Also create ONE module, src/data/hrEnums.js, exporting {value, label} option lists FOR FORM INPUTS ONLY: gender, marital_status, status, employment_type, salary_mode, activity_type, education_level, engineering_rank, governorate, and assignment reason. Comment at the top of the file that display always uses the server's {field}_label.

**2. Employees list.**
Fix the columns: `full_name`; the embedded `job_title.name` (fall back to —, never to the id); `department.name`; delete the salary column; status from `status_label` with colours keyed on the int (1 success, 2 warning, 3 danger). Add `legacy_number` — HR will hold paper carrying M024333 for years after the import.
Add a server-side filter toolbar sending only: status, department_id, job_title_id, activity_type, search, sort. Do not filter the loaded page in the browser and call it a filter. Gate the activity_type control behind hr.employees.viewSalary to match the field gate on that axis. Sort keys are exactly employee_number, first_name, last_name, hire_date, created_at, each with an optional leading `-`; `full_name` is NOT sortable because it is a computed accessor, and an unknown sort is a 422 so never send one. Changing a filter resets to page 1. Read meta.per_page back rather than assuming your request was honoured. Rows open the profile route from item 4.

**3. Employee form.**
Group it into labelled sections — الهوية، البيانات الشخصية، التواصل، الخدمة العسكرية، التعليم، العمل، التصنيف والرواتب. A flat column of forty inputs is not usable; use a tabbed or accordion modal, or a full-page form, and say which you chose and why.
Required on create: first_name, middle_name, last_name, gender. Everything else optional. employee_number is auto-generated as EMP-00001 when omitted, and on UPDATE it cannot be cleared (rule is `filled`).
OMIT rather than null — these are optional but NOT nullable, and sending null is a 422: nationality, wives_count, sons_count, daughters_count, has_hazard_pay, has_scarcity_pay, has_dual_role_pay, status, salary_mode, and employee_number on update.
The whole classification and pay block renders only with hr.employees.viewSalary, and national_id only with hr.employees.viewIdentity — absent from the form AND absent from the payload without the permission.
Mirror these cross-field rules client-side in Arabic, and still map the server's 422 by exact field name: engineering_rank only when activity_type = 1 (هندسي) and industrial_experience_years only when activity_type = 2 (صناعي), clearing and disabling the other; salary_mode = 2 (إتفاق) REQUIRES agreed_salary while salary_mode = 1 (نظام رواتب) FORBIDS it; wives_count only when marital_status = 2; termination_date may not precede hire_date; sham_cash_account is exactly 16 digits. children_count is derived from sons_count + daughters_count — display it, never submit it.
job_title_id uses a selector over GET /hr/job-titles (hr.job_titles.view), preferring is_active titles; on selection, pre-fill organizational_grade and activity_type from the title's default_* values when those inputs are empty, and make clear they are suggestions the user may override. department_id keeps the existing departments.view selector. direct_manager_id is a selector over /hr/employees (hr.employees.view); a person may not be their own manager. Where a selector's permission is missing, fall back truthfully — never invent a lookup endpoint.

**4. Employee profile page.**
Route hr/employees/:id backed by GET /hr/employees/{id}, gated on hr.employees.view. Header: full_name, employee_number, legacy_number, department.name, job_title.name, status badge. Three tabs, each gating independently — a user without a tab's permission sees the profile without that tab, not an error.
نظرة عامة: the full record read-only, honouring both field gates exactly as the form does.
سجل التعيين (hr.assignments.view): paginated, newest first — department, job title, grade, from_date, to_date, reason_label, notes. Mark the `is_current: true` row clearly. Render legacy_workplace and legacy_manager_name when present; they exist so an unresolvable imported value stays visible to a human. Recording a posting (hr.assignments.create) requires from_date (may be back-dated, may NOT be in the future) and reason (int, no default — the user must choose). department_id, job_title_id and organizational_grade are optional, and this is the subtle part: OMITTING a field carries the current value forward, while an explicit null CLEARS it. Build the form so a promotion can state only the new grade and a title change only the new title, and so "no unit" is a deliberate separate action; explain the distinction in the UI copy. There is NO update and NO delete for a posting and no permission that could authorize one — render no edit or delete affordance, including a stray action column inherited from DataTableServer. A posting dated on or before the current one's start amends it in place rather than stacking a new row; say so in the UI. Also tell the user, where they edit department/job title/grade on the employee form, that saving there records a transfer automatically with reason نقل داخلي dated today, and that this tab is how to record the real reason or a back-dated one.
الإنذارات (hr.warnings.view): paginated, newest first. Create is nested under the employee; update and delete are NOT nested — they address /hr/warnings/{warning}. `sequence` is computed by the server: display it, never send it. `issued_by` is an EMPLOYEE id, not a user id — a line manager may have no login — so use an employee selector. Fields: type (required on create), issued_on (required, not in the future), number (رقم كتاب الإنذار), issued_by, notes. Delete is soft: word the confirmation as withdrawal, not erasure.

**5. Job titles catalogue (دليل المسميات الوظيفية).**
New section under src/sections/hr/ plus its own actions/reducer pair. Endpoints GET/POST /hr/job-titles and GET/PUT/DELETE /hr/job-titles/{id}; params page, per_page, search, is_described, is_active, sort (name|-name|created_at|-created_at); default order by name. Boolean filters send 1/0 and an unknown value is a 422, not a silent fallback.
This is ONE shared company-wide catalogue with NO department_id. Do not add a department column, filter or field — "which units use this title?" is answered by filtering employees on job_title_id, so offer that as a link into the employees list instead.
Form: name (required, unique), default_organizational_grade (1–5, nullable), default_activity_type (enum, nullable), is_described, description (max 10000), is_active. Label the two default_* fields as suggestions that pre-fill the employee form, not constraints.
Give `?is_described=0` a first-class preset filter with a count — it is قسم التحليل والتوصيف's worklist of titles still awaiting a job description.
DELETE returns 409 with a `message` and NO `errors` object when the title is held by any employee or any historical posting; render it as a global error and do not soften it. A soft-deleted title keeps its name reserved, so recreating it returns a 422 uniqueness error — show the server's message rather than claiming the name is free.

**6. Ripple fix and sweep.**
Fix the employee picker label in src/sections/accounts/UserFormModal.jsx to use full_name plus employee_number so two people with the same name are distinguishable; keep submitting only employee_id. Leave `user.name` on the Users screen alone — it is an account display name. Then ripgrep src/sections and src/redux for any other consumer of the removed employee keys (`name`, `job_title` as a string, `salary`, `status` as a string) and fix each against the contract.

## Out of scope — do not build

- Employee documents / file upload. The employee_documents table exists for the importer, but there are NO endpoints; uploads belong to the Documents module being ported from the legacy system.
- Any display of a computed salary. The rate tables are not in the API; agreed_salary on the إتفاق track is the only pay figure that exists.
- Departments, auth, roles, notifications — already correct, including users/roles pagination. Note that API document §4.1 and §5.1 still contain a stale sentence saying those lists return "the full collection"; §1 Pagination is authoritative and the frontend already pages them correctly, so do not "fix" it to match the stale line.

## How to work and report

Do the six items in order. After each one, run `npm run build` and give me a two-or-three-line checkpoint — what you changed and whether it built — then continue without waiting for me. If an item is genuinely blocked, finish every other item and say exactly what you left out and why.

Verify before you finish: the list against a payload from a caller holding NEITHER field permission; each filter alone and combined; search by name part and by legacy_number; ascending and descending sort; create with only the four required fields; create with the full record; an update touching one field; every cross-field rule rejected client-side then accepted from the server; a caller WITHOUT viewSalary editing an employee who HAS a salary (the stored value must survive); a transfer that carries department forward while changing grade; an explicit null that clears a department; a future from_date rejected; a 409 job-title delete; each profile tab with and without its permission.

Finish with a concise report: changed files, behaviour completed, checks run, real blockers. No file contents, no full diff. Under 300 words.
```

---

## Two things I flagged to the backend, not blockers for this work

- `?activity_type=` filters the employee list with **no `viewSalary` check**, so a
  caller without the permission can still partition staff by a gated axis. The
  prompt tells the frontend to gate the control anyway.
- **GAP-12** (`API_BUILD_STANDARD.md` §12): `hr.employees.update` alone authorizes
  *writing* pay and identity fields a caller cannot read. The frontend mitigation
  — don't render, don't send — is the correct behaviour and is in the prompt.
