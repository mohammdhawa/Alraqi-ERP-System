<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Resources;

use App\Modules\Attendance\Models\AttendanceRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One attendance row, as the employee's app reads it.
 *
 * NO SELFIE PATH, NO URL (Q11). The file is private; `has_selfie` says whether
 * there is one, and slice 2's authorised endpoint streams it. `has_selfie` is
 * false once the file has been purged after three months — the row stays.
 *
 * Who corrected or refused a row is added with slice 2, beside the oversight
 * endpoints that load those accounts.
 *
 * @mixin AttendanceRecord
 */
class AttendanceRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'employee_id' => $this->employee_id,

            'type'         => $this->type->value,
            'type_label'   => $this->type->label(),
            'status'       => $this->status?->value,
            'status_label' => $this->status?->label(),
            'source'       => $this->source->value,
            'source_label' => $this->source->label(),

            'recorded_at'          => $this->recorded_at->toIso8601String(),
            'work_hours'           => $this->work_hours,
            'work_hours_formatted' => $this->workHoursFormatted(),
            'latitude'             => $this->latitude,
            'longitude'            => $this->longitude,
            'has_selfie'           => $this->selfie_path !== null,
            'client_uuid'          => $this->client_uuid,

            'is_corrected'    => $this->corrected_at !== null,
            'corrected_at'    => $this->corrected_at?->toIso8601String(),
            'correction_note' => $this->correction_note,

            'is_rejected'            => $this->isRejected(),
            'rejected_at'            => $this->rejected_at?->toIso8601String(),
            'rejection_reason'       => $this->rejection_reason?->value,
            'rejection_reason_label' => $this->rejection_reason?->label(),
            'rejection_note'         => $this->rejection_note,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
