<?php

declare(strict_types=1);

namespace App\Modules\Documents\Resources;

use App\Modules\Documents\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One صادر number of a register (documents design §8.4). Needs `creator`
 * loaded.
 *
 * @mixin Document
 */
class ExportRegisterRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $deleted = $this->trashed();

        return [
            'export_number' => $this->export_number,
            'reference'     => $this->reference,
            'title'         => $this->title,
            'created_at'    => $this->created_at?->toIso8601String(),
            'creator'       => $this->creator === null ? null : ['id' => $this->creator->id, 'full_name' => $this->creator->full_name],
            'status'        => ['value' => $this->status->value, 'label' => $this->status->label()],
            'deleted'       => $deleted,
            'deleted_label' => $deleted ? 'محذوف' : null,
        ];
    }
}
