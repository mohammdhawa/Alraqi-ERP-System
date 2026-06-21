<?php

declare(strict_types=1);

namespace App\Modules\Auth\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * NotificationResource
 *
 * API contract for a notification. Exposes the polymorphic reference as a
 * flat {type, id} pair so the frontend can deep-link to the subject record
 * without leaking the internal Eloquent morph map.
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
