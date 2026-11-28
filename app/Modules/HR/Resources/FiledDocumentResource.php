<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\HR\Models\EmployeeDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One file on an employee's record, as the filed list shows it (documents to
 * employees, "The filed list"). Rendered from a row loaded with `document`, so
 * it queries nothing. The path on disk is never shown; the file is read through
 * its own endpoint.
 *
 * @mixin EmployeeDocument
 */
class FiledDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $document = $this->document;
        $refused = $document?->status === DocumentStatus::Refused;

        return [
            'id'            => $this->id,
            'type'          => ['value' => $this->type->value, 'label' => $this->type->label()],
            'title'         => $this->title,
            'issued_at'     => $this->issued_at?->format('Y-m-d'),
            'original_name' => $this->original_name,
            'mime_type'     => $this->mime_type,
            'size_bytes'    => $this->size_bytes,
            'document'      => $document === null ? null : [
                'id'             => $document->id,
                'status'         => ['value' => $document->status->value, 'label' => $document->status->label()],
                'refused'        => $refused,
                'refusal_reason' => $refused ? $document->refusal_reason : null,
            ],
            'refused_label' => $refused ? 'مرفوض' : null,
        ];
    }
}
