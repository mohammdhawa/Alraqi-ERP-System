<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\Document;
use App\Modules\HR\Models\EmployeeDocument;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * Files an answered document on its recipient's record (documents to
 * employees, "Filing on the record"): one `employee_documents` row pointing at
 * the document's own file — the printed copy when it was signed, the copy as
 * sent when it was refused. Nothing is copied. The file is read from the
 * `local` disk, where every `employee_documents.file_path` lives (ruling R2).
 *
 * The caller (the answer) runs it inside its transaction, with the document
 * already marked Signed or Refused and dated.
 */
final class RecordFiling
{
    private const TIMEZONE = 'Asia/Damascus';

    private const NAME_STEM_MAX = 251;

    public function file(Document $document): EmployeeDocument
    {
        [$path, $answeredAt] = match ($document->status) {
            DocumentStatus::Signed  => [$document->printed_path, $document->signed_at],
            DocumentStatus::Refused => [$document->original_path, $document->refused_at],
            default                 => throw new LogicException('Only a signed or refused document is filed on the record.'),
        };

        if ($path === null || $answeredAt === null || $document->recipient_id === null || $document->filing_type === null) {
            throw new LogicException('An answered document carries its file, its answer date, its recipient and its filing type.');
        }

        if ($document->filedRecord()->exists()) {
            throw new LogicException('This document is already filed on the record.');
        }

        return EmployeeDocument::create([
            'employee_id'   => $document->recipient_id,
            'type'          => $document->filing_type,
            'title'         => $document->title,
            'file_path'     => $path,
            'original_name' => mb_substr($document->title, 0, self::NAME_STEM_MAX).'.pdf', // the column is varchar(255)
            'mime_type'     => 'application/pdf',
            'size_bytes'    => Storage::disk(DocumentOriginals::DISK)->size($path),
            'uploaded_by'   => $document->created_by,
            'issued_at'     => $answeredAt->copy()->timezone(self::TIMEZONE)->toDateString(),
            'document_id'   => $document->id,
        ]);
    }
}
