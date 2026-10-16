<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/attendance/records/{record}/checkout — legacy's
 * CorrectAttendanceCheckoutRequest. Who may correct which row is the route's
 * `can:` (AttendanceRecordPolicy); what makes a correction valid is
 * AttendanceOversightService's.
 */
class CorrectCheckoutRequest extends FormRequest
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
            'checked_out_at' => ['required', 'date'],
            'note'           => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'checked_out_at.required' => 'وقت الانصراف مطلوب.',
            'checked_out_at.date'     => 'وقت الانصراف يجب أن يكون تاريخاً صحيحاً.',
            'note.string'             => 'الملاحظة يجب أن تكون نصاً.',
            'note.max'                => 'الملاحظة طويلة جداً.',
        ];
    }
}
