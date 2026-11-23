<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

use Illuminate\Http\UploadedFile;

/**
 * What a creator gives to send a document between managers (documents design
 * §7.1), already validated by the request.
 */
final readonly class CreateDocumentInput
{
    /**
     * @param  array<int, int>  $approverIds  in the order they sign
     * @param  array<int, UploadedFile>  $attachments
     */
    public function __construct(
        public string $title,
        public ?string $description,
        public ?int $importNumber,
        public UploadedFile $file,
        public array $approverIds,
        public array $attachments = [],
    ) {}
}
