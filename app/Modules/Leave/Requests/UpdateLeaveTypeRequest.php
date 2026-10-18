<?php

declare(strict_types=1);

namespace App\Modules\Leave\Requests;

use App\Modules\Leave\Models\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/leave/types/{leaveType} — every field optional; a type may keep
 * its own code and names. Changing `deducts_balance` never rewrites a filed
 * request: the request snapshotted it.
 */
class UpdateLeaveTypeRequest extends FormRequest
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
        /** @var LeaveType $type */
        $type = $this->route('leaveType');

        return [
            'code'                => ['sometimes', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', Rule::unique('leave_types', 'code')->ignore($type->id)],
            'name_ar'             => ['sometimes', 'string', 'max:100', Rule::unique('leave_types', 'name_ar')->ignore($type->id)],
            'name_en'             => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('leave_types', 'name_en')->ignore($type->id)],
            'deducts_balance'     => ['sometimes', 'boolean'],
            'for_requests'        => ['sometimes', 'boolean'],
            'for_excuses'         => ['sometimes', 'boolean'],
            'requires_reason'     => ['sometimes', 'boolean'],
            'requires_attachment' => ['sometimes', 'boolean'],
            'is_active'           => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return LeaveTypeMessages::all();
    }
}
