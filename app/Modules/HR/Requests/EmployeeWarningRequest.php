<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * EmployeeWarningRequest
 *
 * Validates issuing and amending a disciplinary warning.
 *
 * `issued_on` may not be in the future — a warning is a record of something
 * that has happened, and a post-dated one would count toward an employee's
 * sequence before it was served on them.
 *
 * There is no `sequence` field. Whether this is someone's first or third
 * warning is counted from the live rows at read time
 * (EmployeeWarning::sequence()), so it stays right when a warning is withdrawn
 * or a back-dated one is filed. Accepting it as input would let two rows claim
 * to be the second (P1).
 */
class EmployeeWarningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'type'      => [$required, 'string', 'max:255'],
            'number'    => ['sometimes', 'nullable', 'string', 'max:50'],
            'issued_on' => [$required, 'date', 'before_or_equal:today'],

            // An employee, not a user: a warning is signed by a line manager,
            // who may well have no login.
            'issued_by' => ['sometimes', 'nullable', 'integer',
                Rule::exists('employees', 'id')->whereNull('deleted_at')],

            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required'      => 'نوع الإنذار مطلوب.',
            'issued_on.required' => 'تاريخ الإنذار مطلوب.',
            'issued_on.before_or_equal' => 'تاريخ الإنذار لا يمكن أن يكون في المستقبل.',
            'issued_by.exists'   => 'مُصدِر الإنذار المحدد غير موجود أو تم حذفه.',
        ];
    }
}
