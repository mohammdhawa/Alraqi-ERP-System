<?php

declare(strict_types=1);

namespace App\Modules\Auth\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * RoleResource
 *
 * API contract for a role. Includes the role's permission names so an admin UI
 * can render what each role grants without a second request. Permissions are
 * only included when the relation is loaded (whenLoaded) to avoid N+1.
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'label'       => $this->label,
            'description' => $this->description,
            'is_system'   => $this->is_system,
            'permissions' => $this->whenLoaded(
                'permissions',
                fn () => $this->permissions->pluck('name'),
            ),
            'created_at'  => $this->created_at?->toIso8601String(),
        ];
    }
}
