<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The rows HR leaves out of an upload import (EMPLOYEE_IMPORT_UPLOAD_DESIGN.md
 * §12.2): sheet row numbers, the whole list each time; `[]` clears it.
 * Whether each row may be excluded is the service's to judge, against the run.
 */
class EmployeeImportExclusionsRequest extends FormRequest
{
    /**
     * Far above any sheet HR sends (a few hundred rows), and low enough that
     * a list cannot cost seconds of validation before it is refused.
     */
    private const MAX_ROWS = 5000;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rows'   => ['present', 'array', 'max:'.self::MAX_ROWS],
            'rows.*' => ['integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'rows.present'   => 'أرسل قائمة السطور المستبعدة، ولو فارغة.',
            'rows.array'     => 'السطور المستبعدة يجب أن تكون قائمة.',
            'rows.max'       => 'لا يمكن استبعاد أكثر من '.self::MAX_ROWS.' سطر.',
            'rows.*.integer' => 'رقم السطر يجب أن يكون عدداً صحيحاً.',
            'rows.*.min'     => 'رقم السطر يجب أن يكون عدداً صحيحاً.',
        ];
    }
}
