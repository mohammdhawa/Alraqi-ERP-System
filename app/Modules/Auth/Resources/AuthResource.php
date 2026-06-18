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
 *   "name": "John Doe",
 *   "email": "john@example.com",
 *   "is_active": true,
 *   "email_verified_at": "2024-01-15T10:30:00Z",
 *   "created_at": "2024-01-01T00:00:00Z"
 * }
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
            'name'              => $this->name,
            'email'             => $this->email,
            'is_active'         => $this->is_active,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'created_at'        => $this->created_at->toIso8601String(),
        ];
    }
}