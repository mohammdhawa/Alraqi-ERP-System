<?php

declare(strict_types=1);

namespace App\Modules\Me\Resources;

use App\Modules\HR\Resources\EmployeeResource;
use Illuminate\Http\Request;

/**
 * GET /api/me/profile — the employee shape HR reads, rendered through the
 * self rule (`EmployeeResource::forSelf()`), plus the photo.
 *
 * `photo` is `{uploaded_at}` when there is one, `null` when not — never
 * omitted (C31): the app shows initials on null. The file itself is
 * `GET /api/me/photo`, never a URL.
 *
 * Needs `personalPhoto` loaded, beside what EmployeeService prepares.
 */
class MyProfileResource extends EmployeeResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $photo = $this->resource->personalPhoto;

        return [
            ...parent::toArray($request),
            'photo' => $photo === null ? null : ['uploaded_at' => $photo->created_at?->toIso8601String()],
        ];
    }
}
