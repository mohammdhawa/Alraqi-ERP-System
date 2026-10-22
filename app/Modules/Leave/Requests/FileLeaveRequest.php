<?php

declare(strict_types=1);

namespace App\Modules\Leave\Requests;

use App\Modules\Leave\Models\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/me/leave-requests — legacy's StoreLeaveRequest, less the approver
 * picker: the chain is resolved, never chosen (Q1), so `approver_ids` and
 * `manager_id` are not read.
 *
 * The type must be active and offered on the employee's own form — an
 * official mission is HR's to record. A reason where the type requires one;
 * at least one file where it requires an attachment (sick leave, Q3); never
 * more than five, each a PDF or an image of at most 10 MB (the owner's
 * answer, 2026-10-18). The rules about days, the balance and overlap are the
 * service's.
 */
class FileLeaveRequest extends FormRequest
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
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->whereNull('deleted_at')],
            'start_date'    => ['required', 'date'],
            'end_date'      => ['required', 'date', 'after_or_equal:start_date'],
            'reason'        => ['nullable', 'string', 'max:2000'],
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

            if (! $type->isAvailableFor(forExcuse: false)) {
                $validator->errors()->add('leave_type_id', "نوع الإجازة ({$type->label()}) غير متاح لطلبات الموظفين.");

                return;
            }

            if ($type->requires_reason && ! $this->filled('reason')) {
                $validator->errors()->add('reason', "نوع الإجازة ({$type->label()}) يتطلب ذكر السبب.");
            }

            if ($type->requires_attachment && $this->uploads() === []) {
                $validator->errors()->add('attachments', "نوع الإجازة ({$type->label()}) يتطلب إرفاق مستند.");
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'leave_type_id.required' => 'نوع الإجازة مطلوب.',
            'leave_type_id.integer'  => 'نوع الإجازة غير صالح.',
            'leave_type_id.exists'   => 'نوع الإجازة غير موجود.',
            'start_date.required'    => 'تاريخ بداية الإجازة مطلوب.',
            'start_date.date'        => 'تاريخ بداية الإجازة غير صحيح.',
            'end_date.required'      => 'تاريخ نهاية الإجازة مطلوب.',
            'end_date.date'          => 'تاريخ نهاية الإجازة غير صحيح.',
            'end_date.after_or_equal' => 'تاريخ نهاية الإجازة يجب أن يكون بعد تاريخ بدايتها أو مساوياً له.',
            'reason.string'          => 'السبب يجب أن يكون نصاً.',
            'reason.max'             => 'السبب طويل جداً.',
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
