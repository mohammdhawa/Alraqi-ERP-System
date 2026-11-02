<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use App\Modules\Attendance\Enums\AttendanceEventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/me/attendance/sync — legacy's SyncAttendanceRequest and its Arabic
 * messages, plus an optional client_uuid per entry (Q12).
 *
 * `selfies` is matched to `records` by index. A missing selfie is NOT a
 * validation error here: legacy reports it per entry as a business rule, so
 * the other entries still save (AttendanceSyncService).
 */
class SyncAttendanceRequest extends FormRequest
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
            'records'               => ['required', 'array', 'min:1'],
            'records.*.type'        => ['required', 'integer', Rule::in(AttendanceEventType::values())],
            'records.*.latitude'    => ['required', 'numeric', 'between:-90,90'],
            'records.*.longitude'   => ['required', 'numeric', 'between:-180,180'],
            'records.*.recorded_at' => ['required', 'date'],
            'records.*.client_uuid' => ['sometimes', 'nullable', 'uuid'],
            'selfies'               => ['sometimes', 'array'],
            'selfies.*'             => ['sometimes', 'nullable', 'image', 'max:10240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'records.required'               => 'قائمة السجلات مطلوبة.',
            'records.array'                  => 'السجلات يجب أن تكون قائمة.',
            'records.min'                    => 'يجب إرسال سجل واحد على الأقل.',
            'records.*.type.required'        => 'نوع الحضور مطلوب لكل سجل.',
            'records.*.type.integer'         => 'نوع الحضور يجب أن يكون دخول أو خروج.',
            'records.*.type.in'              => 'نوع الحضور يجب أن يكون دخول أو خروج.',
            'records.*.latitude.required'    => 'خط العرض مطلوب لكل سجل.',
            'records.*.latitude.numeric'     => 'خط العرض يجب أن يكون رقماً.',
            'records.*.latitude.between'     => 'خط العرض يجب أن يكون بين -90 و 90.',
            'records.*.longitude.required'   => 'خط الطول مطلوب لكل سجل.',
            'records.*.longitude.numeric'    => 'خط الطول يجب أن يكون رقماً.',
            'records.*.longitude.between'    => 'خط الطول يجب أن يكون بين -180 و 180.',
            'records.*.recorded_at.required' => 'وقت التسجيل مطلوب لكل سجل.',
            'records.*.recorded_at.date'     => 'وقت التسجيل يجب أن يكون تاريخاً صحيحاً.',
            'records.*.client_uuid.uuid'     => 'معرّف السجل على الجهاز غير صالح.',
            'selfies.array'                  => 'الصور يجب أن تكون قائمة.',
            'selfies.*.image'                => 'كل ملف في الصور يجب أن يكون صورة.',
            'selfies.*.max'                  => 'حجم كل صورة يجب ألا يتجاوز 10 ميجابايت.',
        ];
    }
}
