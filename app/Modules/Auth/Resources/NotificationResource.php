<?php

declare(strict_types=1);

namespace App\Modules\Auth\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * NotificationResource
 *
 * API contract for a notification. Exposes the polymorphic reference as a
 * flat {type, id} pair so the frontend can deep-link to the subject record.
 *
 * `reference.type` is a STABLE PUBLIC ALIAS — `employee`, `department`, `user`,
 * `role`, `permission` — not a class name. Until the morph map was enforced in
 * AppServiceProvider this field emitted the fully-qualified model class
 * (`App\Modules\HR\Models\Employee`), which broke C12 and which
 * API_DOCUMENTATION.md published as the contract; this docblock claimed the
 * opposite while the code did it. The alias is now decoupled from the
 * namespace, so moving a model between modules — which the legacy merge will
 * do — does not change what clients see.
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'title'      => $this->title,
            'body'       => $this->body,
            'type'       => $this->type,
            'reference'  => $this->reference_type === null ? null : [
                'type' => $this->reference_type,
                'id'   => $this->reference_id,
            ],
            'is_read'    => $this->is_read,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
