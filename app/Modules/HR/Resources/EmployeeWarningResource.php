<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * EmployeeWarningResource
 *
 * The API contract for one disciplinary warning.
 *
 * `sequence` is COMPUTED per row, not stored — it is what the workbook's
 * "إنذار 1 / 2 / 3" columns were trying to be. Because it is counted from the
 * live rows, withdrawing warning #2 of 3 renumbers the third to #2 on the next
 * read, which a stored counter would not.
 *
 * ⚠️ ITS COST IS ONE QUERY PER ROW. That is an N+1 by construction, accepted
 * here because a warnings list is scoped to ONE employee and paginated, so the
 * page holds at most 100 rows and in practice fewer than five — most staff have
 * none. It would NOT be acceptable on a company-wide listing, which is one more
 * reason no such endpoint exists. If one is ever added, `sequence` must be
 * resolved with a window function in the service, not here.
 */
class EmployeeWarningResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'employee_id' => $this->employee_id,
            'type'        => $this->type,
            'number'      => $this->number,
            'issued_on'   => $this->issued_on?->toDateString(),
            'sequence'    => $this->sequence(),

            'issued_by' => $this->issued_by,
            // Built from the shared stub so a person embedded here has the same
            // keys as one embedded anywhere else (C31) — this used to carry
            // {id, full_name} and now also carries `employee_number`, the field
            // that tells two staff with the same الاسم الثلاثي apart. Always
            // present, null when nobody is recorded; every path that renders
            // this resource loads `issuer`.
            'issuer'    => EmployeeStub::from($this->issuer),

            'notes'      => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
