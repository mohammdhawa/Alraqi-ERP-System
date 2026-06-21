# Alraqi ERP — Postman API Documentation

A complete Postman collection for testing every API in the Alraqi ERP System.

## Files

| File | Purpose |
| --- | --- |
| `Alraqi-ERP.postman_collection.json` | The full collection — all endpoints, grouped by module, with descriptions, sample bodies, and token-capture scripts. |
| `Alraqi-ERP-Local.postman_environment.json` | A ready-to-use environment for local development (`http://localhost:8000`). |

## How to import

1. Open Postman → **Import** → drag both JSON files in.
2. Top-right environment selector → choose **Alraqi ERP - Local**.
3. Make sure your API is running (e.g. `php artisan serve`, which serves on `http://localhost:8000`).

> Both the collection and the environment define the same variables. If you don't select an environment, the collection's own variable defaults are used — so it works either way.

## Quick start

1. Run **Auth → Login**. Its test script captures `accessToken` and `refreshToken` automatically.
2. Every other request inherits the collection's Bearer auth (`{{accessToken}}`), so it's authenticated with no extra steps.
3. When the access token expires (15 min), run **Auth → Refresh Token** to rotate it.

Default seeded admin (from `database/seeders/UserSeeder.php`): `ragab5434@gmail.com` / `MoH.1822`. Run `php artisan migrate --seed` if you haven't seeded yet.

## Endpoint map

### Auth — `/api/auth`
| Method | Path | Auth | Notes |
| --- | --- | --- | --- |
| POST | `/login` | public | 5 req/min/IP. Returns token pair. |
| POST | `/refresh` | public | 10 req/min/IP. Rotates tokens (old refresh token revoked). |
| POST | `/logout` | bearer | Revokes all tokens. |
| GET | `/me` | bearer | Current user profile. |

### RBAC / Roles — `/api/auth/roles`
| Method | Path | Permission |
| --- | --- | --- |
| GET | `/roles` | `auth.roles.view` |
| POST | `/roles` | `auth.roles.create` |
| POST | `/roles/assign` | `auth.roles.update` |
| POST | `/roles/unassign` | `auth.roles.update` |
| PUT/PATCH | `/roles/{role}` | `auth.roles.update` |
| DELETE | `/roles/{role}` | `auth.roles.delete` (admin role is protected → 422) |

### Notifications — `/api/auth/notifications`
| Method | Path | Notes |
| --- | --- | --- |
| GET | `/notifications` | Own notifications only. |
| GET | `/notifications/unread-count` | `data.unread_count`. |
| POST | `/notifications/{notification}/read` | Mark one as read (ownership-scoped). |

### Departments — `/api/departments` (perm: `departments.view`)
| Method | Path |
| --- | --- |
| GET | `/departments` (paginated, `?page=`) |
| POST | `/departments` |
| GET | `/departments/{id}` |
| PUT/PATCH | `/departments/{id}` |
| DELETE | `/departments/{id}` |

### HR / Employees — `/api/hr/employees` (perm: `hr.employees.view`)
| Method | Path |
| --- | --- |
| GET | `/hr/employees` (paginated, `?page=`) |
| POST | `/hr/employees` |
| GET | `/hr/employees/{id}` |
| PUT/PATCH | `/hr/employees/{id}` |
| DELETE | `/hr/employees/{id}` |

## Response envelope

Every response is wrapped consistently:

```jsonc
// success
{ "success": true,  "message": "...", "data": { /* ... */ } }
// error
{ "success": false, "message": "...", "errors": { /* field: [msgs] */ } }
```

Common status codes: `200` OK, `201` Created, `401` Unauthenticated, `403` Forbidden (missing permission), `404` Not found, `422` Validation error, `429` Rate limited.

## Variables

| Variable | Meaning |
| --- | --- |
| `baseUrl` | API base URL (default `http://localhost:8000`). |
| `email` / `password` | Login credentials. |
| `accessToken` / `refreshToken` | Set automatically by the Login/Refresh test scripts. |
| `roleId`, `userId`, `departmentId`, `employeeId`, `notificationId` | Resource ids used in path-param requests; the create requests auto-store the new ids. |
