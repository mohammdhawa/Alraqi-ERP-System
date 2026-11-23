<?php

declare(strict_types=1);

namespace App\Modules\Documents\Requests;

use App\Modules\Documents\Pdf\PdfIntake;
use App\Modules\Documents\Support\CreateDocumentInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * POST /api/documents — a document sent for signing (documents design §7.1).
 *
 * The file's TYPE is not a rule here: the intake reads the content and
 * refuses anything but a PDF or a photo, in its own words. The approvers'
 * standing (managers, not the creator, not المدير العام) is the service's.
 */
class CreateDocumentRequest extends FormRequest
{
    public const MAX_ATTACHMENTS = 10;

    public const MAX_APPROVERS = 10;

    private const FILE_MESSAGE = 'يُرجى رفع الملف بصيغة PDF أو صورة لا يتجاوز حجمه 20 ميغابايت.';

    private const ATTACHMENT_MESSAGE = 'المرفق يجب أن يكون PDF أو صورة أو ملف Word أو Excel لا يتجاوز 20 ميغابايت.';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'         => ['required', 'string', 'max:255'],
            'description'   => ['nullable', 'string', 'max:2000'],
            'import_number' => ['nullable', 'integer', 'min:1', 'max:999999999'],
            'file'          => ['required', 'file', 'max:'.PdfIntake::MAX_KB],
            'approvers'     => ['required', 'array', 'min:1', 'max:'.self::MAX_APPROVERS],
            'approvers.*'   => ['integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'attachments'   => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_ATTACHMENTS],
            'attachments.*' => ['file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx', 'max:'.PdfIntake::MAX_KB],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required'         => 'عنوان المستند مطلوب.',
            'title.string'           => 'عنوان المستند غير صالح.',
            'title.max'              => 'عنوان المستند لا يزيد على 255 حرفاً.',
            'description.string'     => 'وصف المستند غير صالح.',
            'description.max'        => 'وصف المستند لا يزيد على 2000 حرف.',
            'import_number.integer'  => 'رقم الوارد يجب أن يكون رقماً صحيحاً.',
            'import_number.min'      => 'رقم الوارد يجب أن يكون بين 1 و 999999999.',
            'import_number.max'      => 'رقم الوارد يجب أن يكون بين 1 و 999999999.',
            'file.required'          => 'الملف مطلوب.',
            'file.file'              => self::FILE_MESSAGE,
            'file.max'               => self::FILE_MESSAGE,
            'file.uploaded'          => self::FILE_MESSAGE,
            'approvers.required'     => 'اختر معتمداً واحداً على الأقل.',
            'approvers.array'        => 'اختر معتمداً واحداً على الأقل.',
            'approvers.min'          => 'اختر معتمداً واحداً على الأقل.',
            'approvers.max'          => 'لا يزيد عدد المعتمدين على 10.',
            'approvers.*.integer'    => 'المعتمد المحدد غير صالح.',
            'approvers.*.exists'     => 'المعتمد المحدد غير موجود.',
            'attachments.array'      => 'المرفقات غير صالحة.',
            'attachments.max'        => 'لا تزيد المرفقات على 10.',
            'attachments.*.file'     => self::ATTACHMENT_MESSAGE,
            'attachments.*.mimes'    => self::ATTACHMENT_MESSAGE,
            'attachments.*.max'      => self::ATTACHMENT_MESSAGE,
            'attachments.*.uploaded' => self::ATTACHMENT_MESSAGE,
        ];
    }

    public function toInput(): CreateDocumentInput
    {
        $attachments = $this->file('attachments');

        return new CreateDocumentInput(
            title: trim((string) $this->input('title')),
            description: $this->filled('description') ? trim((string) $this->input('description')) : null,
            importNumber: $this->filled('import_number') ? (int) $this->input('import_number') : null,
            file: $this->file('file'),
            approverIds: array_map('intval', array_values((array) $this->input('approvers'))),
            attachments: array_values(array_filter((array) $attachments, static fn ($file): bool => $file instanceof UploadedFile)),
        );
    }
}
