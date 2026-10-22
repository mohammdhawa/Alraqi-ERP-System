<?php

declare(strict_types=1);

namespace App\Modules\Leave\Requests;

/**
 * The Arabic messages the two leave-type forms share — one copy, so create
 * and update never word the same refusal differently.
 */
final class LeaveTypeMessages
{
    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            'code.required'       => 'رمز نوع الإجازة مطلوب.',
            'code.max'            => 'رمز نوع الإجازة طويل جداً.',
            'code.regex'          => 'رمز نوع الإجازة يتكون من أحرف إنجليزية صغيرة وأرقام وشرطة سفلية فقط.',
            'code.unique'         => 'رمز نوع الإجازة مستخدم مسبقاً.',
            'name_ar.required'    => 'اسم نوع الإجازة مطلوب.',
            'name_ar.max'         => 'اسم نوع الإجازة طويل جداً.',
            'name_ar.unique'      => 'اسم نوع الإجازة مستخدم مسبقاً.',
            'name_en.max'         => 'الاسم الإنجليزي طويل جداً.',
            'name_en.unique'      => 'الاسم الإنجليزي مستخدم مسبقاً.',
            'deducts_balance.required' => 'يجب تحديد ما إذا كان النوع يُخصم من الرصيد.',
            '*.boolean'           => 'القيمة يجب أن تكون نعم أو لا.',
        ];
    }
}
