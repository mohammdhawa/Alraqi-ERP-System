# Alraqi ERP System - التقرير الهندسي الرئيسي

تاريخ التحليل الأصلي: 2026-06-18  
آخر تحديث للحالة: 2026-06-21  
نطاق التحليل: الملفات الموجودة فعليا داخل المستودع، مع تشغيل أوامر Laravel المحلية للتحقق من التسجيل الفعلي للمسارات والأوامر.

---

## 0. تحديث الحالة — 2026-06-21

> هذا القسم هو المرجع المعتمد للحالة الحالية. الأقسام من 1 إلى 19 أدناه هي لقطة تاريخية بتاريخ 2026-06-18 ومحفوظة كما هي للرجوع؛ حيثما تعارضت مع هذا القسم، فهذا القسم هو الصحيح. تم التحقق من كل بند أدناه عبر قراءة الكود وتشغيل `php artisan test` (نتيجة: **69 اختبار / 245 تأكيد، كلها ناجحة**) و`php artisan route:list -v` و`php artisan list`.

### 0.1 بنود تم حلها منذ لقطة 2026-06-18

| # | البند | الحالة السابقة (في اللقطة) | الحالة الآن | الدليل / الأقسام المُلغاة |
|---|-------|----------------------------|-------------|---------------------------|
| 1 | **RBAC** | placeholder فقط، لا جداول ولا `hasPermission` | منفذ ومُفعَّل بالكامل: جداول `roles`/`permissions`/`user_roles`/`role_permissions`، و`User::hasPermission`، و`CheckPermission` يفرض فعليا | يُلغي 8.5، 9، 13.1، 18.5 — `app/Modules/Auth/Models/{Role,Permission}.php`, `PermissionSeeder`, `RoleSeeder` |
| 2 | **فرض الصلاحية لكل إجراء** | `permission` غير مستخدم على أي مسار | كل إجراء محمي بصلاحيته الخاصة (`view/create/update/delete`) في Auth وDepartments وHR | `app/Modules/*/routes.php` + اختبارات `test_view_only_user_cannot_write` |
| 3 | **وحدة Departments** | مجلدات فارغة | CRUD كامل + صلاحيات لكل إجراء | يُلغي ذكرها في 7 و9 — `app/Modules/Departments/*` |
| 4 | **وحدة HR / Employees** | مجلدات فارغة | CRUD كامل + ربط User↔Employee + Department↔manager/members | يُلغي 13.7 وذكرها في 9 — `app/Modules/HR/*` |
| 5 | **إدارة المستخدمين (auth.users.*)** | صلاحيات معرفة بلا تنفيذ | CRUD كامل ومُفعَّل + حارس منع حذف الحساب الذاتي | `app/Modules/Auth/Controllers/UserController.php` + `UserTest` |
| 6 | **نظام Notifications** | غير موجود | منفذ، مقيد بملكية كل مستخدم | `app/Modules/Auth/Controllers/NotificationController.php` |
| 7 | **Rate limiting للمصادقة** | 1000/min وlogin throttle معلق | `auth-login` = 5/min و`auth-refresh` = 10/min، وlogin يستخدم `throttle:auth-login` فعليا | يُلغي 8.6، 13.4، 18.1 — `AppServiceProvider`, `Auth/routes.php` + `AuthThrottleTest` |
| 8 | **أمر prune للـ refresh tokens** | namespace لا يطابق path، غير مسجل، الجدولة معلقة | namespace `App\Console\Commands` يطابق المسار، الأمر مسجل (`php artisan list`)، ومجدول يوميا فعليا | يُلغي 8.7، 13.3، 18.2 — `routes/console.php` + `PruneRefreshTokensTest` |
| 9 | **ازدواجية نموذج User** | `App\Models\User` موجود وfactory/seeder يستخدمانه | `App\Models\User` محذوف؛ `config/auth.php` و`UserFactory` و`DatabaseSeeder` كلها على `App\Modules\Auth\Models\User`؛ وأُعيد توليد classmap في Composer لإزالة المدخل المعلق | يُلغي 8.3، 13.5، 14، 18.3 — `IdentityModelTest` |
| 10 | **معالجة Exceptions في الإنتاج** | catch عام في login يكشف تفاصيل الاستثناء في 500 | استثناءات auth المُنمَّطة تُعرض مركزيا في `bootstrap/app.php` دون تسريب أي تفاصيل | يُلغي 13.6 و17 (البند الأخير) — `bootstrap/app.php` |
| 11 | **Audit request middleware** | مسجل لكن غير مطبق على أي مسار | مطبق على المسارات المحمية في Auth وDepartments وHR | يُلغي 13.2 — `*/routes.php` + `test_audit_middleware_records_authenticated_requests` |
| 12 | **مجموعة اختبارات حقيقية** | اختبارات افتراضية فقط | 69 اختبار / 245 تأكيد: تدفق Auth، throttle، prune، identity، roles، users، Departments، HR | يُلغي ملاحظات الاختبارات في 6، 13، 18.4، 19 — `tests/Feature/*` |

### 0.2 بنود ما زالت مفتوحة

| البند | الحالة | ملاحظة |
|-------|--------|--------|
| وحدة Finance | scaffolding فارغ (لا ملفات) | أول bounded context تالٍ مقترح؛ HR منفذة بالفعل كنموذج يُحتذى |
| README المشروع | ما زال README الافتراضي لـ Laravel | لا يصف النظام؛ يحتاج توثيقا مخصصا |
| استراتيجية API versioning | غير مُدخلة | مطلوبة قبل توسع واجهة الـ API خارجيا |
| Multi-tenancy | غير مُدخلة (مؤجلة عمدا) | مذكورة كاتجاه مستقبلي في تعليقات `User`؛ تحتاج قرار سياسة قبل إضافة بيانات أعمال |

### 0.3 ملخص

اللقطة الأصلية وصفت النظام بأنه «أساس Auth + Shared فقط». منذ ذلك الحين اكتملت طبقة RBAC وفُرضت لكل إجراء، وأُضيفت وحدتا Departments وHR وإدارة المستخدمين والإشعارات، وأُغلقت كل ديون الـ hardening المذكورة (throttle، prune، توحيد نموذج User، معالجة الاستثناءات، تطبيق audit). المتبقي المفتوح هو وحدة Finance وREADME وقرارات مستقبلية (versioning، multi-tenancy) — لا أكثر.

---

## 1. الخلاصة التنفيذية

> ملاحظة: هذا القسم يعكس لقطة 2026-06-18. للحالة الحالية انظر القسم 0 أعلاه.

المشروع الحالي هو تطبيق Laravel 12 في مرحلة تأسيس Backend API لنظام ERP modular. الجزء المنفذ فعليا هو:

- وحدة مصادقة `Auth` داخل `app/Modules/Auth`.
- بنية مشتركة `Shared` للردود الموحدة، التدقيق audit logging، وmiddleware صلاحيات مستقبلية.
- مزود وحدات `ModuleServiceProvider` يكتشف routes وmigrations تلقائيا.
- بنية قاعدة بيانات أولية للمستخدمين، refresh tokens، audit logs، cache، queues، وSanctum personal access tokens.

لا توجد حاليا ميزات منفذة للمستندات، workflows، templates، rendering engine، HR، Finance، أو واجهة UI حقيقية. توجد مجلدات فارغة لـ `HR` و`Finance`، وتوجد إشارات مستقبلية لها في التعليقات والدليل الداخلي، لكنها ليست كودا عاملا.

## 2. الأدلة المستخدمة

اعتمد هذا التقرير على:

- فهرسة الملفات عبر `rg --files`.
- قراءة ملفات المسارات، providers، controllers، services، models، migrations، middleware، seeders، config، وtests.
- تشغيل `php artisan route:list` و `php artisan route:list -v`.
- تشغيل `php artisan list auth`.
- تشغيل `php artisan about`.
- تشغيل `php artisan test`.
- فحص TODOs والأنماط المعلقة عبر `rg`.

نتائج التشغيل المهمة:

- `php artisan route:list` أظهر مسارات Auth الأربعة: `api/auth/login`, `api/auth/refresh`, `api/auth/logout`, `api/auth/me`.
- `php artisan route:list -v` أظهر أن `login` لا يملك throttle مفعل، وأن `refresh` فقط عليه `throttle:auth-refresh`.
- `php artisan list auth` لم يعرض الأمر `auth:prune-refresh-tokens`، رغم وجود ملف له.
- `php artisan test` نجح، لكن الاختبارات الموجودة افتراضية فقط: اختبار `/` واختبار `true`.
- `php artisan about` أظهر Laravel `12.58.0` وPHP `8.2.12` وبيئة محلية تستخدم MySQL حسب الإعدادات المحملة.

## 3. الهدف العام للنظام

من الأدلة داخل الكود، الهدف الحالي هو بناء نواة ERP modular تبدأ بالمصادقة والبنية الأمنية المشتركة.

الأدلة:

- `app/Providers/ModuleServiceProvider.php` يصف نفسه بأنه auto-discovers ERP modules من `app/Modules`.
- `Guide-Line/1_Auth.md` عنوانه `ERP Auth Module - Integration & Architecture Guide`.
- `app/Modules/Auth/Models/User.php` يذكر أن `User` هو central identity model للـ ERP، وأن HR وFinance سيشيران إليه لاحقا.
- `app/Shared/Middleware/CheckPermission.php` يضع نمط صلاحيات `{module}.{resource}.{action}` مثل `hr.employees.view` و `finance.invoices.create`.

بناء على الموجود فعليا: النظام ليس Document Engine ولا Workflow Platform حاليا. الاتجاه المكتوب في الكود هو ERP Modular API، مع قابلية مستقبلية للتوسع إلى HR وFinance وRBAC وmulti-tenancy.

## 4. التقنيات والأطر المستخدمة

Backend:

- PHP `^8.2`.
- Laravel Framework `^12.0`، والنسخة المشغلة محليا `12.58.0`.
- Laravel Sanctum `^4.0` للتوكنات.
- Eloquent ORM وMigrations.
- Laravel queue database driver موجود في config وmigrations.
- PHPUnit `^11.5.50`.

Frontend/Assets:

- Vite `^7.0.7`.
- Tailwind CSS `^4.0.0`.
- Axios `^1.11.0`.
- `laravel-vite-plugin`.

لكن الواجهة الحالية scaffold افتراضي فقط:

- `resources/views/welcome.blade.php` صفحة Laravel الافتراضية.
- `resources/js/app.js` يستورد `bootstrap.js`.
- `resources/js/bootstrap.js` يضبط Axios فقط.
- `resources/css/app.css` يستورد Tailwind.

## 5. نقاط الدخول الرئيسية

### HTTP

- `public/index.php`: نقطة دخول Laravel القياسية.
- `bootstrap/app.php`: يربط routes وmiddleware aliases.
- `routes/web.php`: يعرض صفحة `/` الافتراضية.
- `routes/api.php`: يحتوي `/api/user` الافتراضي المحمي بـ `auth:sanctum`.
- `app/Modules/Auth/routes.php`: يحتوي مسارات Auth ويتم تحميله تلقائيا تحت prefix `api/auth`.

### Providers

- `bootstrap/providers.php`: يسجل `AppServiceProvider` و `ModuleServiceProvider`.
- `app/Providers/ModuleServiceProvider.php`: يحمّل routes وmigrations للوحدات.
- `app/Providers/AppServiceProvider.php`: يسجل rate limiters باسم `auth-login` و `auth-refresh`.

### Console

- `routes/console.php`: يحتوي الأمر الافتراضي `inspire` فقط، وجدولة prune معلقة بتعليق.
- `app/Console/Commands/PruneExpiredRefreshTokens.php`: ملف أمر تنظيف موجود، لكنه غير مسجل فعليا في Artisan بسبب mismatch في namespace/path وعدم ظهوره في `php artisan list auth`.

## 6. النمط المعماري

النمط الحالي: Modular Laravel Monolith مع Layered Architecture داخل كل module.

ليس microservices. لا توجد خدمات مستقلة أو حدود نشر منفصلة. التطبيق Laravel واحد، لكن النطاقات مفصولة داخل `app/Modules/{Module}`.

النمط داخل وحدة Auth:

```text
User
  -> Route
  -> Form Request
  -> Controller
  -> Service
  -> Model / DB / Sanctum
  -> Resource
  -> ApiRespond JSON Response
```

التدفق المبسط:

```text
Client
  -> /api/auth/*
  -> ModuleServiceProvider loaded route
  -> AuthController
  -> AuthService
  -> User / RefreshToken / Sanctum / audit_logs
  -> AuthResource
  -> ApiRespond envelope
```

مثال login:

```text
POST /api/auth/login
  -> LoginRequest validates email/password format
  -> AuthController::login()
  -> AuthService::login()
  -> User lookup + Hash::check + is_active check
  -> DB transaction
  -> Sanctum access token
  -> custom refresh_tokens row
  -> AuditLogService::logAction(user_logged_in)
  -> AuthResource + access_token + refresh_token
```

## 7. خريطة المجلدات

### `app/Modules`

المكان المقصود للوحدات الدومينية. الوحدة الوحيدة التي تحتوي ملفات عاملة هي `Auth`.

موجود:

- `app/Modules/Auth`: وحدة المصادقة.
- `app/Modules/HR`: مجلدات فارغة `Controllers`, `Models`, `Observers`, `Policies`, `Requests`, `Resources`, `Services`.
- `app/Modules/Finance`: مجلد موجود لكنه لا يحتوي ملفات حسب الفحص.

### `app/Modules/Auth`

هيكل طبقي كامل:

- `Controllers/AuthController.php`: HTTP layer.
- `Services/AuthService.php`: business logic.
- `Requests/LoginRequest.php`, `RefreshTokenRequest.php`: validation.
- `Resources/AuthResource.php`: API output transform.
- `Models/User.php`, `RefreshToken.php`: Eloquent models.
- `Exceptions/*`: exceptions مخصصة للتدفقات الأمنية.
- `routes.php`: API routes الخاصة بالوحدة.

### `app/Shared`

طبقة مشتركة بين الوحدات:

- `Traits/ApiRespond.php`: envelope موحد للـ JSON.
- `Traits/HasAuditLog.php`: تسجيل تغييرات Eloquent models.
- `Services/AuditLogService.php`: الكاتب المركزي لجدول `audit_logs`.
- `Middleware/AuditLogMiddleware.php`: تسجيل طلبات API للمستخدمين الموثقين.
- `Middleware/CheckPermission.php`: middleware صلاحيات مستقبلية.
- `Helpers`: مجلد موجود لكنه فارغ.

### `app/Providers`

- `AppServiceProvider.php`: rate limiters.
- `ModuleServiceProvider.php`: اكتشاف الوحدات وتحميل routes/migrations.

### `database/migrations`

مقسمة حسب النطاق:

- `Auth`: users, is_active, refresh_tokens.
- `Shared`: audit_logs, jobs, cache.
- root: `personal_access_tokens` الخاص بـ Sanctum.

### `routes`

- `web.php`: صفحة welcome فقط.
- `api.php`: endpoint افتراضي `/api/user`.
- `console.php`: `inspire` وجدولة prune معلقة.

### `resources`

واجهة Laravel الافتراضية وأصول Vite/Tailwind فقط. لا توجد UI Modules للنظام.

### `tests`

اختبارات Laravel الافتراضية فقط. لا توجد اختبارات Auth أو audit أو module provider.

### `Guide-Line`

دليل داخلي لوحدة Auth. مهم لفهم النية المعمارية، لكنه لا يساوي حالة التنفيذ الفعلية.

## 8. الميزات المنفذة فعليا

### 8.1 اكتشاف الوحدات تلقائيا

المكان:

- `app/Providers/ModuleServiceProvider.php`
- `bootstrap/providers.php`

كيف تعمل:

- `bootstrap/providers.php` يسجل `ModuleServiceProvider`.
- provider يبحث داخل `app/Modules`.
- كل مجلد module يحتوي `routes.php` يتم تحميله تحت `api/{module-name-lowercase}` وبـ middleware `api`.
- provider يحمّل migrations من كل subdirectory تحت `database/migrations`.

أهم العناصر:

- `ModuleServiceProvider::boot()`
- `loadModuleRoutes()`
- `loadModuleMigrations()`

ملاحظات:

- هذا يدعم modules مستقبلية دون تسجيل يدوي للمسارات.
- لا يوجد interface binding حاليا؛ التعليق يوضح أن الخدمات concrete classes مباشرة.

### 8.2 المصادقة باستخدام Access Token وRefresh Token

المكان:

- `app/Modules/Auth/routes.php`
- `app/Modules/Auth/Controllers/AuthController.php`
- `app/Modules/Auth/Services/AuthService.php`
- `app/Modules/Auth/Models/User.php`
- `app/Modules/Auth/Models/RefreshToken.php`
- `database/migrations/Auth/*`
- `database/migrations/2026_05_13_135248_create_personal_access_tokens_table.php`

Endpoints:

- `POST /api/auth/login`
- `POST /api/auth/refresh`
- `POST /api/auth/logout`
- `GET /api/auth/me`

كيف تعمل:

- Login:
  - `LoginRequest` يتحقق من email/password.
  - `AuthService::login` يبحث عن المستخدم عبر email.
  - يفحص كلمة المرور باستخدام `Hash::check`.
  - يرفض الحساب إذا `is_active` false.
  - ينشئ Sanctum access token لمدة 15 دقيقة.
  - ينشئ refresh token عشوائي 64 حرفا، يخزن hash فقط في `refresh_tokens`.
  - يسجل `user_logged_in` في audit logs.

- Refresh:
  - `RefreshTokenRequest` يتحقق من وجود token بطول 64.
  - `AuthService::refreshTokens` يحسب SHA-256 hash.
  - يبحث في `refresh_tokens` مع `lockForUpdate`.
  - يرفض token غير موجود أو منتهي أو revoked.
  - إذا كان token revoked مستخدما مرة ثانية، يعتبر replay ويستدعي `revokeAllUserTokens`.
  - يلغى refresh token القديم وينشئ زوج tokens جديدا.

- Logout:
  - محمي بـ `auth:sanctum`.
  - يحذف جميع Sanctum tokens للمستخدم.
  - يضع جميع refresh tokens النشطة للمستخدم `revoked = true`.
  - يسجل `user_logged_out`.

- Me:
  - محمي بـ `auth:sanctum`.
  - يعيد بيانات المستخدم عبر `AuthResource`.

أهم classes:

- `AuthController`
- `AuthService`
- `User`
- `RefreshToken`
- `LoginRequest`
- `RefreshTokenRequest`
- `AuthResource`
- `AuthenticationException`
- `AccountDisabledException`
- `InvalidRefreshTokenException`

### 8.3 نموذج المستخدم المركزي

المكان:

- `app/Modules/Auth/Models/User.php`
- `config/auth.php`

كيف يعمل:

- `config/auth.php` provider `users` يستخدم `App\Modules\Auth\Models\User::class`.
- النموذج يستخدم:
  - `HasApiTokens`
  - `HasFactory`
  - `Notifiable`
  - `HasAuditLog`
- يحتوي `is_active` مع cast boolean.
- يحتوي علاقة `refreshTokens()`.

ملاحظة عدم اتساق:

- `app/Models/User.php` الافتراضي ما زال موجودا.
- `database/factories/UserFactory.php` و `database/seeders/DatabaseSeeder.php` يستخدمان `App\Models\User`.
- هذا يخلق ازدواجية بين نموذج Laravel الافتراضي ونموذج Auth module.

### 8.4 التدقيق Audit Logging

المكان:

- `app/Shared/Services/AuditLogService.php`
- `app/Shared/Traits/HasAuditLog.php`
- `app/Shared/Middleware/AuditLogMiddleware.php`
- `database/migrations/Shared/2026_05_13_125646_create_audit_logs_table.php`

كيف يعمل:

- `AuditLogService::log` يسجل تغييرات model مع `old_values` و `new_values`.
- `AuditLogService::logAction` يسجل أحداث غير مرتبطة بموديل مثل login/logout.
- `sanitize` يحجب حقولا حساسة مثل `password`, `token`, `refresh_token`, `secret`.
- `HasAuditLog` يربط Eloquent events:
  - created
  - updated
  - deleted
- `AuditLogMiddleware` يسجل request-level audit للمستخدمين الموثقين إذا استُخدم middleware `audit`.

أهم الجداول:

- `audit_logs`:
  - `user_id`
  - `event`
  - `auditable_type`
  - `auditable_id`
  - `old_values`
  - `new_values`
  - `description`
  - `ip_address`
  - `user_agent`
  - `created_at`

ملاحظات:

- `AuditLogMiddleware` مسجل alias في `bootstrap/app.php` باسم `audit`.
- لا تظهر مسارات حالية تستخدم `audit` middleware فعليا.
- `HasAuditLog` مستخدم حاليا على `App\Modules\Auth\Models\User` فقط.

### 8.5 Middleware صلاحيات مستقبلية

المكان:

- `app/Shared/Middleware/CheckPermission.php`
- `bootstrap/app.php`

كيف يعمل:

- مسجل alias باسم `permission`.
- يأخذ permission string مثل `hr.employees.view`.
- إذا لا يوجد user يعيد 401.
- إذا كان المستخدم لديه method باسم `hasPermission` ونتيجته false يعيد 403.
- إذا لا يوجد `hasPermission` يسمح بالمرور.

الحالة:

- هذا ليس RBAC منفذا.
- الكود نفسه يحتوي TODO يقول: استبداله بفحص صلاحيات حقيقي بعد بناء RBAC module.
- لا توجد جداول roles أو permissions.
- لا يوجد `hasPermission` في `User`.
- لا توجد routes تستخدم middleware `permission` حاليا.

### 8.6 Rate limiting للمصادقة

المكان:

- `app/Providers/AppServiceProvider.php`
- `app/Modules/Auth/routes.php`

الحالة الفعلية:

- `auth-login` معرف كـ 1000 request/min per IP.
- `auth-refresh` معرف كـ 1000 request/min per IP.
- `login` route لديه middleware throttle معلق بالتعليق.
- `refresh` route يستخدم `throttle:auth-refresh`.

الحالة المقصودة حسب التعليقات:

- login: 5/min.
- refresh: 10/min.

الاستنتاج:

- rate limiting بدأ تنفيذه لكنه ليس مضبوطا أمنيا كما تصف الوثائق والتعليقات.

### 8.7 تنظيف Refresh Tokens

المكان:

- `app/Console/Commands/PruneExpiredRefreshTokens.php`
- `routes/console.php`

الحالة الفعلية:

- ملف الأمر موجود.
- `routes/console.php` يحتوي جدولة معلقة:

```php
// Schedule::command('auth:prune-refresh-tokens')->daily();
```

- `php artisan list auth` لم يعرض `auth:prune-refresh-tokens`.

سبب محتمل من الكود:

- الملف موجود تحت `app/Console/Commands`.
- namespace داخل الملف هو `App\Modules\Auth\Console`.
- مع autoload الحالي `App\\ => app/`، هذا namespace لا يطابق المسار الفعلي.

الاستنتاج:

- ميزة التنظيف مكتوبة جزئيا لكنها غير عاملة حاليا.

### 8.8 Queues وCache

المكان:

- `database/migrations/Shared/2026_05_13_123023_create_jobs_table.php`
- `database/migrations/Shared/0001_01_01_000001_create_cache_table.php`
- `config/queue.php`
- `config/cache.php`

الحالة:

- جداول queue/cache موجودة.
- `config/queue.php` يستخدم database كافتراضي عبر env fallback.
- لا توجد Jobs مخصصة في `app` حاليا.

### 8.9 Sanctum API Support

المكان:

- `composer.json`
- `config/sanctum.php`
- `database/migrations/2026_05_13_135248_create_personal_access_tokens_table.php`
- `app/Modules/Auth/Services/AuthService.php`

كيف يعمل:

- `AuthService::createAccessToken` يستخدم `$user->createToken`.
- التوكن ينتهي بعد 15 دقيقة.
- `config/sanctum.php` يحتوي `expiration` افتراضي 15.

## 9. ميزات غير موجودة حاليا

هذه العناصر ذُكرت في طلب التحليل، لكن لا يوجد دليل كودي على تنفيذها:

- إدارة المستندات: لا توجد modules أو models أو controllers باسم Documents.
- Workflows: لا توجد workflow engine أو tables أو services.
- Templates: لا توجد template domain سوى Blade welcome الافتراضي.
- Rendering: لا توجد rendering services أو document rendering.
- UI Modules: لا توجد واجهة تطبيق للنظام.
- HR: مجلدات فارغة فقط.
- Finance: مجلد موجود بلا ملفات عاملة.
- RBAC: middleware placeholder فقط، لا جداول ولا models.
- Multi-tenancy: مذكور في التعليقات فقط، لا tenant model ولا tenant_id.
- Inventory: مذكور كاتجاه مستقبلي في تعليق `ModuleServiceProvider` فقط.

## 10. بنية قاعدة البيانات

### Auth schema

`users`:

- `id`
- `name`
- `email` unique
- `email_verified_at`
- `password`
- `remember_token`
- `timestamps`
- `is_active` مضاف في migration منفصل ومفهرس

`refresh_tokens`:

- `id`
- `user_id` FK إلى users مع cascade delete
- `token_hash` unique بطول 64
- `revoked` boolean indexed
- `expires_at` indexed
- `created_at`
- index مركب `user_id, revoked`

`personal_access_tokens`:

- جدول Sanctum القياسي مع `expires_at`.

### Shared schema

`audit_logs`:

- polymorphic audit fields.
- JSON old/new values.
- user/ip/user_agent.
- indexes على event وcreated_at وauditable pair.

`cache`, `cache_locks`:

- جداول Laravel cache.

`jobs`, `job_batches`, `failed_jobs`:

- جداول Laravel queues.

### ملاحظة مهمة حول migrations

تم حذف migrations الافتراضية من root حسب `git status`، ونقلها/استبدالها بمجلدات `Auth` و`Shared`. هذا جزء من اتجاه modular migrations.

## 11. تدفق الطلبات داخل النظام

### التدفق العام

```text
HTTP Request
  -> public/index.php
  -> bootstrap/app.php
  -> Laravel Router
  -> ModuleServiceProvider-loaded route if module route
  -> Middleware: api, auth:sanctum, throttle, audit/permission if applied
  -> FormRequest validation
  -> Controller
  -> Service
  -> Model/DB/Sanctum
  -> Resource
  -> ApiRespond JSON
```

### مثال refresh token

```text
POST /api/auth/refresh
  -> throttle:auth-refresh
  -> RefreshTokenRequest
  -> AuthController::refresh()
  -> AuthService::refreshTokens()
  -> hash plain token
  -> RefreshToken::where(token_hash)->lockForUpdate()
  -> validate revoked/expires/user active
  -> revoke old token
  -> create Sanctum access token
  -> create new refresh token row
  -> AuditLogService::logAction(tokens_refreshed)
  -> JSON response
```

## 12. أين توقف التطوير بالضبط؟

التطوير توقف عند نهاية تأسيس Auth module والبنية المشتركة، قبل إكمال hardening والتسجيل التشغيلي وقبل بناء modules الدومينية التالية.

الأدلة الدقيقة:

1. وحدة Auth مكتملة شكليا لكنها ليست مشددة تشغيليا:
   - `login` throttle معلق في `app/Modules/Auth/routes.php`.
   - limits الفعلية 1000/min في `AppServiceProvider` بدل 5/10 كما في الدليل والتعليقات.

2. تنظيف refresh tokens غير مكتمل:
   - أمر prune موجود في `app/Console/Commands/PruneExpiredRefreshTokens.php`.
   - الجدولة معلقة في `routes/console.php`.
   - الأمر لا يظهر في `php artisan list auth`.
   - namespace لا يطابق المسار الفعلي.

3. RBAC مؤسس كمنفذ middleware فقط:
   - `CheckPermission` يحتوي TODO صريح.
   - لا توجد roles/permissions tables.
   - لا يوجد `hasPermission` على User.

4. الانتقال من Laravel default structure إلى modular structure غير مكتمل:
   - `config/auth.php` يستخدم `App\Modules\Auth\Models\User`.
   - `database/factories/UserFactory.php` و `database/seeders/DatabaseSeeder.php` ما زالا يستخدمان `App\Models\User`.
   - `app/Models/User.php` الافتراضي ما زال موجودا.

5. modules التالية scaffolding فقط:
   - `app/Modules/HR` يحتوي مجلدات فارغة.
   - `app/Modules/Finance` لا يحتوي implementation.
   - التعليقات والدليل يذكران HR/Finance، لكن لا routes ولا migrations ولا classes.

6. الاختبارات لم تلحق بالكود الجديد:
   - tests الافتراضية فقط.
   - لا توجد feature tests لـ login/refresh/logout/me.
   - لا توجد unit tests لـ AuthService أو AuditLogService.

الإجابة المختصرة:

آخر اتجاه هندسي كان يتم العمل عليه هو بناء أساس Modular ERP Backend: Auth module + Shared audit/security layer + module auto-discovery. توقف التطوير قبل إكمال hardening والتشغيل الفعلي للـ auth lifecycle، وقبل تنفيذ RBAC وHR/Finance أو أي workflows/documents.

## 13. الميزات المنفذة جزئيا أو غير المكتملة

### 13.1 RBAC

الحالة: placeholder فقط.  
المطلوب للإكمال:

- جداول `roles`, `permissions`, `role_user`, `permission_role` أو تصميم مكافئ.
- `User::hasPermission`.
- seeding لصلاحيات modules.
- tests لتدفقات 401/403.

### 13.2 Audit request middleware

الحالة: مسجل alias لكنه غير مستخدم في routes الحالية.  
المطلوب:

- تطبيقه على protected API groups أو routes حساسة.
- تحديد هل يسجل كل طلب أم أحداث مختارة فقط.

### 13.3 Refresh token pruning

الحالة: ملف أمر موجود لكنه غير مسجل وغير مجدول.  
المطلوب:

- نقل الملف إلى namespace/path صحيح أو إلى module path فعلي مع تحميل commands.
- تفعيل scheduling في `routes/console.php`.
- اختبار command.

### 13.4 Rate limiting

الحالة: معرف لكنه مخفف جدا وlogin غير مفعل.  
المطلوب:

- تفعيل `throttle:auth-login`.
- ضبط الحدود حسب الدليل أو إعدادات env.

### 13.5 User model migration cleanup

الحالة: ازدواجية بين `App\Models\User` و `App\Modules\Auth\Models\User`.  
المطلوب:

- توحيد factory/seeder/tests على model المعتمد.
- حذف أو تحويل `App\Models\User` إذا لم يعد له دور.

### 13.6 Exception handling

الحالة:

- Auth exceptions موجودة.
- `AuthController::login` يلتقط exceptions يدويا.
- `bootstrap/app.php` لا يملك renderable مخصص لهذه exceptions.
- catch العام في login يعيد تفاصيل exception في 500، وهذا غير مناسب للإنتاج.

المطلوب:

- تعريف exception rendering مركزي.
- إزالة كشف تفاصيل exceptions من controller.

### 13.7 HR/Finance

الحالة: folders بلا implementation.  
المطلوب:

- تحديد أول bounded context حقيقي.
- routes.php لكل module.
- models/migrations/services/controllers/resources/requests.

## 14. الأنماط غير المتسقة والديون التقنية

- ازدواجية `User` model بين `app/Models` و`app/Modules/Auth/Models`.
- `UserFactory` يستخدم model مختلفا عن auth provider.
- `DatabaseSeeder` يستخدم `App\Models\User` بينما `UserSeeder` يستخدم Auth module model.
- namespace أمر console لا يطابق مكان الملف.
- docs والتعليقات تقول rate limits 5/10 بينما التنفيذ 1000/1000.
- route login لا يستخدم throttle رغم وجود تعريف له.
- `AuditLogMiddleware` مسجل لكنه غير مطبق.
- `CheckPermission` يسمح بالمرور إذا لم يوجد `hasPermission`، وهذا مقبول للتأسيس لكنه خطر إذا استُخدم على routes حساسة قبل RBAC.
- اختبارات Auth غير موجودة.
- README ما زال Laravel default ولا يشرح المشروع.
- بعض النصوص في التعليقات ظهرت encoding artifacts في إخراج PowerShell، وهذا يدل أن بعض الملفات قد تكون محفوظة أو معروضة بترميز غير مضبوط في بيئة الطرفية.

## 15. الاتجاه المستقبلي المستنتج

الاتجاه المستقبلي المدعوم بالأدلة هو:

```text
ERP Modular Backend Platform
  -> Auth as identity core
  -> Shared audit/security infrastructure
  -> Future RBAC
  -> Future HR / Finance / Inventory modules
  -> Possible future multi-tenancy
```

ليس هناك دليل كودي حاليا على:

- Document Engine.
- Workflow Platform.
- Rendering Engine.
- Template Engine.

المكونات الناقصة لإكمال الرؤية الحالية:

- RBAC module.
- توحيد User model/factory/seeding.
- command discovery/scheduling strategy.
- تطبيق audit middleware على protected routes.
- first real business module: HR أو Finance.
- API versioning strategy إذا كان النظام سيتوسع.
- test suite حقيقي.
- project README مخصص.
- production exception handling.
- policy حول multi-tenancy قبل إضافة بيانات أعمال.

## 16. Developer Master Guide

### 16.1 نظرة عامة للمطور الجديد

هذا المشروع Laravel 12 API لنظام ERP modular. كل نطاق أعمال يجب أن يعيش داخل `app/Modules/{ModuleName}`. البنية الحالية تبدأ بـ `Auth` كنواة الهوية والمصادقة، و`Shared` كبنية مشتركة للـ audit والردود الموحدة والـ middleware.

قاعدة مهمة: لا تضف منطق أعمال جديدا في `routes` أو controllers مباشرة. اتبع المسار:

```text
Request -> Controller -> Service -> Model/DB -> Resource -> Response
```

### 16.2 خريطة المعمارية المعتمدة

لكل module جديد:

```text
app/Modules/{Module}/
  Controllers/
  Services/
  Requests/
  Resources/
  Models/
  Exceptions/        optional
  Policies/          عند الحاجة
  Observers/         عند الحاجة
  routes.php

database/migrations/{Module}/
  yyyy_mm_dd_hhmmss_create_*.php
```

المسارات ستحمل تلقائيا:

```text
app/Modules/HR/routes.php -> /api/hr/*
app/Modules/Finance/routes.php -> /api/finance/*
```

### 16.3 أين تضيف الميزات الجديدة؟

- Auth-related behavior: داخل `app/Modules/Auth`.
- أي نطاق أعمال جديد: داخل `app/Modules/{Domain}`.
- كود مشترك بين عدة modules: داخل `app/Shared`.
- migrations الخاصة بنطاق: داخل `database/migrations/{Domain}`.
- routes الخاصة بنطاق: داخل `app/Modules/{Domain}/routes.php`.
- validation: داخل `Requests`.
- output transforms: داخل `Resources`.
- business logic: داخل `Services`.
- database persistence: داخل `Models` وmigrations.

لا تضف business module جديدا داخل:

- `routes/api.php` إلا إذا كان route عام framework-level.
- `app/Http/Controllers` إلا إذا كان controller عام خارج نظام modules.
- `app/Models` إذا كان model تابع لنطاق module.

### 16.4 قواعد كتابة Controllers

Controllers يجب أن تكون thin:

- تستقبل FormRequest أو Request.
- تستدعي Service.
- تحول النتيجة إلى Resource/response.
- لا تحتوي queries معقدة.
- لا تحتوي transactions.
- لا تنشئ tokens أو records مباشرة إلا في حالات بسيطة جدا وغير دومينية.

النمط الحالي:

```php
public function action(SomeRequest $request): JsonResponse
{
    $result = $this->service->action($request->validated());

    return $this->success(
        data: new SomeResource($result),
        message: 'Done.'
    );
}
```

### 16.5 قواعد كتابة Services

Services هي مكان business logic:

- ضع transactions داخل service.
- ضع token generation أو workflow decisions داخل service.
- استخدم models داخل service لا داخل controller.
- اجعل الخدمة قابلة للاختبار دون HTTP.
- إذا احتجت audit، استدع `AuditLogService`.
- إذا كانت العملية تكتب أكثر من جدول، استخدم `DB::transaction`.

النمط الحالي في `AuthService` هو المرجع.

### 16.6 قواعد Models

- Model الدوميني يعيش داخل module الذي يملكه.
- العلاقات إلى User يجب أن تشير إلى `App\Modules\Auth\Models\User`.
- استخدم `HasAuditLog` فقط على models التي تحتاج تدقيق field-level.
- لا تستخدم `HasAuditLog` على جداول عالية التردد مثل refresh tokens إلا إذا كان هناك سبب واضح.

### 16.7 قواعد Migrations

- ضع migration في `database/migrations/{Module}`.
- لا تخلط migrations نطاقات متعددة في ملف واحد إلا إذا كان Shared infrastructure.
- استخدم indexes لمسارات الاستعلام المتوقعة.
- أي FK إلى users يجب أن يكون واضحا في migration.
- إذا أضفت tenant لاحقا، يجب تعديله مركزيا وباستراتيجية واضحة لا بإضافات عشوائية.

### 16.8 قواعد API Responses

- استخدم `ApiRespond` في controllers.
- النجاح:

```json
{
  "success": true,
  "message": "Success",
  "data": {}
}
```

- الخطأ:

```json
{
  "success": false,
  "message": "Error",
  "errors": {}
}
```

- لا ترجع raw Eloquent models من controllers. استخدم Resources.

### 16.9 قواعد Workflows المستقبلية

لا توجد workflows منفذة حاليا. إذا أضيفت:

- لا تضع workflow logic داخل controller.
- أنشئ module أو subdomain واضح مثل `Workflow`.
- ضع transitions داخل service مستقل.
- خزّن الحالات في migrations واضحة.
- اربط workflow بالأحداث عبر service أو jobs، لا عبر if statements موزعة.
- أضف audit لكل transition مهم.

### 16.10 قواعد Rendering المستقبلية

لا يوجد rendering engine حاليا. إذا أضيف:

- لا تربط rendering داخل controllers مباشرة.
- أنشئ service مثل `DocumentRenderService` داخل module مناسب.
- افصل template parsing عن output generation.
- لا hardcode بنية المستندات في controller.
- اجعل renderer قابلا للاختبار بمدخلات ومخرجات ثابتة.

### 16.11 خطوات إضافة ميزة جديدة

1. حدد النطاق: `Auth`, `HR`, `Finance`, أو module جديد.
2. أنشئ/حدّث migration داخل `database/migrations/{Module}`.
3. أنشئ Model داخل `app/Modules/{Module}/Models`.
4. أنشئ Request validation داخل `Requests`.
5. أنشئ Service يحتوي business logic.
6. أنشئ Resource لشكل API output.
7. أنشئ Controller thin يستدعي الخدمة.
8. أضف routes داخل `app/Modules/{Module}/routes.php`.
9. أضف middleware مناسب:
   - `auth:sanctum` للمسارات المحمية.
   - `audit` إذا كان مطلوبا.
   - `permission:{module}.{resource}.{action}` بعد اكتمال RBAC.
10. أضف tests:
   - Feature tests للـ endpoint.
   - Unit tests للـ service.
   - Migration/model relationship tests عند الحاجة.
11. شغّل:
   - `php artisan route:list -v`
   - `php artisan test`

### 16.12 خطوات إضافة Module جديد

1. أنشئ `app/Modules/{ModuleName}`.
2. أضف `routes.php`.
3. أضف `Controllers`, `Services`, `Requests`, `Resources`, `Models`.
4. أضف migrations في `database/migrations/{ModuleName}`.
5. تأكد من ظهور routes عبر `php artisan route:list`.
6. لا تعدل `ModuleServiceProvider` إلا إذا احتجت آلية تحميل جديدة.

مثال route:

```php
use App\Modules\HR\Controllers\EmployeeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/employees', [EmployeeController::class, 'index']);
});
```

سيصبح endpoint:

```text
GET /api/hr/employees
```

### 16.13 قواعد توسيع Auth

- لا تضف password reset أو MFA داخل controller مباشرة.
- أضف service methods داخل `AuthService` أو service متخصص مثل `PasswordResetService`.
- استخدم exceptions مخصصة.
- لا تكشف تفاصيل exceptions في responses.
- أضف audit action لكل حدث أمني مهم.
- اختبر replay/expired/revoked token paths.

### 16.14 قواعد Audit

- استخدم `AuditLogService` للأحداث اليدوية.
- استخدم `HasAuditLog` لتغييرات models المهمة.
- لا تسجل secrets أو tokens صريحة.
- إذا أضفت حقلا حساسا جديدا، حدّث قائمة `sanitize`.
- لا تجعل فشل audit يكسر العملية الأساسية إلا إذا كان المتطلب compliance يفرض ذلك.

## 17. قائمة "لا تفعل هذا"

- لا تضع business logic داخل controllers.
- لا تضف routes module داخل `routes/api.php` إذا كان لها نطاق واضح.
- لا تستخدم `App\Models\User` في كود جديد؛ استخدم model المعتمد في Auth module.
- لا تتجاوز `AuthService` لإنشاء tokens من controller.
- لا تخزن refresh tokens plaintext.
- لا ترجع Eloquent models مباشرة في API.
- لا تستخدم `permission` middleware لحماية بيانات حساسة قبل إكمال RBAC، لأنه يسمح بالمرور إذا لا يوجد `hasPermission`.
- لا تضف migrations مسطحة كثيرة في root إذا كانت تخص module.
- لا تكرر audit insert logic خارج `AuditLogService`.
- لا hardcode workflow states أو document structures داخل controllers.
- لا تترك commands في namespace لا يطابق path.
- لا تعتمد على التعليقات كدليل أن الميزة تعمل؛ تحقق عبر artisan/tests.
- لا تضف HR/Finance logic داخل Auth module.
- لا تكشف exception class/message للمستخدم في production كما يحدث حاليا في catch العام داخل `AuthController::login`.

## 18. أولويات الاستكمال المقترحة

1. إصلاح Auth hardening:
   - تفعيل login throttle.
   - ضبط limits إلى قيم واقعية.
   - إضافة exception handling مركزي.

2. إصلاح command scheduling:
   - تصحيح namespace/path لأمر prune.
   - تسجيله والتأكد من ظهوره في `php artisan list auth`.
   - تفعيل schedule.

3. توحيد User model:
   - تحديث `UserFactory` و`DatabaseSeeder`.
   - إزالة أو تحييد `App\Models\User`.

4. إضافة tests حقيقية:
   - login success/failure.
   - disabled account.
   - refresh success/expired/revoked/replay.
   - logout revokes tokens.
   - audit log writes.

5. بناء RBAC قبل حماية business modules:
   - roles/permissions schema.
   - `User::hasPermission`.
   - permission seeder.

6. اختيار أول business module:
   - HR أو Finance.
   - تنفيذه بنفس النمط: Controller -> Service -> Model -> Resource.

## 19. الحالة النهائية للمشروع الآن

> ملاحظة: هذا القسم يعكس لقطة 2026-06-18 وقد تجاوزته الأحداث. للحالة المعتمدة الحالية (2026-06-21) انظر القسم 0 أعلاه: اكتملت RBAC والوحدات Departments/HR وإدارة المستخدمين والإشعارات وكل بنود الـ hardening، والمتبقي المفتوح هو Finance وREADME وقرارات versioning/multi-tenancy.

المشروع صالح كبذرة backend modular ERP، وليس منتجا ERP مكتملا.

المكتمل:

- modular provider.
- Auth module skeleton قوي نسبيا.
- access/refresh token flow.
- audit infrastructure.
- response envelope.
- migrations أساسية.

غير المكتمل:

- hardening النهائي للمصادقة.
- RBAC.
- command scheduling.
- توحيد User model.
- business modules.
- workflows/documents/rendering/templates.
- tests حقيقية.
- README مخصص.

الخلاصة المعمارية:

```text
الحالة الحالية = Laravel Modular API Foundation
الرؤية القريبة = ERP Modular System
آخر نقطة توقف = بعد بناء Auth/Shared foundation وقبل RBAC وbusiness modules
```
