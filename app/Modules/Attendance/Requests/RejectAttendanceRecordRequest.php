<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use App\Modules\Attendance\Enums\RejectionReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/attendance/records/{record}/reject — legacy's
 * RejectAttendanceRecordRequest. `reason` is the int of RejectionReason;
 * «أخرى» carries no canned explanation, so it must bring a note.
 *
 * The route's `can:` runs before this, so an out-of-scope caller is refused
 * before their body is read — legacy's own ordering.
 */
class RejectAttendanceRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'integer', Rule::enum(RejectionReason::class)],
            'note'   => ['nullable', 'string', 'max:500', 'required_if:reason,'.RejectionReason::Other->value],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required'  => 'سبب الرفض مطلوب.',
            'reason.integer'   => 'سبب الرفض غير صالح.',
            'reason.enum'      => 'سبب الرفض غير صالح.',
            'note.required_if' => 'يجب كتابة سبب الرفض عند اختيار "أخرى".',
            'note.string'      => 'الملاحظة يجب أن تكون نصاً.',
            'note.max'         => 'الملاحظة طويلة جداً.',
        ];
    }
}
