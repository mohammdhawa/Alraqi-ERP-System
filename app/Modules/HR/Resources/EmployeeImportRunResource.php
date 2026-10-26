<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use App\Modules\HR\Enums\ImportRunStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An upload import's run on the wire (EMPLOYEE_IMPORT_UPLOAD_DESIGN.md §4.2).
 * Never a path or a hash — where the files wait is the server's business —
 * and never a password: those travel only in the real run's own response.
 * `report` is null once purged at expiry (§4.6).
 */
class EmployeeImportRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'status'        => $this->status->value,
            'status_label'  => $this->status->label(),
            'workbook_name' => $this->workbook_name,
            'checked_at'    => $this->checked_at?->toIso8601String(),
            'executed_at'   => $this->executed_at?->toIso8601String(),
            'expires_at'    => $this->expires_at?->toIso8601String(),
            // By the server's clock: the screen counts down from this, not
            // from expires_at against a browser clock that may be off.
            'seconds_left'  => $this->status === ImportRunStatus::Checked
                ? max(0, (int) ceil(now()->diffInSeconds($this->expires_at, false)))
                : 0,
            'summary'       => $this->summary,
            'report'        => $this->report,
            // Design §12: what may be left out of this import, and what is.
            'importable_rows' => $this->importable_rows ?? [],
            'excluded_rows'   => $this->excluded_rows ?? [],
        ];
    }
}
