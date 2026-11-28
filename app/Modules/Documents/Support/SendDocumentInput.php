<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

use App\Modules\HR\Enums\EmployeeDocumentType;
use Illuminate\Http\UploadedFile;

/**
 * What one of HR's managers gives to send a document to an employee
 * (documents to employees, "What a sent document carries"), already validated
 * by the request: the recipient, how the answered document is filed on their
 * record, and a file to upload or a template with its values and, optionally,
 * a typed صادر number — never both. No approvers, no وارد number, no
 * supporting attachments.
 */
final readonly class SendDocumentInput
{
    /**
     * @param  array<string, mixed>  $fieldValues  as typed, keyed by field key; the service checks them
     */
    public function __construct(
        public string $title,
        public ?string $description,
        public int $recipientId,
        public EmployeeDocumentType $filingType,
        public ?UploadedFile $file,
        public ?int $templateId = null,
        public array $fieldValues = [],
        public ?int $exportNumber = null,
    ) {}
}
