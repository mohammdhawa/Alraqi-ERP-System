<?php

declare(strict_types=1);

namespace App\Modules\Auth\Resources;

use App\Modules\HR\Resources\EmployeeStub;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UserResource
 *
 * API contract for a user in the admin user-management surface. Includes the
 * user's role names so an admin UI can show what each account can do without a
 * second request. Roles are only included when the relation is loaded
 * (whenLoaded) to avoid N+1.
 *
 * NAME: the account stores no name; `name` is the linked employee's name
 * (employee_id -> employees). The service eager-loads `employee` for the listing
 * so this does not N+1.
 *
 * WHO THE ACCOUNT IS: `employee_id` is the write contract and stays exactly as
 * it is; `employee` is the same person as an object, so a client rendering a
 * user list does not have to resolve the id itself (C31).
 *
 * SECURITY: password and remember_token are never exposed (mirrors AuthResource).
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            // The employee's three name parts, joined by the model's accessor.
            // Batch 2 split `employees.name`; the KEY here stays `name` because
            // it is an account's display name, and a client rendering a user
            // header has no use for the parts.
            'name'              => $this->employee?->full_name,
            'email'             => $this->email,
            'is_active'         => $this->is_active,
            'employee_id'       => $this->employee_id,
            // NEVER OMITTED, unlike `roles` above: null here means the account
            // is not linked to a live employee — either `employee_id` is null
            // (a service account) or it points at a soft-deleted person, who a
            // client cannot fetch either way. An absent key would instead say
            // "you may not see this" (C11, C31), which is not true of a name.
            //
            // Eager-loaded on every path that renders this resource:
            // UserService::paginate/create/update and RoleService::assign/
            // unassign all carry `employee` — the same load `name` above has
            // always depended on.
            'employee'          => EmployeeStub::from($this->employee),
            // De-duplicated since GAP-3: the same role granted over two units
            // is two rows in `roles`, and a name list should say it once.
            'roles'             => $this->whenLoaded(
                'roles',
                fn () => $this->roles->pluck('name')->unique()->values(),
            ),
            // Role + scope, one entry per grant (GAP-3, decision 18) — what the
            // role list above cannot say: how far each role reaches.
            // `department` is set only for a unit grant.
            'grants'            => $this->whenLoaded(
                'grants',
                fn () => $this->grants->map(fn ($grant): array => [
                    'id'          => $grant->id,
                    'role'        => $grant->role?->name,
                    // Null for a stored type ScopeType does not define — a
                    // grant that reaches nothing (see UserRole::scopeType()).
                    'scope_type'  => $grant->scope_type?->value,
                    'scope_label' => $grant->scope_type?->label(),
                    'department'  => $grant->department === null ? null : [
                        'id'   => $grant->department->id,
                        'name' => $grant->department->name,
                    ],
                ])->values(),
            ),
            'last_login_at'     => $this->last_login_at?->toIso8601String(),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'created_at'        => $this->created_at?->toIso8601String(),
        ];
    }
}
