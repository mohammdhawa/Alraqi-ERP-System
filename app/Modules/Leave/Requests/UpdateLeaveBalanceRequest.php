<?php

declare(strict_types=1);

namespace App\Modules\Leave\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/leave/balances/{employee}/{year} — legacy's
 * UpdateLeaveBalanceRequest. The year is in the path; an omitted adjustment
 * is zero, as legacy.
 */
class UpdateLeaveBalanceRequest extends FormRequest
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
            'allocated_days'  => ['required', 'integer', 'min:0', 'max:365'],
            'adjustment_days' => ['sometimes', 'integer', 'min:-365', 'max:365'],
            'note'            => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'allocated_days.required' => 'عدد أيام الرصيد مطلوب.',
            'allocated_days.integer'  => 'عدد أيام الرصيد يجب أن يكون رقماً صحيحاً.',
            'allocated_days.min'      => 'عدد أيام الرصيد لا يكون سالباً.',
            'allocated_days.max'      => 'عدد أيام الرصيد لا يتجاوز 365 يوماً.',
            'adjustment_days.integer' => 'أيام التعديل يجب أن تكون رقماً صحيحاً.',
            'adjustment_days.min'     => 'أيام التعديل بين -365 و 365.',
            'adjustment_days.max'     => 'أيام التعديل بين -365 و 365.',
            'note.string'             => 'الملاحظة يجب أن تكون نصاً.',
            'note.max'                => 'الملاحظة طويلة جداً.',
        ];
    }
}
