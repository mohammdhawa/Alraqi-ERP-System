<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/approvals/{approval}/steps/{step}/assignee — legacy's
 * ReassignLeaveApprovalRequest: the new approver and a reason of 3–500
 * characters. That the person exists and has not left is checked here;
 * whether they may take the step — active, with an active account, not the
 * requester, not already on the chain (§9 Q7 = A) — is the engine's.
 */
class ReassignStepRequest extends FormRequest
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
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'reason'      => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_id.required' => 'الموظف المُسنَد إليه مطلوب.',
            'employee_id.integer'  => 'الموظف المُسنَد إليه غير صالح.',
            'employee_id.exists'   => 'الموظف المُسنَد إليه غير موجود.',
            'reason.required'      => 'سبب إعادة الإسناد مطلوب.',
            'reason.string'        => 'سبب إعادة الإسناد يجب أن يكون نصاً.',
            'reason.min'           => 'سبب إعادة الإسناد قصير جداً.',
            'reason.max'           => 'سبب إعادة الإسناد طويل جداً.',
        ];
    }
}
