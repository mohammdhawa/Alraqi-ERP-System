<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Requests;

use App\Modules\Attendance\Support\AttendanceCalendar;
use Illuminate\Foundation\Http\FormRequest;

/** Ending a span: only its to_date, today or later (work shifts spec §9.2). */
class EndWorkScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.app(AttendanceCalendar::class)->today()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['to_date.after_or_equal' => 'لا يمكن أن ينتهي الجدول قبل اليوم.'];
    }
}
