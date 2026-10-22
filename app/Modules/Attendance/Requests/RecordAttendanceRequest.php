<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use App\Modules\Attendance\Enums\AttendanceEventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/me/attendance — legacy's RecordAttendanceRequest and its Arabic
 * messages, plus the optional client_uuid (Q12). `type` is the int of
 * AttendanceEventType (1 حضور, 2 انصراف), house style.
 *
 * Shape only. Every business rule is AttendanceService's (C4).
 */
class RecordAttendanceRequest extends FormRequest
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
            'type'        => ['required', 'integer', Rule::in(AttendanceEventType::values())],
            'latitude'    => ['required', 'numeric', 'between:-90,90'],
            'longitude'   => ['required', 'numeric', 'between:-180,180'],
            'selfie'      => ['required', 'image', 'max:10240'],
            'recorded_at' => ['required', 'date'],
            'client_uuid' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required'        => 'نوع الحضور مطلوب.',
            'type.integer'         => 'نوع الحضور يجب أن يكون دخول أو خروج.',
            'type.in'              => 'نوع الحضور يجب أن يكون دخول أو خروج.',
            'latitude.required'    => 'خط العرض مطلوب.',
            'latitude.numeric'     => 'خط العرض يجب أن يكون رقماً.',
            'latitude.between'     => 'خط العرض يجب أن يكون بين -90 و 90.',
            'longitude.required'   => 'خط الطول مطلوب.',
            'longitude.numeric'    => 'خط الطول يجب أن يكون رقماً.',
            'longitude.between'    => 'خط الطول يجب أن يكون بين -180 و 180.',
            'selfie.required'      => 'صورة السيلفي مطلوبة.',
            'selfie.image'         => 'الملف المرفق يجب أن يكون صورة.',
            'selfie.max'           => 'حجم الصورة يجب ألا يتجاوز 10 ميجابايت.',
            'recorded_at.required' => 'وقت التسجيل مطلوب.',
            'recorded_at.date'     => 'وقت التسجيل يجب أن يكون تاريخاً صحيحاً.',
            'client_uuid.uuid'     => 'معرّف السجل على الجهاز غير صالح.',
        ];
    }
}
