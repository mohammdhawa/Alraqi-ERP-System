<?php

declare(strict_types=1);

namespace App\Modules\Auth\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AuthResource
 *
 * Transforms authentication response data for the API.
 *
 * WHY API Resources:
 * - Decouples your internal model structure from the API contract.
 * - Adding a field to the User model doesn't accidentally expose it to the API.
 * - Consistent transformation logic across all auth endpoints.
 * - Frontend teams get a stable, documented response shape.
 *
 * Response shape:
 * {
 *   "id": 1,
 *   "name": "John Doe",            // the LINKED EMPLOYEE's name; null if unlinked
 *   "email": "john@example.com",
 *   "is_active": true,
 *   "roles": ["admin"],
 *   "permissions": ["auth.users.view", "..."],
 *   "last_login_at": "2024-01-15T09:00:00Z",
 *   "email_verified_at": "2024-01-15T10:30:00Z",
 *   "created_at": "2024-01-01T00:00:00Z"
 * }
 *
 * NAME: the account has no name of its own — `name` is the linked employee's
 * name (users.employee_id -> employees). Eager-load `employee` on the user
 * before wrapping it (the login and /me flows do) to avoid an N+1.
 *
 * `roles` and `permissions` are only included when the `roles` relation is
 * loaded (the login and /me flows eager-load `roles.permissions`). Exposing the
 * permission set here lets a client build a permission-aware UI from the same
 * payload it already fetches on login / session bootstrap.
 *
 * SECURITY: Never expose password, remember_token, or internal IDs
 * that aren't needed by the frontend.
 */
class AuthResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'name'              => $this->employee?->name,
            'email'             => $this->email,
            'is_active'         => $this->is_active,
            'roles'             => $this->whenLoaded(
                'roles',
                fn () => $this->roles->pluck('name')->values(),
            ),
            'permissions'       => $this->whenLoaded(
                'roles',
                fn () => $this->allPermissionNames(),
            ),
            'last_login_at'     => $this->last_login_at?->toIso8601String(),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'created_at'        => $this->created_at->toIso8601String(),
        ];
    }
}