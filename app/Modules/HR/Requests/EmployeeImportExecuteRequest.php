<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The real import (EMPLOYEE_IMPORT_UPLOAD_DESIGN.md §4.2). `excluded_rows`, when
 * sent, is the list the screen showed: the server refuses to import if it is
 * not the list it holds (§12.2) — a stale screen must not confirm counts for
 * another list. Optional, so a client without exclusions sends nothing.
 */
class EmployeeImportExecuteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'excluded_rows'   => ['sometimes', 'array', 'max:5000'],
            'excluded_rows.*' => ['integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'excluded_rows.array'     => 'السطور المستبعدة يجب أن تكون قائمة.',
            'excluded_rows.max'       => 'لا يمكن استبعاد أكثر من 5000 سطر.',
            'excluded_rows.*.integer' => 'رقم السطر يجب أن يكون عدداً صحيحاً.',
            'excluded_rows.*.min'     => 'رقم السطر يجب أن يكون عدداً صحيحاً.',
        ];
    }
}
