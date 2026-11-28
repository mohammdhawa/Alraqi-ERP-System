<?php

declare(strict_types=1);

namespace App\Modules\Documents\Requests;

use App\Modules\Documents\Requests\Concerns\CarriesADocument;
use App\Modules\Documents\Support\SendDocumentInput;
use App\Modules\HR\Enums\EmployeeDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/documents/to-employee — a document one of HR's managers sends to
 * one employee (documents to employees, "What a sent document carries"):
 * the title, the description and the original are `CarriesADocument`'s, as
 * piece 2–3's request reads them and in its words. It carries no approvers,
 * no وارد number and no supporting attachments; each is refused if sent.
 *
 * The sender's standing (one of HR's managers), the recipient's (an employee
 * on duty with an active account, not the sender), the template's (live,
 * active, for employees), its values and the صادر number are the service's.
 */
class SendDocumentRequest extends FormRequest
{
    use CarriesADocument;

    private const FILING_TYPE_MESSAGE = 'نوع الحفظ غير صالح.';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->headingRules() + [
            'recipient_id'  => ['required', 'integer'],
            'filing_type'   => [
                'required', 'integer',
                Rule::in(array_map(static fn (EmployeeDocumentType $type): int => $type->value, EmployeeDocumentType::forSentDocuments())),
            ],
        ] + $this->originalRules() + [
            'approvers'     => ['prohibited'],
            'import_number' => ['prohibited'],
            'attachments'   => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return $this->documentMessages() + [
            'recipient_id.required'    => 'الموظف المستلم مطلوب.',
            'recipient_id.integer'     => 'الموظف المحدد غير موجود.',
            'filing_type.required'     => 'نوع الحفظ في ملف الموظف مطلوب.',
            'filing_type.integer'      => self::FILING_TYPE_MESSAGE,
            'filing_type.in'           => self::FILING_TYPE_MESSAGE,
            'approvers.prohibited'     => 'لا يُحدَّد معتمدون لمستند يُرسل إلى موظف.',
            'import_number.prohibited' => 'لا يُحدَّد رقم وارد لمستند يُرسل إلى موظف.',
            'attachments.prohibited'   => 'لا تُرفق ملفات داعمة بمستند يُرسل إلى موظف.',
        ];
    }

    public function toInput(): SendDocumentInput
    {
        return new SendDocumentInput(
            title: $this->titleInput(),
            description: $this->descriptionInput(),
            recipientId: (int) $this->input('recipient_id'),
            filingType: EmployeeDocumentType::from((int) $this->input('filing_type')),
            file: $this->fileInput(),
            templateId: $this->templateIdInput(),
            fieldValues: $this->fieldValuesInput(),
            exportNumber: $this->exportNumberInput(),
        );
    }
}
