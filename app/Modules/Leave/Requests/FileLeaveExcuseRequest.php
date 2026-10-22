<?php

declare(strict_types=1);

namespace App\Modules\Leave\Requests;

use App\Modules\Leave\Models\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/leave/excuses — legacy's StoreLeaveExcuseRequest, on employees.
 *
 * The type must be active and offered for excuses; a reason where the type
 * requires one. Files are OPTIONAL here whatever the type — HR records an
 * absence after the fact, often from a paper report it already holds (the
 * owner's answer, 2026-10-18) — and bounded as on a request. `force` lets a
 * deducting excuse go past the balance (legacy).
 */
class FileLeaveExcuseRequest extends FormRequest
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
            'employee_id'   => ['required', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->whereNull('deleted_at')],
            'start_date'    => ['required', 'date'],
            'end_date'      => ['required', 'date', 'after_or_equal:start_date'],
            'reason'        => ['nullable', 'string', 'max:2000'],
            'force'         => ['sometimes', 'boolean'],
            ...AttachmentRules::rules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('leave_type_id')) {
                return;
            }

            $type = LeaveType::find($this->input('leave_type_id'));

            if ($type === null) {
                return;
            }

            if (! $type->isAvailableFor(forExcuse: true)) {
                $validator->errors()->add('leave_type_id', "نوع الإجازة ({$type->label()}) غير متاح للأعذار.");

                return;
            }

            if ($type->requires_reason && ! $this->filled('reason')) {
                $validator->errors()->add('reason', "نوع الإجازة ({$type->label()}) يتطلب ذكر السبب.");
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_id.required'    => 'الموظف مطلوب.',
            'employee_id.integer'     => 'الموظف غير صالح.',
            'employee_id.exists'      => 'الموظف غير موجود.',
            'leave_type_id.required'  => 'نوع الإجازة مطلوب.',
            'leave_type_id.integer'   => 'نوع الإجازة غير صالح.',
            'leave_type_id.exists'    => 'نوع الإجازة غير موجود.',
            'start_date.required'     => 'تاريخ البداية مطلوب.',
            'start_date.date'         => 'تاريخ البداية غير صحيح.',
            'end_date.required'       => 'تاريخ النهاية مطلوب.',
            'end_date.date'           => 'تاريخ النهاية غير صحيح.',
            'end_date.after_or_equal' => 'تاريخ النهاية يجب أن يكون بعد تاريخ البداية أو مساوياً له.',
            'reason.string'           => 'السبب يجب أن يكون نصاً.',
            'reason.max'              => 'السبب طويل جداً.',
            'force.boolean'           => 'قيمة التجاوز غير صالحة.',
        ] + AttachmentRules::messages();
    }

    /**
     * @return array<int, UploadedFile>
     */
    public function uploads(): array
    {
        return array_values(array_filter((array) $this->file('attachments', [])));
    }
}
