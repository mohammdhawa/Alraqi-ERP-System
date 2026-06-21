<?php

declare(strict_types=1);

namespace App\Modules\Auth\Resources;

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
            'name'              => $this->name,
            'email'             => $this->email,
            'is_active'         => $this->is_active,
            'employee_id'       => $this->employee_id,
            'roles'             => $this->whenLoaded(
                'roles',
                fn () => $this->roles->pluck('name'),
            ),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'created_at'        => $this->created_at?->toIso8601String(),
        ];
    }
}
