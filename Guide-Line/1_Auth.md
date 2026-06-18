# ERP Auth Module — Integration & Architecture Guide

## Final File Structure

```
app/
├── Modules/
│   └── Auth/
│       ├── Controllers/
│       │   └── AuthController.php          # Thin controller, delegates to AuthService
│       ├── Services/
│       │   └── AuthService.php             # All auth business logic lives here
│       ├── Requests/
│       │   ├── LoginRequest.php            # Validates login input
│       │   └── RefreshTokenRequest.php     # Validates refresh token input
│       ├── Resources/
│       │   └── AuthResource.php            # Transforms User model for API output
│       ├── Models/
│       │   ├── User.php                    # Core identity model (extends Authenticatable)
│       │   └── RefreshToken.php            # Hashed refresh token storage
│       ├── Exceptions/
│       │   ├── AuthenticationException.php
│       │   ├── InvalidRefreshTokenException.php
│       │   └── AccountDisabledException.php
│       ├── Console/
│       │   └── PruneExpiredRefreshTokens.php  # Scheduled cleanup
│       └── routes.php                      # Module route definitions
│
├── Shared/
│   ├── Services/
│   │   └── AuditLogService.php             # Centralized audit trail writer
│   ├── Middleware/
│   │   ├── AuditLogMiddleware.php          # Request-level audit logging
│   │   └── CheckPermission.php             # RBAC guard (future-ready)
│   └── Traits/
│       ├── HasAuditLog.php                 # Model-level audit via Eloquent events
│       └── ApiRespond.php                  # Standardized JSON response envelope
│
├── Providers/
│   └── ModuleServiceProvider.php           # Auto-discovers and loads all modules
│
database/
└── migrations/
    └── Auth/
        ├── 2024_01_01_000001_add_is_active_to_users_table.php
        ├── 2024_01_01_000002_create_refresh_tokens_table.php
        └── 2024_01_01_000003_create_audit_logs_table.php

config/
└── sanctum.php                             # Sanctum overrides (15-min token expiry)
```

---

## Step-by-Step Integration Into Your Laravel 12 Project

### 1. Install Sanctum

```bash
composer require laravel/sanctum
php artisan install:api
```

This publishes Sanctum's migration (`personal_access_tokens`) and adds
the `api` middleware group with Sanctum's token guard.

### 2. Register ModuleServiceProvider

In `bootstrap/providers.php`, add:

```php
return [
    App\Providers\AppServiceProvider::class,
    App\Providers\ModuleServiceProvider::class,  // <-- Add this
];
```

This provider auto-discovers all modules in `app/Modules/` and loads their
routes and migrations. You never need to manually register a new module.

### 3. Register Middleware Aliases

In `bootstrap/app.php`:

```php
use App\Shared\Middleware\AuditLogMiddleware;
use App\Shared\Middleware\CheckPermission;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'audit'      => AuditLogMiddleware::class,
            'permission' => CheckPermission::class,
        ]);
    })
    ->create();
```

### 4. Configure Rate Limiters

In `app/Providers/AppServiceProvider.php`:

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

public function boot(): void
{
    RateLimiter::for('auth-login', function (Request $request) {
        return Limit::perMinute(5)->by($request->ip());
    });

    RateLimiter::for('auth-refresh', function (Request $request) {
        return Limit::perMinute(10)->by($request->ip());
    });
}
```

### 5. Update User Model Reference

Laravel's default `config/auth.php` points to `App\Models\User`. Update it:

```php
// config/auth.php
'providers' => [
    'users' => [
        'driver' => 'eloquent',
        'model'  => App\Modules\Auth\Models\User::class,  // <-- Point to module
    ],
],
```

### 6. Run Migrations

```bash
php artisan migrate
```

ModuleServiceProvider auto-loads migrations from `database/migrations/Auth/`.

### 7. Schedule Token Cleanup

In `routes/console.php` (Laravel 12):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('auth:prune-refresh-tokens')->daily();
```

### 8. Set Environment Variables

```env
SANCTUM_TOKEN_EXPIRATION=15
SANCTUM_STATEFUL_DOMAINS=localhost,localhost:3000,your-spa-domain.com
```

---

## How Components Connect

```
┌─────────────────────────────────────────────────────────────────┐
│                        HTTP Request                             │
└──────────────────────────────┬──────────────────────────────────┘
                               │
                               ▼
┌──────────────────────────────────────────────────────────────┐
│  Laravel Router (loaded by ModuleServiceProvider)            │
│  Prefix: /api/auth                                          │
│  Middleware: api, throttle:auth-login                        │
└──────────────────────────────┬───────────────────────────────┘
                               │
                               ▼
┌──────────────────────────────────────────────────────────────┐
│  LoginRequest (Form Request)                                 │
│  - Validates email format, password length                   │
│  - Auto-returns 422 on failure                               │
└──────────────────────────────┬───────────────────────────────┘
                               │
                               ▼
┌──────────────────────────────────────────────────────────────┐
│  AuthController::login()                                     │
│  - Receives validated data                                   │
│  - Calls AuthService::login()                                │
│  - Formats response with ApiRespond + AuthResource           │
└──────────────────────────────┬───────────────────────────────┘
                               │
                               ▼
┌──────────────────────────────────────────────────────────────┐
│  AuthService::login()                                        │
│  - Looks up user by email                                    │
│  - Verifies password hash                                    │
│  - Checks is_active flag                                     │
│  - Creates Sanctum access token (15 min)                     │
│  - Creates refresh token (hashed, 30 days)                   │
│  - Logs "user_logged_in" via AuditLogService                 │
│  - Returns token pair + user                                 │
└──────────────────────────────┬───────────────────────────────┘
                               │
              ┌────────────────┼────────────────┐
              ▼                ▼                ▼
     ┌─────────────┐  ┌──────────────┐  ┌─────────────────┐
     │ Sanctum      │  │ RefreshToken │  │ AuditLogService │
     │ (personal_   │  │ Model        │  │ (writes to      │
     │  access_     │  │ (writes to   │  │  audit_logs     │
     │  tokens)     │  │  refresh_    │  │  table)         │
     │              │  │  tokens)     │  │                 │
     └─────────────┘  └──────────────┘  └─────────────────┘
```

---

## Token Flow Diagram

```
CLIENT                              SERVER
  │                                    │
  │  POST /api/auth/login              │
  │  { email, password }               │
  │ ──────────────────────────────────>│
  │                                    │  Validate credentials
  │                                    │  Create access_token (15 min)
  │                                    │  Create refresh_token (30 days, hashed)
  │  { access_token, refresh_token }   │
  │ <──────────────────────────────────│
  │                                    │
  │  GET /api/hr/employees             │
  │  Authorization: Bearer {access}    │
  │ ──────────────────────────────────>│
  │                                    │  Sanctum validates access_token
  │  { employees: [...] }              │
  │ <──────────────────────────────────│
  │                                    │
  │  ... access_token expires (401) ...│
  │                                    │
  │  POST /api/auth/refresh            │
  │  { refresh_token }                 │
  │ ──────────────────────────────────>│
  │                                    │  Validate refresh_token hash
  │                                    │  Revoke old refresh_token
  │                                    │  Issue new access + refresh pair
  │  { new_access, new_refresh }       │
  │ <──────────────────────────────────│
  │                                    │
  │  POST /api/auth/logout             │
  │  Authorization: Bearer {access}    │
  │ ──────────────────────────────────>│
  │                                    │  Delete all Sanctum tokens
  │                                    │  Revoke all refresh tokens
  │  { message: "Logged out" }         │
  │ <──────────────────────────────────│
```

---

## API Endpoints Summary

| Method | Endpoint           | Auth?     | Rate Limit        | Description                    |
|--------|--------------------|-----------|--------------------|--------------------------------|
| POST   | /api/auth/login    | No        | 5/min per IP       | Get access + refresh tokens    |
| POST   | /api/auth/refresh  | No*       | 10/min per IP      | Rotate tokens                  |
| POST   | /api/auth/logout   | Yes       | Default API        | Revoke all tokens              |
| GET    | /api/auth/me       | Yes       | Default API        | Get current user profile       |

*Refresh authenticates via the refresh token itself, not Sanctum.

---

## Frontend Storage Recommendations

### SPA (React, Vue, Angular)

```
Access Token  → JavaScript memory (variable/store)
                NEVER in localStorage (XSS vulnerable)

Refresh Token → httpOnly cookie (set by backend)
                OR in-memory if your SPA and API share the same domain
```

For httpOnly cookie approach, modify `AuthController::login()` to attach
the refresh token as a cookie instead of returning it in the JSON body:

```php
return $this->success($data)
    ->cookie('refresh_token', $result['refresh_token'], 43200, '/', null, true, true);
    //       name            value                       min    path dom  secure httpOnly
```

### Mobile (iOS, Android)

```
Access Token  → Keychain (iOS) / EncryptedSharedPreferences (Android)
Refresh Token → Keychain (iOS) / EncryptedSharedPreferences (Android)
```

Both tokens go in secure OS-level storage. Never in plain SharedPreferences,
UserDefaults, or AsyncStorage.

---

## Exception Handling Strategy

Add this to `bootstrap/app.php` to catch auth exceptions globally:

```php
use App\Modules\Auth\Exceptions\AuthenticationException;

->withExceptions(function (Exceptions $exceptions) {
    $exceptions->renderable(function (AuthenticationException $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], $e->statusCode);
    });
})
```

This ensures that any `AuthenticationException` (or its subclasses) thrown
anywhere in the application returns a properly formatted JSON error.

---

## Adding Future Modules (HR, Finance, etc.)

Every new module follows the same pattern. For example, an HR module:

```
app/Modules/HR/
├── Controllers/
│   └── EmployeeController.php
├── Services/
│   └── EmployeeService.php
├── Requests/
│   └── CreateEmployeeRequest.php
├── Resources/
│   └── EmployeeResource.php
├── Models/
│   └── Employee.php          # has user_id FK, uses HasAuditLog trait
└── routes.php                # auto-loaded by ModuleServiceProvider

database/migrations/HR/
└── 2024_02_01_000001_create_employees_table.php
```

The `routes.php` file gets auto-loaded at `/api/hr/*`. Migrations get
auto-loaded. No registration needed anywhere — just create the folder.

The Employee model would reference the User model:

```php
// App\Modules\HR\Models\Employee
public function user(): BelongsTo
{
    return $this->belongsTo(\App\Modules\Auth\Models\User::class);
}
```

---

## Security Best Practices Implemented

1. **Hashed refresh tokens** — plaintext never stored
2. **Token rotation** — each refresh token is single-use
3. **Replay detection** — reusing a revoked token revokes ALL user tokens
4. **Short access token TTL** — 15 minutes limits exposure
5. **Rate limiting** — prevents brute force on login/refresh
6. **Generic error messages** — login doesn't reveal if email exists
7. **Account disable flag** — admins can lock accounts instantly
8. **Audit trail** — every auth event is logged with IP and user agent
9. **Sensitive field redaction** — passwords never appear in audit logs
10. **DB transactions** — token rotation is atomic (no partial state)
11. **Locked rows during rotation** — prevents race conditions

---

## Performance Considerations

1. **Indexes on every query path** — token_hash (unique), user_id, revoked, expires_at
2. **Composite index** — (user_id, revoked) for bulk revocation
3. **Direct DB inserts for audit** — avoids Eloquent overhead on high-frequency writes
4. **Scheduled pruning** — keeps refresh_tokens table small
5. **No unnecessary eager loading** — relationships loaded only when needed

---

## Multi-Tenant Future Path

When you're ready for multi-tenancy:

1. Add `tenant_id` to: `users`, `refresh_tokens`, `audit_logs`
2. Create a `TenantContext` singleton that resolves the current tenant
   (from subdomain, header, or token claim)
3. Add a global scope to User and RefreshToken models
4. Inject `TenantContext` into `AuditLogService` to include `tenant_id`
5. All existing code continues to work — the scope handles filtering