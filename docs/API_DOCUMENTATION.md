# Al‑Raqi ERP — API Documentation (Phase 1)

> Reference for frontend implementation. Covers every endpoint shipped in Phase 1:
> **Auth**, **Users**, **Roles & Permissions (RBAC)**, **Notifications**, **Departments**, and **HR / Employees**.

---

## 1. Conventions

### Base URL

```
{APP_URL}/api
```

All routes below are relative to `/api`. Each module is mounted under its own prefix:

| Module        | Prefix               |
| ------------- | -------------------- |
| Auth / RBAC   | `/api/auth`          |
| Notifications | `/api/auth/notifications` |
| Departments   | `/api/departments`   |
| HR            | `/api/hr`            |

### Content type

- Send `Content-Type: application/json` and `Accept: application/json` on every request.
- Always send `Accept: application/json` — it makes Laravel return JSON errors (e.g. 401/422) instead of HTML redirects.

### Authentication

Authentication uses **Laravel Sanctum bearer tokens** with a custom refresh‑token rotation layer.

- Send the access token on every protected request:

  ```
  Authorization: Bearer {access_token}
  ```

- **Access token** lifetime: **15 minutes** (`expires_in: 900` seconds).
- **Refresh token** lifetime: **30 days**, single‑use (rotated on every refresh).
- On `401 Unauthorized`, call `POST /api/auth/refresh` with the refresh token to obtain a new pair, then retry the original request.
- Tokens are per‑user and replace each other: logging in (or refreshing) **deletes the previous access token**, so a user effectively holds one active access token at a time.

**Recommended client storage**

| Token         | SPA (browser)                        | Mobile             |
| ------------- | ------------------------------------ | ------------------ |
| access_token  | in‑memory                            | secure storage     |
| refresh_token | httpOnly cookie (or secure storage)  | secure storage     |

### Standard response envelope

Every endpoint returns the **same envelope**, so you can write a single parser.

**Success**

```json
{
  "success": true,
  "message": "تمت العملية بنجاح.",
  "data": { }
}
```

- `data` is omitted entirely when there is nothing to return (e.g. logout, delete).
- `data` may be an object or an array depending on the endpoint.
- `message` is a human‑readable Arabic string suitable for display (toast/snackbar).

**Error**

```json
{
  "success": false,
  "message": "فشل التحقق من البيانات المُدخلة.",
  "errors": {
    "email": ["يوجد مستخدم مسجّل بهذا البريد الإلكتروني بالفعل."]
  }
}
```

- `errors` is present only on validation failures (HTTP 422) and mirrors Laravel's field‑keyed error bag.
- For non‑validation errors (401/403/404/422 business rules), only `message` is returned.

### HTTP status codes

| Code | Meaning                          | When                                                            |
| ---- | -------------------------------- | --------------------------------------------------------------- |
| 200  | OK                               | Successful read/update/delete/action                            |
| 201  | Created                          | Successful `POST` that creates a resource                       |
| 401  | Unauthorized                     | Missing/expired/invalid access token; bad login credentials     |
| 403  | Forbidden                        | Authenticated but missing the required permission               |
| 404  | Not Found                        | Resource id does not exist (or is not owned, for notifications) |
| 422  | Unprocessable Entity             | Validation failed, or a business rule blocked the action        |
| 429  | Too Many Requests                | Rate limit hit (login/refresh)                                  |
| 500  | Server Error                     | Unhandled error                                                 |

### Validation messages language

Most validation/business messages are returned in **Arabic**. Display `message` directly; use `errors` for per‑field highlighting.

### Pagination ⚠️ (important for list endpoints)

List endpoints (`departments.index`, `hr/employees.index`, `notifications.index`) are paginated server‑side at **15 items per page**.

- Request a page with the query string: `?page=2`.
- `data` is a **flat array of items**, and pagination metadata is returned alongside it under a sibling **`meta`** key:

```json
{
  "success": true,
  "message": "تم جلب الأقسام.",
  "data": [ { "...item..." } ],
  "meta": {
    "current_page": 1,
    "last_page": 4,
    "per_page": 15,
    "total": 52,
    "from": 1,
    "to": 15
  }
}
```

| `meta` field   | Meaning                                          |
| -------------- | ------------------------------------------------ |
| `current_page` | the page returned                                |
| `last_page`    | total number of pages (build pager from this)    |
| `per_page`     | items per page (15)                              |
| `total`        | total item count across all pages                |
| `from` / `to`  | 1‑based index range of items on this page (`null` when the page is empty) |

Roles and Users list endpoints (`/api/auth/roles`, `/api/auth/users`) return the **full collection** (not paginated) and have **no** `meta` key.

---

## 2. RBAC model (permissions)

Protected write/read routes are guarded by **permission middleware**. A user gets permissions through the **roles** assigned to them. The seeded `admin` role holds **every** permission.

Permission string format: `{module}.{resource}.{action}`.

**Phase‑1 permission catalogue**

| Module        | Permissions                                                                                      |
| ------------- | ------------------------------------------------------------------------------------------------ |
| Departments   | `departments.view`, `departments.create`, `departments.update`, `departments.delete`             |
| HR            | `hr.employees.view`, `hr.employees.create`, `hr.employees.update`, `hr.employees.delete`         |
| Auth – Users  | `auth.users.view`, `auth.users.create`, `auth.users.update`, `auth.users.delete`                 |
| Auth – Roles  | `auth.roles.view`, `auth.roles.create`, `auth.roles.update`, `auth.roles.delete`                 |

A missing permission yields:

```json
{ "success": false, "message": "ليس لديك الصلاحيات الكافية لتنفيذ هذا الإجراء." }
```
HTTP `403`.

> **Frontend tip:** call `GET /api/auth/me` after login and cache the user. To build a permission‑aware UI, the frontend currently must map the user's role names to permissions (the `me` payload returns user identity; role→permission mapping is available via the Roles endpoints). Hide/disable actions the user can't perform to avoid 403s.

---

## 3. Auth endpoints

### 3.1 Login

```
POST /api/auth/login
```
**Public.** Rate limited to **5 requests/minute per IP**.

**Request body**

| Field      | Type   | Rules                            |
| ---------- | ------ | -------------------------------- |
| `email`    | string | required, email, max 255         |
| `password` | string | required, 8–128 chars            |

```json
{ "email": "admin@example.com", "password": "secret123" }
```

**200 Response**

```json
{
  "success": true,
  "message": "تم تسجيل الدخول بنجاح.",
  "data": {
    "user": {
      "id": 1,
      "name": "System Administrator",
      "email": "admin@example.com",
      "is_active": true,
      "email_verified_at": null,
      "created_at": "2026-06-21T10:00:00+00:00"
    },
    "access_token": "1|xxxxxxxxxxxxxxxxxxxxxxxx",
    "refresh_token": "64-character-random-string",
    "token_type": "Bearer",
    "expires_in": 900
  }
}
```

**Errors**

- `401` — invalid credentials: `{ "message": "بيانات الاعتماد غير صحيحة." }` (generic on purpose — does not reveal whether the email exists).
- `401` — disabled account (`is_active = false`).
- `422` — validation failure.
- `429` — too many attempts.

---

### 3.2 Refresh tokens

```
POST /api/auth/refresh
```
**Public.** Rate limited to **10 requests/minute per IP**.

**Request body**

| Field           | Type   | Rules                  |
| --------------- | ------ | ---------------------- |
| `refresh_token` | string | required, exactly 64 chars |

**200 Response** (a brand‑new pair; the old refresh token is now revoked)

```json
{
  "success": true,
  "message": "تم تحديث الرموز بنجاح.",
  "data": {
    "access_token": "2|yyyyyyyyyyyyyyyyyyyy",
    "refresh_token": "new-64-character-random-string",
    "token_type": "Bearer",
    "expires_in": 900
  }
}
```

**Errors (all `401`)** — `message` varies:

- `رمز التحديث غير صالح.` — token not found.
- `انتهت صلاحية رمز التحديث.` — expired.
- `الحساب غير مُفعّل.` — account disabled.
- `تم إبطال هذا الرمز. تم إنهاء جميع الجلسات حفاظًا على الأمان.` — **token reuse detected**. The refresh token was already used once; the server treats this as theft and **revokes all of the user's sessions**. The client must force a full re‑login.

> **Single‑use rule:** never retry `/refresh` with the same refresh token. Always store the new refresh token returned by each call.

---

### 3.3 Logout

```
POST /api/auth/logout
```
**Protected** (`Authorization: Bearer`). Revokes **all** access + refresh tokens for the user (logs them out on every device).

**200 Response**

```json
{ "success": true, "message": "تم تسجيل الخروج بنجاح." }
```

---

### 3.4 Current user (me)

```
GET /api/auth/me
```
**Protected.** Returns the authenticated user's profile. Use it to verify a session on app boot.

**200 Response**

```json
{
  "success": true,
  "message": "تم جلب بيانات المستخدم الحالي.",
  "data": {
    "id": 1,
    "name": "System Administrator",
    "email": "admin@example.com",
    "is_active": true,
    "email_verified_at": null,
    "created_at": "2026-06-21T10:00:00+00:00"
  }
}
```

---

## 4. Users (admin)

All routes are **protected** and guarded by `auth.users.*` permissions. Base path `/api/auth/users`.

> Role grants are **not** handled here — use the dedicated [assign/unassign endpoints](#54-assign-role-to-user). This keeps a single source of truth for RBAC changes.

**User object shape**

```json
{
  "id": 5,
  "name": "Jane Doe",
  "email": "jane@example.com",
  "is_active": true,
  "employee_id": null,
  "roles": ["manager"],
  "email_verified_at": null,
  "created_at": "2026-06-21T10:00:00+00:00"
}
```

- `roles` is an array of **role names**, included only when the relation is loaded.

### 4.1 List users

```
GET /api/auth/users          (permission: auth.users.view)
```
Returns the full collection in `data` (array of user objects). Message: `تم جلب المستخدمين.`

### 4.2 Create user

```
POST /api/auth/users         (permission: auth.users.create)
```

| Field       | Type    | Rules                                   |
| ----------- | ------- | --------------------------------------- |
| `name`      | string  | required, max 255                       |
| `email`     | string  | required, email, max 255, **unique**    |
| `password`  | string  | required, min 8                         |
| `is_active` | boolean | optional (defaults to `true`)           |

**201 Response** → `{ data: <user object>, message: "تم إنشاء المستخدم." }`
**422** — `email.unique`: `يوجد مستخدم مسجّل بهذا البريد الإلكتروني بالفعل.`

### 4.3 Update user

```
PUT|PATCH /api/auth/users/{user}   (permission: auth.users.update)
```
Partial update — all fields optional.

| Field       | Type    | Rules                                                |
| ----------- | ------- | ---------------------------------------------------- |
| `name`      | string  | optional, max 255                                    |
| `email`     | string  | optional, email, max 255, unique (ignores this user) |
| `password`  | string  | optional, nullable, min 8 (re‑hashed when sent)      |
| `is_active` | boolean | optional                                             |

**200 Response** → `{ data: <user object>, message: "تم تحديث المستخدم." }`

### 4.4 Delete user

```
DELETE /api/auth/users/{user}      (permission: auth.users.delete)
```
**200 Response** → `{ message: "تم حذف المستخدم." }`
**422** — you cannot delete your own account: `لا يمكنك حذف حسابك الخاص.`

---

## 5. Roles & Permissions (RBAC)

All routes **protected**, base path `/api/auth/roles`, guarded by `auth.roles.*`.

**Role object shape**

```json
{
  "id": 2,
  "name": "manager",
  "description": "Department managers",
  "permissions": ["hr.employees.view", "hr.employees.update"],
  "created_at": "2026-06-21T10:00:00+00:00"
}
```

- `permissions` is an array of **permission names**, included only when loaded.

### 5.1 List roles

```
GET /api/auth/roles          (permission: auth.roles.view)
```
Full collection in `data`. Message: `تم جلب الأدوار.`

### 5.2 Create role

```
POST /api/auth/roles         (permission: auth.roles.create)
```

| Field           | Type     | Rules                                                       |
| --------------- | -------- | ----------------------------------------------------------- |
| `name`          | string   | required, max 255, **unique**                               |
| `description`   | string   | optional, nullable, max 255                                 |
| `permissions`   | string[] | optional; each must be an **existing permission name**      |

```json
{
  "name": "hr_clerk",
  "description": "HR data entry",
  "permissions": ["hr.employees.view", "hr.employees.create"]
}
```

**201 Response** → `{ data: <role object>, message: "تم إنشاء الدور." }`
**422** — `name.unique`: `يوجد دور بهذا الاسم بالفعل.` / unknown permission: `واحدة أو أكثر من الصلاحيات المحددة غير موجودة.`

### 5.3 Update role

```
PUT|PATCH /api/auth/roles/{role}   (permission: auth.roles.update)
```
Partial update.

| Field         | Type     | Rules                                                              |
| ------------- | -------- | ------------------------------------------------------------------ |
| `name`        | string   | optional, max 255, unique (ignores this role)                      |
| `description` | string   | optional, nullable, max 255                                        |
| `permissions` | string[] | optional — **replaces** the role's entire permission set (sync)    |

> Sending `permissions` **overwrites** the existing set. Omit the field to leave permissions unchanged.

**200 Response** → `{ data: <role object>, message: "تم تحديث الدور." }`

### 5.4 Assign role to user

```
POST /api/auth/roles/assign        (permission: auth.roles.update)
```

| Field     | Type    | Rules                          |
| --------- | ------- | ------------------------------ |
| `user_id` | integer | required, must exist in users  |
| `role_id` | integer | required, must exist in roles  |

**200 Response** → `{ message: "تم إسناد الدور 'manager' إلى jane@example.com." }`
**422** — unknown user/role: `المستخدم المحدد غير موجود.` / `الدور المحدد غير موجود.`

### 5.5 Unassign role from user

```
POST /api/auth/roles/unassign      (permission: auth.roles.update)
```
Same body as assign. **200 Response** → `{ message: "تم سحب الدور 'manager' من jane@example.com." }`

### 5.6 Delete role

```
DELETE /api/auth/roles/{role}      (permission: auth.roles.delete)
```
**200 Response** → `{ message: "تم حذف الدور." }`
**422** — the seeded `admin` role is protected: `لا يمكن حذف دور المدير (admin).`

---

## 6. Notifications

Base path `/api/auth/notifications`. **Protected** (auth only — no permission needed; every operation is scoped to the **authenticated user's own** notifications). These endpoints are designed for frequent polling.

**Notification object shape**

```json
{
  "id": 12,
  "title": "New employee added",
  "body": "Jane Doe joined Engineering.",
  "type": "employee_created",
  "reference": { "type": "App\\Modules\\HR\\Models\\Employee", "id": 5 },
  "is_read": false,
  "created_at": "2026-06-21T10:00:00+00:00"
}
```

- `reference` is `null` when the notification isn't linked to a record; otherwise a `{ type, id }` pair you can use to deep‑link.
- `type` / `body` may be `null`.

### 6.1 List my notifications

```
GET /api/auth/notifications
```
Paginated (15/page, newest first). `data` is an array (see [pagination note](#pagination-️-important-for-list-endpoints)). Message: `تم جلب الإشعارات.`

### 6.2 Unread count

```
GET /api/auth/notifications/unread-count
```
**200 Response**

```json
{
  "success": true,
  "message": "تم جلب عدد الإشعارات غير المقروءة.",
  "data": { "unread_count": 3 }
}
```
Ideal for a navbar badge poll.

### 6.3 Mark as read

```
POST /api/auth/notifications/{notification}/read
```
Idempotent — marking an already‑read notification is a no‑op. Returns the updated notification object. Message: `تم وضع علامة "مقروء" على الإشعار.`
**404** — id doesn't exist **or** belongs to another user (the two are indistinguishable by design).

---

## 7. Departments

Base path `/api/departments`. **Protected**, guarded by `departments.*` permissions.

**Department object shape**

```json
{
  "id": 1,
  "name": "Engineering",
  "manager_id": 4,
  "created_at": "2026-06-21T10:00:00+00:00",
  "updated_at": "2026-06-21T10:00:00+00:00"
}
```

| Method & path                          | Permission             | Notes                                   |
| -------------------------------------- | ---------------------- | --------------------------------------- |
| `GET /api/departments`                 | `departments.view`     | Paginated list (15/page). `?page=N`     |
| `POST /api/departments`                | `departments.create`   | 201 on success                          |
| `GET /api/departments/{department}`    | `departments.view`     | Single resource                         |
| `PUT\|PATCH /api/departments/{department}` | `departments.update` | Partial update                          |
| `DELETE /api/departments/{department}` | `departments.delete`   | 200 on success                          |

**Request body (create / update)**

| Field        | Type    | Rules                                                   |
| ------------ | ------- | ------------------------------------------------------- |
| `name`       | string  | required on create, optional on update, max 255         |
| `manager_id` | integer | optional, nullable (an employee id; not FK‑validated yet) |

**Messages:** index `تم جلب الأقسام.` · show `تم جلب القسم.` · create `تم إنشاء القسم.` · update `تم تحديث القسم.` · delete `تم حذف القسم.`
**422** — missing name: `اسم القسم مطلوب.`

---

## 8. HR — Employees

Base path `/api/hr`. **Protected**, guarded by `hr.employees.*` permissions.

**Employee object shape**

```json
{
  "id": 1,
  "name": "Jane Doe",
  "phone": "+966500000000",
  "email": "jane@example.com",
  "address": "Riyadh",
  "department_id": 2,
  "job_title": "Engineer",
  "hire_date": "2026-01-15",
  "salary": "85000.00",
  "status": "active",
  "created_at": "2026-06-21T10:00:00+00:00",
  "updated_at": "2026-06-21T10:00:00+00:00"
}
```

- `salary` is serialized as a **string** (decimal 12,2). `hire_date` is a plain `YYYY-MM-DD` date.
- `status` is one of `active` | `inactive` | `terminated`.

| Method & path                              | Permission              | Notes                               |
| ------------------------------------------ | ----------------------- | ----------------------------------- |
| `GET /api/hr/employees`                    | `hr.employees.view`     | Paginated list (15/page). `?page=N` |
| `POST /api/hr/employees`                   | `hr.employees.create`   | 201 on success                      |
| `GET /api/hr/employees/{employee}`         | `hr.employees.view`     | Single resource                     |
| `PUT\|PATCH /api/hr/employees/{employee}`  | `hr.employees.update`   | Partial update                      |
| `DELETE /api/hr/employees/{employee}`      | `hr.employees.delete`   | 200 on success                      |

**Request body (create / update)**

| Field           | Type    | Rules                                                              |
| --------------- | ------- | ------------------------------------------------------------------ |
| `name`          | string  | required on create, optional on update, max 255                    |
| `phone`         | string  | optional, nullable, max 50                                         |
| `email`         | string  | optional, nullable, email, max 255                                 |
| `address`       | string  | optional, nullable, max 1000                                       |
| `department_id` | integer | optional, nullable, **must exist** in departments                  |
| `job_title`     | string  | optional, nullable, max 255                                        |
| `hire_date`     | date    | optional, nullable (`YYYY-MM-DD`)                                  |
| `salary`        | numeric | optional, ≥ 0                                                     |
| `status`        | enum    | optional, one of `active` / `inactive` / `terminated`              |

**Messages:** index `تم جلب الموظفين.` · show `تم جلب بيانات الموظف.` · create `تم إنشاء الموظف.` · update `تم تحديث بيانات الموظف.` · delete `تم حذف الموظف.`
**422** — missing name: `اسم الموظف مطلوب.` · unknown department: `القسم المحدد غير موجود.` · bad status: `يجب أن تكون الحالة واحدة من: active أو inactive أو terminated.`

---

## 9. Endpoint quick reference

| # | Method      | Path                                         | Auth     | Permission             |
| - | ----------- | -------------------------------------------- | -------- | ---------------------- |
| 1 | POST        | `/api/auth/login`                            | public   | — (5/min)              |
| 2 | POST        | `/api/auth/refresh`                          | public   | — (10/min)             |
| 3 | POST        | `/api/auth/logout`                           | bearer   | —                      |
| 4 | GET         | `/api/auth/me`                               | bearer   | —                      |
| 5 | GET         | `/api/auth/users`                            | bearer   | `auth.users.view`      |
| 6 | POST        | `/api/auth/users`                            | bearer   | `auth.users.create`    |
| 7 | PUT/PATCH   | `/api/auth/users/{user}`                     | bearer   | `auth.users.update`    |
| 8 | DELETE      | `/api/auth/users/{user}`                     | bearer   | `auth.users.delete`    |
| 9 | GET         | `/api/auth/roles`                            | bearer   | `auth.roles.view`      |
| 10| POST        | `/api/auth/roles`                            | bearer   | `auth.roles.create`    |
| 11| PUT/PATCH   | `/api/auth/roles/{role}`                     | bearer   | `auth.roles.update`    |
| 12| DELETE      | `/api/auth/roles/{role}`                     | bearer   | `auth.roles.delete`    |
| 13| POST        | `/api/auth/roles/assign`                     | bearer   | `auth.roles.update`    |
| 14| POST        | `/api/auth/roles/unassign`                   | bearer   | `auth.roles.update`    |
| 15| GET         | `/api/auth/notifications`                    | bearer   | — (owner‑scoped)       |
| 16| GET         | `/api/auth/notifications/unread-count`       | bearer   | — (owner‑scoped)       |
| 17| POST        | `/api/auth/notifications/{notification}/read`| bearer   | — (owner‑scoped)       |
| 18| GET         | `/api/departments`                           | bearer   | `departments.view`     |
| 19| POST        | `/api/departments`                           | bearer   | `departments.create`   |
| 20| GET         | `/api/departments/{department}`              | bearer   | `departments.view`     |
| 21| PUT/PATCH   | `/api/departments/{department}`              | bearer   | `departments.update`   |
| 22| DELETE      | `/api/departments/{department}`              | bearer   | `departments.delete`   |
| 23| GET         | `/api/hr/employees`                          | bearer   | `hr.employees.view`    |
| 24| POST        | `/api/hr/employees`                          | bearer   | `hr.employees.create`  |
| 25| GET         | `/api/hr/employees/{employee}`               | bearer   | `hr.employees.view`    |
| 26| PUT/PATCH   | `/api/hr/employees/{employee}`               | bearer   | `hr.employees.update`  |
| 27| DELETE      | `/api/hr/employees/{employee}`               | bearer   | `hr.employees.delete`  |

---

## 10. Frontend integration checklist

1. **HTTP client:** set base URL `{APP_URL}/api`, default headers `Accept: application/json` + `Content-Type: application/json`.
2. **Auth interceptor:** attach `Authorization: Bearer {access_token}` on every request.
3. **Refresh flow:** on `401`, call `/api/auth/refresh` once with the stored refresh token, swap in the new pair, and retry the failed request. If refresh fails (any `401`), clear tokens and route to login.
4. **Single‑use refresh:** persist the new `refresh_token` returned by **both** login and refresh; never reuse an old one.
5. **Session bootstrap:** on app start, if a token exists, call `/api/auth/me` to validate it and load the user.
6. **Error handling:** read `success`. On `false`, show `message`; if `errors` exists, map field errors to inputs.
7. **Permission‑aware UI:** hide/disable actions the user's roles don't permit to avoid `403`s.
8. **Notification badge:** poll `/api/auth/notifications/unread-count` on an interval.
9. **Lists:** paginated endpoints return items in `data` plus a `meta` block (`current_page`, `last_page`, `total`, …); use `?page=N` to page and `meta.last_page` to build the pager.
