<?php

declare(strict_types=1);

namespace App\Modules\Documents\Requests;

use App\Modules\Documents\Pdf\PdfIntake;
use App\Modules\Documents\Requests\Concerns\CarriesADocument;
use App\Modules\Documents\Support\CreateDocumentInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * POST /api/documents — a document sent for signing (documents design §7.1),
 * uploaded as a file or made from a template (§8.3), never both.
 *
 * The title, the description and the original are `CarriesADocument`'s. The
 * approvers' standing (managers, not the creator, not المدير العام) is the
 * service's, and so are the template's (live, active, between managers), its
 * values and the صادر number's.
 */
class CreateDocumentRequest extends FormRequest
{
    use CarriesADocument;

    public const MAX_ATTACHMENTS = 10;

    public const MAX_APPROVERS = 10;

    private const ATTACHMENT_MESSAGE = 'المرفق يجب أن يكون PDF أو صورة أو ملف Word أو Excel لا يتجاوز 20 ميغابايت.';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->headingRules() + [
            'import_number' => ['nullable', 'integer', 'min:1', 'max:999999999'],
        ] + $this->originalRules() + [
            'approvers'     => ['required', 'array', 'min:1', 'max:'.self::MAX_APPROVERS],
            'approvers.*'   => ['integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'attachments'   => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_ATTACHMENTS],
            'attachments.*' => ['file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx', 'max:'.PdfIntake::MAX_KB],
        ];
    }

    public function messages(): array
    {
        return $this->documentMessages() + [
            'import_number.integer'      => 'رقم الوارد يجب أن يكون رقماً صحيحاً.',
            'import_number.min'          => 'رقم الوارد يجب أن يكون بين 1 و 999999999.',
            'import_number.max'          => 'رقم الوارد يجب أن يكون بين 1 و 999999999.',
            'approvers.required'         => 'اختر معتمداً واحداً على الأقل.',
            'approvers.array'            => 'اختر معتمداً واحداً على الأقل.',
            'approvers.min'              => 'اختر معتمداً واحداً على الأقل.',
            'approvers.max'              => 'لا يزيد عدد المعتمدين على 10.',
            'approvers.*.integer'        => 'المعتمد المحدد غير صالح.',
            'approvers.*.exists'         => 'المعتمد المحدد غير موجود.',
            'attachments.array'          => 'المرفقات غير صالحة.',
            'attachments.max'            => 'لا تزيد المرفقات على 10.',
            'attachments.*.file'         => self::ATTACHMENT_MESSAGE,
            'attachments.*.mimes'        => self::ATTACHMENT_MESSAGE,
            'attachments.*.max'          => self::ATTACHMENT_MESSAGE,
            'attachments.*.uploaded'     => self::ATTACHMENT_MESSAGE,
        ];
    }

    public function toInput(): CreateDocumentInput
    {
        $attachments = $this->file('attachments');

        return new CreateDocumentInput(
            title: $this->titleInput(),
            description: $this->descriptionInput(),
            importNumber: $this->filled('import_number') ? (int) $this->input('import_number') : null,
            file: $this->fileInput(),
            approverIds: array_map('intval', array_values((array) $this->input('approvers'))),
            templateId: $this->templateIdInput(),
            fieldValues: $this->fieldValuesInput(),
            exportNumber: $this->exportNumberInput(),
            attachments: array_values(array_filter((array) $attachments, static fn ($file): bool => $file instanceof UploadedFile)),
        );
    }
}
