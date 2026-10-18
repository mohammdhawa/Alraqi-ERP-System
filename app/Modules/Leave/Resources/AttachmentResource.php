<?php

declare(strict_types=1);

namespace App\Modules\Leave\Resources;

use App\Shared\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A file on a leave, as the request screen lists it — never its path or a
 * URL: the file is streamed by its own endpoint to who may read the leave
 * (LEAVE_BUILD.md §5).
 *
 * @mixin Attachment
 */
class AttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->original_name,
            'mime_type'  => $this->mime_type,
            'size'       => $this->size,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
