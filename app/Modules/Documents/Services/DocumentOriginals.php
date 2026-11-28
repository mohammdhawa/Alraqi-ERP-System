<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Exceptions\DocumentRouteException;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Documents\Pdf\PdfIntake;
use App\Modules\Documents\Pdf\PdfSession;
use App\Modules\Documents\Pdf\PdfWorkspace;
use App\Modules\Documents\Pdf\TemplatePrinter;
use App\Modules\Documents\Support\RecipientValues;
use App\Modules\Documents\Support\StoredOriginal;
use App\Modules\Documents\Support\SystemValues;
use App\Modules\Documents\Templates\FieldValues;
use App\Modules\Documents\Templates\TemplateFields;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * A document's original, for both workflows (documents design §7.1, §8.3,
 * §8.4, §11.2): a file uploaded through piece 1's intake, or a template
 * printed with its صادر number and reference. Moved out of piece 2–3's
 * `DocumentCreation` as it stood, so documents between managers and
 * documents to employees share one copy of it.
 *
 * WHERE EACH RUNS (ruling R1):
 * - `storeUpload()` before the caller's transaction, as piece 2 stores an
 *   upload: a file is not in the transaction, and the intake's work is no
 *   reason to hold one open;
 * - `printTemplate()` inside it, since it claims the numbers: the counter and
 *   the sequence roll back with the transaction.
 *
 * Each removes the document's folder when it throws itself. A throw after it
 * (the row refused, the caller's own work) is the caller's to clean up with
 * `discard()`, inside its transaction and not around it: Laravel runs the
 * after-commit callbacks still inside `transaction()`, and a throw from there
 * must not delete the files a committed document names.
 */
final class DocumentOriginals
{
    public const DISK = 'local';

    private const TEMPLATE_UNAVAILABLE = 'القالب المختار غير موجود أو غير مفعّل.';

    private const TEMPLATE_FOR_EMPLOYEES = 'هذا القالب للمستندات المرسلة إلى الموظفين.';

    private const TEMPLATE_NOT_FOR_EMPLOYEES = 'هذا القالب ليس للمستندات المرسلة إلى الموظفين.';

    private const COLD_REGISTER = 'هذا أول مستند صادر في الإدارة، يرجى إدخال رقم الصادر لتحديد نقطة البداية.';

    /**
     * What the driver's own message of a unique violation names for each
     * number's index: SQLite names its columns, MySQL and PostgreSQL the
     * index (`documents_register_unit_id_export_number_unique`,
     * `documents_register_unit_id_live_import_number_unique`). Neither text
     * is part of the other.
     */
    private const EXPORT_INDEX_MARK = 'export_number';

    private const IMPORT_INDEX_MARK = 'live_import_number';

    public function __construct(
        private readonly PdfWorkspace $workspace,
        private readonly PdfIntake $intake,
        private readonly FieldValues $fieldValues,
        private readonly ExportNumbers $exportNumbers,
        private readonly ReferenceNumbers $references,
        private readonly TemplatePrinter $printer,
    ) {}

    /**
     * A live, active template for the workflow: one for employees only to an
     * employee, any other only between managers (ruling R6).
     *
     * @throws DocumentRouteException
     */
    public function template(int $id, bool $forEmployees): DocumentTemplate
    {
        $template = DocumentTemplate::query()->active()->find($id)
            ?? throw new DocumentRouteException(self::TEMPLATE_UNAVAILABLE);

        if ($template->for_employees !== $forEmployees) {
            throw new DocumentRouteException($forEmployees ? self::TEMPLATE_NOT_FOR_EMPLOYEES : self::TEMPLATE_FOR_EMPLOYEES);
        }

        return $template;
    }

    /**
     * A template's refusals, before any write: the values against its fields,
     * and the صادر number — a typed one already used, or none typed on a cold
     * register. `printTemplate()` checks them all again inside the
     * transaction (ruling R4); these spare it the work.
     *
     * @param  array<string, mixed>  $values  as typed
     *
     * @throws DocumentRouteException
     * @throws ValidationException
     */
    public function checkTemplate(DocumentTemplate $template, array $values, Department $register, ?int $typedExport): void
    {
        $this->fieldValues->check(TemplateFields::fromArray($template->fields), $values);

        if ($typedExport === null) {
            if ($this->exportNumbers->next($register) === null) {
                throw new DocumentRouteException(self::COLD_REGISTER);
            }
        } elseif ($this->exportNumbers->isUsed($register, $typedExport)) {
            throw $this->exportNumberTaken($typedExport);
        }
    }

    /**
     * The intake's printable file becomes `original.pdf`; the file as
     * uploaded is kept beside it as `uploaded.pdf` only when it was rewritten.
     * A throw removes the folder.
     *
     * @param  UploadedFile|null  $file  the request's; it requires one without a template
     */
    public function storeUpload(?UploadedFile $file, string $directory): StoredOriginal
    {
        if ($file === null) {
            throw new LogicException('An upload needs its file; the request requires one without a template.');
        }

        try {
            [$original, $uploaded] = $this->workspace->run(function (PdfSession $session) use ($file, $directory): array {
                $accepted = $this->intake->accept($file, $session);
                $disk = Storage::disk(self::DISK);

                $original = $disk->putFileAs($directory, new File($accepted->printablePath), 'original.pdf');
                $uploaded = $accepted->uploadedPath === null
                    ? null
                    : $disk->putFileAs($directory, new File($accepted->uploadedPath), 'uploaded.pdf');

                // The disk answers false to a write it could not make; a path of
                // '' or a missing uploaded copy must never be saved as success.
                if ($original === false || ($accepted->uploadedPath !== null && $uploaded === false)) {
                    throw new RuntimeException('The document file could not be written.');
                }

                return [(string) $original, $uploaded === null ? null : (string) $uploaded];
            });
        } catch (Throwable $e) {
            $this->discard($directory);

            throw $e;
        }

        return StoredOriginal::uploaded($original, $uploaded);
    }

    /**
     * Inside the caller's transaction, on the template the caller re-read in
     * it (so the version it records is the one printed), in this order:
     * 1. the values checked again against that template (ruling R4), so an
     *    edit since `checkTemplate()` is caught here;
     * 2. the صادر number claimed in the register, then the reference (the
     *    lock order);
     * 3. the creator's values numbered with them and dated now, after the
     *    claims, as piece 3 dated the page;
     * 4. the page printed and stored as `original.pdf` (a write the disk
     *    refuses throws).
     *
     * A throw removes the folder. The result's `columns()` is the row's
     * share of it.
     *
     * @param  array<string, mixed>  $values  as typed
     * @param  SystemValues  $system  the creator's, unnumbered (`SystemValues::unnumbered()`); numbered once, here
     *
     * @throws DocumentRouteException
     * @throws ValidationException
     */
    public function printTemplate(
        DocumentTemplate $template,
        array $values,
        Department $register,
        ?int $typedExport,
        SystemValues $system,
        ?RecipientValues $recipient,
        string $directory,
    ): StoredOriginal {
        try {
            $checked = $this->fieldValues->check(TemplateFields::fromArray($template->fields), $values);

            $exportNumber = $this->exportNumbers->claim($register, $typedExport);
            $reference = $this->references->claim($template->reference_prefix);

            $numbered = $system->numbered($exportNumber, $reference, CarbonImmutable::now());

            [$original, $cardFloor, $contentBottom] = $this->workspace->run(function (PdfSession $session) use ($template, $checked, $numbered, $recipient, $directory): array {
                $rendered = $this->printer->print($template, $checked, $numbered, $recipient, $session);

                // The disk answers false to a write it could not make; a path of
                // '' must never be saved as success.
                $original = Storage::disk(self::DISK)->putFileAs($directory, new File($rendered->path), 'original.pdf');

                if ($original === false) {
                    throw new RuntimeException('The document file could not be written.');
                }

                return [(string) $original, $rendered->cardFloor, $rendered->contentBottom];
            });
        } catch (Throwable $e) {
            $this->discard($directory);

            throw $e;
        }

        return StoredOriginal::printed($template, $checked, $exportNumber, $reference, $cardFloor, $contentBottom, $original);
    }

    /**
     * The document's row. A unique index refusing it means another document
     * took one of its numbers between the check and here (piece 3's Review
     * Focus 3): that answers the same "used" copy as the checks.
     *
     * @param  array<string, mixed>  $row
     *
     * @throws DocumentRouteException
     */
    public function createDocument(array $row): Document
    {
        try {
            return Document::create($row);
        } catch (UniqueConstraintViolationException $e) {
            throw $this->numberTakenMeanwhile($e, $row);
        }
    }

    /** Removes the document's folder and everything stored in it. */
    public function discard(string $directory): void
    {
        Storage::disk(self::DISK)->deleteDirectory($directory);
    }

    public function importNumberTaken(int $number): DocumentRouteException
    {
        return new DocumentRouteException("رقم الوارد {$number} مستخدم في سجل هذه الإدارة.");
    }

    public function exportNumberTaken(int $number): DocumentRouteException
    {
        return new DocumentRouteException("رقم الصادر {$number} مستخدم في سجل هذه الإدارة.");
    }

    /**
     * The DB index is the authority for a number committed while this claim
     * waited. The index is told by the driver's own message (the PDO error
     * info), never by the exception's whole message, whose SQL names every
     * column. A violation of any other index, or of a number the row does not
     * carry, is not a number's, and goes on as it is.
     *
     * @param  array<string, mixed>  $row
     */
    private function numberTakenMeanwhile(UniqueConstraintViolationException $e, array $row): Throwable
    {
        $detail = (string) ($e->errorInfo[2] ?? $e->getPrevious()?->getMessage() ?? '');

        return match (true) {
            isset($row['export_number']) && str_contains($detail, self::EXPORT_INDEX_MARK) => $this->exportNumberTaken((int) $row['export_number']),
            isset($row['import_number']) && str_contains($detail, self::IMPORT_INDEX_MARK) => $this->importNumberTaken((int) $row['import_number']),
            default                                                                       => $e,
        };
    }
}
