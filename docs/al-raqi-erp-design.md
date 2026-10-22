# مشروع ERP — شركة الراقي للإنشاءات
# Al-Raqi Construction Company — ERP System Design Document
# ──────────────────────────────────────────────────────────
# هذا الملف يحتوي على كامل التصميم الذي توصلنا إليه.
# أرسل هذا الملف في بداية أي محادثة جديدة للمتابعة من حيث توقفنا.
 
---
 
## نظرة عامة
 
نظام ERP مخصص لشركة إنشاءات سورية. أعمل عليه بمفردي كمدير القسم التقني، بمساعدة Claude Code.
 
### Technology Stack
- **Backend:** Laravel (PHP) + Sanctum Authentication
- **Web Frontend:** React
- **Mobile Frontend:** Flutter + Riverpod + Dio + GoRouter
- **Design System:** Gold (#C8A36B) + Navy (#224167), Arabic RTL
- **Hosting:** VPS on Hostinger, accessed via Cloudflare Tunnel
---
 
## قرار تصميمي أساسي: فصل الموظفين عن المستخدمين
 
- جدول `employees` → كل شخص يعمل بالشركة (حتى لو لا يستخدم النظام)
- جدول `users` → فقط من لديهم صلاحية دخول للنظام
- العلاقة: `User belongsTo Employee` — كل user هو employee، لكن ليس كل employee هو user
---
 
## الموديولات التسعة (مرتبة حسب أولوية البناء)
 
---
 
### المرحلة 1 — الموظفين والأقسام والصلاحيات (2-3 أسابيع)
 
```
departments
├── id
├── name
└── manager_id → employees
 
employees
├── id
├── name, phone, email, address
├── department_id
├── job_title
├── hire_date
├── salary (basic)
└── status: active / inactive / terminated
 
users
├── id
├── employee_id → employees
├── email, password
└── is_active
 
roles
├── id, name, description
 
permissions
├── id, name, module, description
 
role_permissions → role_id, permission_id
user_roles → user_id, role_id
 
audit_logs
├── user_id, action, model_type, model_id
├── old_values (JSON), new_values (JSON)
├── ip_address, created_at
 
notifications
├── user_id, title, body, type
├── reference_type, reference_id, is_read
```
 
---
 
### المرحلة 2 — الحضور والإجازات (2-3 أسابيع)
 
```
attendances
├── employee_id, date
├── check_in, check_out
├── check_in_method: manual / qr / fingerprint / gps
├── total_hours, overtime_hours
├── status: present / absent / late / half_day / holiday
├── project_id (nullable)
└── notes
 
attendance_settings
├── work_start_time, work_end_time
├── late_threshold_minutes
├── overtime_rate
├── working_days (JSON)
└── effective_from
 
leave_types
├── name, default_days_per_year
├── is_paid, requires_attachment
 
leave_balances
├── employee_id, leave_type_id, year
├── total_days, used_days, remaining_days
 
leave_requests
├── employee_id, leave_type_id
├── start_date, end_date, days_count
├── reason, attachment_path
├── status: pending / approved / rejected / cancelled
├── reviewed_by, reviewed_at
```
 
**الربط:** الإجازة المقبولة → غياب مبرر في attendances → تنعكس على الرواتب.
 
---
 
### المرحلة 3 — الرواتب (3-4 أسابيع)
 
**المنطق:** النظام يحسب تلقائياً → يولد مسودة → HR يراجع ويعدّل → يعتمد.
 
```
salary_structures
├── employee_id
├── basic_salary
├── housing_allowance, transport_allowance, food_allowance
└── effective_from
 
payrolls (دورة شهرية)
├── month, year
├── status: draft → reviewed → approved → paid
├── generated_by (system), approved_by (HR)
 
payslips (سطر لكل موظف)
├── payroll_id, employee_id
├── basic_salary, total_allowances
├── overtime_hours, overtime_amount
├── deductions, bonuses
├── net_salary_calculated    ← حساب النظام
├── net_salary_final         ← بعد تعديل HR
├── is_manually_adjusted     ← flag
├── adjustment_reason
└── status
 
payslip_lines (تفصيل كل بند)
├── payslip_id
├── type: earning / deduction
├── label: "بدل سكن", "خصم غياب", "إضافي"
├── amount_calculated        ← النظام
├── amount_final             ← بعد تعديل HR
└── note
```
 
**النقطة الذكية:** كل سطر فيه `amount_calculated` + `amount_final`. بالبداية متطابقين. لو HR عدّل، بس amount_final يتغير والأصلي محفوظ = audit trail كامل.
 
---
 
### المرحلة 4 — المهام (2-3 أسابيع)
 
**نظام Kanban مشابه لـ Trello مع دعم التعاون بين الإدارات.**
 
```
boards
├── department_id (nullable), name, created_by
 
board_columns
├── board_id, name, sort_order
  ("جديدة"، "قيد التنفيذ"، "مراجعة"، "منجزة")
 
tasks
├── board_id, column_id, sort_order
├── title, description
├── priority: low / medium / high / urgent
├── created_by → employees (المدير)
├── assigned_to → employees (المنفذ)
├── department_id
├── project_id (nullable)    ← ربط بمشروع
├── phase_id (nullable)      ← ربط بمرحلة مشروع
├── due_date
└── status: open / completed / cancelled
 
task_collaborators (التعاون بين الإدارات)
├── task_id, employee_id
├── role: assignee / reviewer / collaborator
 
task_comments
├── task_id, employee_id, body
 
task_attachments
├── task_id, file_path, uploaded_by
 
task_activity_log
├── task_id, employee_id
├── action: created / moved / assigned / commented / completed
├── old_value, new_value
```
 
**سيناريو التعاون بين الإدارات:** مدير إدارة ينشئ مهمة ويضيف موظف من إدارة أخرى كـ collaborator. الموظف يرى المهمة في "مشترك فيها" بدون تداخل مع لوحة إدارته.
 
**الصلاحيات:**
- المدير → ينشئ / يسند / يحذف مهام إدارته + يضيف collaborators من أي إدارة
- الموظف → يحرّك مهامه بين الأعمدة + يعلّق
- المدير العام → يشوف ويتدخل بكل الإدارات
---
 
### المرحلة 5 — المشاريع (3-4 أسابيع)
 
```
projects
├── name, code (auto: "PRJ-2026-003")
├── client_name, client_phone, location, description
├── contract_value, estimated_cost
├── actual_cost (auto-calculated from expenses)
├── start_date, expected_end_date, actual_end_date
├── status: draft / active / on_hold / completed / cancelled
├── manager_id → employees
├── department_id
└── profit_margin (calculated: contract_value - actual_cost)
 
project_phases
├── project_id, name, sort_order
├── start_date, end_date
├── status: pending / in_progress / completed
├── completion_percentage
└── budget
 
project_teams
├── project_id, employee_id
├── role: "مدير مشروع" / "مهندس موقع" / "مشرف" / "فني"
├── assigned_from, assigned_until
 
project_expenses
├── project_id, phase_id (nullable)
├── category: materials / labor / equipment / subcontractor / other
├── description, amount, date
├── receipt_path, recorded_by, approved_by
 
project_materials
├── project_id, phase_id
├── material_name, quantity_required, quantity_received
├── unit, unit_cost, supplier
└── status: requested / ordered / received / partial
 
project_daily_logs (تقارير الموقع اليومية)
├── project_id, date, weather
├── workers_count, summary, issues
├── reported_by
└── photos
 
project_documents
├── project_id
├── type: contract / drawing / permit / report / other
├── title, file_path, uploaded_by, version
```
 
**ملاحظة:** الشركة تستخدم DJI drones — صور ومقاطع الدرون تُربط بالمشروع عبر daily_logs أو جدول project_media.
 
---
 
### المرحلة 6 — المشتريات والموردين (2-3 أسابيع)
 
**المسار:** طلب شراء ← موافقة ← أمر شراء ← استلام ← دخول المخزن.
 
```
suppliers
├── name, contact_person, phone, email, address
├── category: materials / equipment / services / subcontractor
├── tax_number, rating, notes
 
purchase_requests
├── code (auto: "PR-2026-015")
├── requested_by, department_id
├── project_id (nullable), phase_id (nullable)
├── urgency: normal / urgent / critical
├── status: draft / pending_approval / approved / rejected
├── approved_by, notes
 
purchase_request_items
├── purchase_request_id, item_name
├── quantity, unit, estimated_unit_cost, specifications
 
purchase_orders (بعد الموافقة)
├── code (auto: "PO-2026-008")
├── purchase_request_id, supplier_id
├── total_amount
├── status: issued / partial_received / received / cancelled
├── payment_status: unpaid / partial / paid
├── expected_delivery_date, approved_by
 
purchase_order_items
├── purchase_order_id, item_name
├── quantity_ordered, quantity_received
├── unit_cost, total_cost
```
 
---
 
### المرحلة 7 — المخازن (2 أسابيع)
 
```
warehouses
├── name, location
└── keeper_id → employees
 
inventory_items
├── name, sku, unit
├── category: construction / electrical / plumbing / safety / tools
├── minimum_stock
└── current_stock (calculated from movements)
 
inventory_movements
├── item_id, warehouse_id
├── type: in / out / transfer / adjustment
├── quantity
├── reference_type ("purchase_order" / "project_withdrawal")
├── reference_id
├── project_id (nullable)
├── done_by, date, notes
 
stock_alerts
├── item_id, warehouse_id
├── alert_type: low_stock / overstock / expiring
├── threshold, is_resolved
```
 
---
 
### المرحلة 8 — الأصول (1-2 أسبوع)
 
```
assets
├── name, code (auto: "AST-001")
├── category: vehicle / heavy_equipment / tool / electronics / drone
├── purchase_date, purchase_cost, current_value
├── status: available / in_use / maintenance / retired
├── assigned_to (nullable), location, warranty_expiry
 
asset_assignments
├── asset_id, employee_id, project_id (nullable)
├── assigned_date, returned_date, condition_on_return
 
asset_maintenance
├── asset_id
├── type: preventive / repair
├── description, cost, done_by
├── date, next_maintenance_date
```
 
---
 
### المرحلة 9 — المالية (3-4 أسابيع)
 
```
accounts (شجرة الحسابات)
├── code, name
├── type: asset / liability / equity / revenue / expense
├── parent_id (nullable), is_active
 
transactions (القيود)
├── date, description
├── reference_type ("payroll" / "purchase" / "client_payment")
├── reference_id, created_by
└── status: draft / posted
 
transaction_lines
├── transaction_id, account_id
├── debit, credit
├── project_id (nullable)
└── description
 
treasury
├── name, type: cash / bank
├── balance (calculated), account_id
 
clients
├── name, type: individual / company
├── phone, email, address, tax_number
├── contact_person, notes
 
client_contracts
├── client_id, project_id, contract_number
├── contract_date, value, file_path
├── payment_terms
├── status: active / completed / terminated
 
client_payments
├── contract_id, amount, payment_date
├── method: cash / bank_transfer / check
├── reference_number, receipt_path, recorded_by
```
 
---
 
## خريطة الربط بين الموديولات
 
- العملاء → عقود ودفعات → المشاريع
- المشاريع → مهام المشروع → المهام
- المشاريع → فريق العمل → الموظفين
- المشاريع → سحب مواد → المخازن
- المشاريع → طلبات شراء → المشتريات
- المشاريع → مصاريف → المالية
- المشاريع → تخصيص معدات → الأصول
- الموظفين → تسجيل حضور → الحضور
- الموظفين → طلب إجازة → الإجازات
- الموظفين → بيانات الراتب → الرواتب
- الموظفين → مهام مسندة → المهام
- الموظفين → عهدة → الأصول
- الحضور → ساعات إضافي + غياب → الرواتب
- الإجازات → خصم إجازة بدون راتب → الرواتب
- الإجازات → غياب مبرر → الحضور
- المشتريات → أمر شراء → الموردين
- المشتريات → إدخال مواد → المخازن
- المشتريات → قيد مشتريات → المالية
- الرواتب → قيد رواتب → المالية
- العملاء → قيد دفعات → المالية
---
