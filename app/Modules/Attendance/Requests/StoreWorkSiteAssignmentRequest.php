<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkSiteAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'work_site_id' => ['required', 'integer', 'exists:work_sites,id'],
            'effective_from' => ['sometimes', 'date'],
        ];
    }
}
