<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

use App\Modules\Documents\Enums\DocumentOrigin;
use App\Modules\Documents\Models\DocumentTemplate;

/**
 * A document's original as `DocumentOriginals` stored it under the document's
 * folder: the printable `original.pdf`, and beside it the file as uploaded
 * when the intake rewrote it. A printed template's also carries the template
 * version printed, its values as checked against it, the numbers claimed for
 * it, where its cards stop above the footer and where its text ends (ruling
 * R17). An upload's are null.
 *
 * `columns()` is the one place the original is written into a document's
 * row, for both workflows.
 */
final readonly class StoredOriginal
{
    /**
     * @param  array<string, mixed>|null  $fieldValues  normalised, keyed by field key
     */
    private function __construct(
        public DocumentOrigin $origin,
        public string $originalPath,
        public ?string $uploadedPath = null,
        public ?int $templateId = null,
        public ?int $templateVersion = null,
        public ?array $fieldValues = null,
        public ?int $exportNumber = null,
        public ?string $reference = null,
        public ?int $cardFloor = null,
        public ?float $contentBottom = null,
    ) {}

    public static function uploaded(string $originalPath, ?string $uploadedPath): self
    {
        return new self(DocumentOrigin::Uploaded, $originalPath, $uploadedPath);
    }

    /**
     * @param  array<string, mixed>  $fieldValues  as checked against the template printed
     */
    public static function printed(
        DocumentTemplate $template,
        array $fieldValues,
        int $exportNumber,
        string $reference,
        ?int $cardFloor,
        ?float $contentBottom,
        string $originalPath,
    ): self {
        return new self(
            origin: DocumentOrigin::Template,
            originalPath: $originalPath,
            templateId: (int) $template->getKey(),
            templateVersion: $template->version,
            fieldValues: $fieldValues,
            exportNumber: $exportNumber,
            reference: $reference,
            cardFloor: $cardFloor,
            contentBottom: $contentBottom,
        );
    }

    /**
     * The document row's columns for this original: its origin and files, and
     * for a template the version printed, its values and numbers, the card
     * floor and the text's end. An upload's row leaves the template's columns
     * out, as piece 2's always did.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        $columns = ['origin' => $this->origin];

        if ($this->origin === DocumentOrigin::Template) {
            $columns += [
                'template_id'      => $this->templateId,
                'template_version' => $this->templateVersion,
                'field_values'     => $this->fieldValues,
                'export_number'    => $this->exportNumber,
                'reference'        => $this->reference,
                'card_floor'       => $this->cardFloor,
                'content_bottom'   => $this->contentBottom,
            ];
        }

        return $columns + [
            'original_path' => $this->originalPath,
            'uploaded_path' => $this->uploadedPath,
        ];
    }
}
