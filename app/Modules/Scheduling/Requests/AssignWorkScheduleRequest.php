<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Assigning a schedule from a date — one person (POST …/work-schedules) or
 * many (BulkAssignRequest adds who). The rules on the span itself — today or
 * later, no overlap, a cycle's start date — are the model's, and come back as
 * a 422 with their message.
 */
class AssignWorkScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'work_schedule_id' => ['required', 'integer', Rule::exists('work_schedules', 'id')->whereNull('deleted_at')],
            'from_date'        => ['required', 'date_format:Y-m-d'],
            'cycle_start_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'work_schedule_id' => 'الجدول',
            'from_date'        => 'تاريخ البدء',
            'cycle_start_date' => 'تاريخ بداية الدورة',
            'employee_ids'     => 'الموظفون',
            'department_id'    => 'الوحدة',
        ];
    }
}
