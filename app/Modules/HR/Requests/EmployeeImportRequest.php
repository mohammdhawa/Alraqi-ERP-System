<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The upload import's two files (EMPLOYEE_IMPORT_UPLOAD_DESIGN.md §8).
 *
 * `extensions:xlsx`, not `mimes:xlsx`: an xlsx is a zip, and which MIME type
 * a server's libmagic reports for one varies — `mimes` would refuse a sound
 * workbook on some servers. Whether the file really is one is the reader's to
 * say, and it refuses an unreadable one with an Arabic 422.
 */
class EmployeeImportRequest extends FormRequest
{
    /** 20 MB each; today's files are 0.7 MB and 0.3 MB. */
    private const MAX_KB = 20 * 1024;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'workbook' => ['required', 'file', 'extensions:xlsx', 'max:'.self::MAX_KB],
            // HR merges the صرح columns into their own file (project owner,
            // 2026-10-24): an old client still sending it is told, not ignored.
            'sarh'     => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'workbook.required'   => 'اختر ملف الموارد (Excel).',
            'sarh.prohibited'     => 'ملف صرح لم يعد مستخدماً — ادمج بياناته في ملف الموارد البشرية.',
            '*.extensions'        => ':attribute يجب أن يكون ملف Excel بصيغة xlsx.',
            '*.max'               => ':attribute أكبر من 20 ميغابايت.',
            '*.file'              => ':attribute لم يُرفع بشكل صحيح.',
        ];
    }

    public function attributes(): array
    {
        return [
            'workbook' => 'ملف الموارد',
        ];
    }
}
