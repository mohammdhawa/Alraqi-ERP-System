<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Resources;

use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Resources\EmployeeStub;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One attendance row — for the employee's app and the oversight list alike.
 *
 * NO SELFIE PATH, NO URL (Q11). The file is private; `has_selfie` says whether
 * there is one, and the selfie endpoints stream it to who may see it.
 * `has_selfie` is false once the file has been purged after three months —
 * the row stays.
 *
 * The person and the correcting / refusing accounts are embedded beside their
 * ids (C31). Every path that hands rows here eager-loads
 * AttendanceRecordQuery::RENDER_RELATIONS, so nothing lazy-loads.
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
            'employee'    => $this->whenLoaded('employee', fn (): ?array => EmployeeStub::from($this->employee)),

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
            'corrected_by'    => $this->whenLoaded('correctedBy', fn (): ?array => $this->actor($this->correctedBy)),
            'correction_note' => $this->correction_note,

            'is_rejected'            => $this->isRejected(),
            'rejected_at'            => $this->rejected_at?->toIso8601String(),
            'rejected_by'            => $this->whenLoaded('rejectedBy', fn (): ?array => $this->actor($this->rejectedBy)),
            'rejection_reason'       => $this->rejection_reason?->value,
            'rejection_reason_label' => $this->rejection_reason?->label(),
            'rejection_note'         => $this->rejection_note,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * The account that corrected or refused the row — legacy's
     * `rejected_by_name`, as C31's id-plus-object. An account is not always a
     * person (the technical department), so the name falls back to its email.
     *
     * @return array{id: int, name: ?string}|null
     */
    private function actor(?User $user): ?array
    {
        return $user === null ? null : [
            'id'   => (int) $user->getKey(),
            'name' => $user->employee?->full_name ?? $user->email,
        ];
    }
}
