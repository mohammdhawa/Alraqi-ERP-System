<?php

declare(strict_types=1);

namespace App\Modules\Leave\Requests;

/**
 * The rules for the files a leave or an excuse carries — one copy, so the
 * employee's form and HR's take the same files (`config('leave.attachments')`,
 * the owner's answer of 2026-10-18).
 */
final class AttachmentRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $limits = config('leave.attachments');

        return [
            'attachments'   => ['sometimes', 'array', 'max:'.$limits['max_files']],
            'attachments.*' => ['file', 'mimes:'.implode(',', $limits['mimes']), 'max:'.$limits['max_kb']],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        $limits = config('leave.attachments');

        return [
            'attachments.array'   => 'المرفقات غير صالحة.',
            'attachments.max'     => "لا يمكن إرفاق أكثر من {$limits['max_files']} ملفات.",
            'attachments.*.file'  => 'المرفق يجب أن يكون ملفاً.',
            'attachments.*.mimes' => 'المرفق يجب أن يكون ملف PDF أو صورة (JPG أو PNG).',
            'attachments.*.max'   => 'حجم المرفق يجب ألا يتجاوز 10 ميغابايت.',
        ];
    }
}
