<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

use Illuminate\Http\UploadedFile;

/**
 * What a creator gives to send a document between managers (documents design
 * §7.1, §8.3), already validated by the request: a file to upload, or a
 * template with its values and, optionally, a typed صادر number — never both.
 */
final readonly class CreateDocumentInput
{
    /**
     * @param  array<int, int>  $approverIds  in the order they sign
     * @param  array<string, mixed>  $fieldValues  as typed, keyed by field key; the service checks them
     * @param  array<int, UploadedFile>  $attachments
     */
    public function __construct(
        public string $title,
        public ?string $description,
        public ?int $importNumber,
        public ?UploadedFile $file,
        public array $approverIds,
        public ?int $templateId = null,
        public array $fieldValues = [],
        public ?int $exportNumber = null,
        public array $attachments = [],
    ) {}
}
