<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Approvals\Enums\RejectionPolicy;
use App\Modules\Approvals\Services\ApprovalService;
use App\Modules\Approvals\Support\ApprovalRoute;
use App\Modules\Approvals\Support\StepDefinition;
use App\Modules\Auth\Models\User;
use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Enums\DocumentKind;
use App\Modules\Documents\Enums\DocumentOrigin;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Exceptions\DocumentRouteException;
use App\Modules\Documents\Exceptions\NotAUnitManagerException;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Documents\Pdf\PdfIntake;
use App\Modules\Documents\Pdf\PdfSession;
use App\Modules\Documents\Pdf\PdfWorkspace;
use App\Modules\Documents\Pdf\TemplatePrinter;
use App\Modules\Documents\Support\CreateDocumentInput;
use App\Modules\Documents\Support\SystemValues;
use App\Modules\Documents\Templates\FieldValues;
use App\Modules\Documents\Templates\TemplateFields;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Shared\Services\AttachmentStore;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Sending a document between managers for signing (documents design §7.1),
 * uploaded as a file or made from a template (§8.3, §8.4, §11.2).
 *
 * Everything that can be refused is refused first, before a byte is written.
 * An upload's files are then stored, and one transaction makes the row, the
 * attachments and the approval's submission. A template's numbers and page
 * belong to that transaction instead: it re-reads the template, claims the
 * صادر number and the reference, prints the page as the original, then goes
 * on as an upload does — so the counter and the sequence roll back with it.
 *
 * A throw while storing the files or inside the transaction removes the
 * folder and the stored attachments, because a file is not in the
 * transaction. A failure after the commit (an after-commit callback) deletes
 * nothing; a failed commit itself can at worst leave unreferenced files,
 * never a committed document without its own.
 *
 * WHO: the creator is the caller's own employee; the route names the
 * approvers, in the order they sign, and المدير العام always decides last.
 */
final class DocumentCreation
{
    public const DISK = 'local';

    private const TEMPLATE_UNAVAILABLE = 'القالب المختار غير موجود أو غير مفعّل.';

    private const TEMPLATE_FOR_EMPLOYEES = 'هذا القالب للمستندات المرسلة إلى الموظفين.';

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
        private readonly UnitManagers $managers,
        private readonly PdfWorkspace $workspace,
        private readonly PdfIntake $intake,
        private readonly AttachmentStore $attachments,
        private readonly ApprovalService $approvals,
        private readonly FieldValues $fieldValues,
        private readonly ExportNumbers $exportNumbers,
        private readonly ReferenceNumbers $references,
        private readonly TemplatePrinter $printer,
    ) {}

    /**
     * @throws NotAUnitManagerException
     * @throws DocumentRouteException
     * @throws ValidationException a template's values, under `field_values.{key}`
     */
    public function create(Employee $creator, User $by, CreateDocumentInput $input): Document
    {
        $unit = $this->managers->unitOf($creator) ?? throw new NotAUnitManagerException;

        $generalManager = $this->managers->generalManager()
            ?? throw new DocumentRouteException('لا يوجد مدير عام في الهيكل التنظيمي، فلا يمكن إرسال المستند.');

        $this->checkApprovers($creator, $generalManager, $input->approverIds);

        $register = $this->managers->registerOf($unit);
        $this->checkImportNumber($register, $input->importNumber);

        if ($input->templateId !== null) {
            $this->checkTemplate($input, $register);
        }

        $folder = (string) Str::uuid();
        $directory = "documents/{$folder}";

        $row = [
            'kind'             => DocumentKind::Internal,
            'title'            => $input->title,
            'description'      => $input->description,
            'creator_id'       => $creator->getKey(),
            'creator_unit_id'  => $unit->getKey(),
            'register_unit_id' => $register->getKey(),
            'created_by'       => $by->getKey(),
            'import_number'    => $input->importNumber,
            'status'           => DocumentStatus::Pending,
            'folder'           => $folder,
        ];

        if ($input->templateId !== null) {
            return $this->persist($input, $by, $generalManager, $directory, fn (): array => $row + $this->printTemplate($creator, $unit, $register, $input, $directory));
        }

        try {
            [$original, $uploaded] = $this->storeFiles($input, $directory);
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->deleteDirectory($directory);

            throw $e;
        }

        return $this->persist($input, $by, $generalManager, $directory, static fn (): array => $row + [
            'origin'        => DocumentOrigin::Uploaded,
            'original_path' => $original,
            'uploaded_path' => $uploaded,
        ]);
    }

    /**
     * One transaction: the row's columns (for a template, its numbers claimed
     * and its page printed first), the row, its attachments, then the
     * approval's submission.
     *
     * @param  Closure(): array<string, mixed>  $columns
     */
    private function persist(CreateDocumentInput $input, User $by, Employee $generalManager, string $directory, Closure $columns): Document
    {
        return DB::transaction(function () use ($input, $by, $generalManager, $directory, $columns): Document {
            $storedAttachments = [];

            // Inside the transaction, not around it: Laravel runs after-commit
            // callbacks (the first approver's push) once the commit is done,
            // still inside transaction(), and a throw from there must not
            // delete the files the committed document names.
            try {
                $row = $columns();

                try {
                    $document = Document::create($row);
                } catch (UniqueConstraintViolationException $e) {
                    throw $this->numberTakenMeanwhile($e, $row);
                }

                foreach ($input->attachments as $file) {
                    $storedAttachments[] = $this->attachments->store($file, $document, $by)->path;
                }

                $this->approvals->submit($document, $this->route($input->approverIds, $generalManager));

                return $document;
            } catch (Throwable $e) {
                Storage::disk(self::DISK)->deleteDirectory($directory);
                $this->attachments->deleteFiles($storedAttachments);

                throw $e;
            }
        });
    }

    /**
     * A template's refusals, before any write: the template itself, the
     * values against its fields, and the صادر number — a typed one already
     * used, or none typed on a cold register. The transaction checks them
     * all again (ruling R4); these spare it the work.
     *
     * @throws DocumentRouteException
     * @throws ValidationException
     */
    private function checkTemplate(CreateDocumentInput $input, Department $register): void
    {
        $template = $this->template((int) $input->templateId);

        $this->fieldValues->check(TemplateFields::fromArray($template->fields), $input->fieldValues);

        if ($input->exportNumber === null) {
            if ($this->exportNumbers->next($register) === null) {
                throw new DocumentRouteException(self::COLD_REGISTER);
            }
        } elseif ($this->exportNumbers->isUsed($register, $input->exportNumber)) {
            throw $this->exportNumberTaken($input->exportNumber);
        }
    }

    /**
     * Inside the transaction, in this order:
     * 1. the template re-read — the version recorded and the page printed are
     *    this read's — and the values checked again against it (ruling R4),
     *    so an edit or a deactivation since the check above is caught here;
     * 2. the صادر number claimed, then the reference (the lock order);
     * 3. the page printed and stored as `original.pdf` (a write the disk
     *    refuses throws).
     *
     * @return array<string, mixed> the template's columns of the row
     *
     * @throws DocumentRouteException
     * @throws ValidationException
     */
    private function printTemplate(Employee $creator, Department $unit, Department $register, CreateDocumentInput $input, string $directory): array
    {
        $template = $this->template((int) $input->templateId);
        $values = $this->fieldValues->check(TemplateFields::fromArray($template->fields), $input->fieldValues);

        $exportNumber = $this->exportNumbers->claim($register, $input->exportNumber);
        $reference = $this->references->claim($template->reference_prefix);

        $system = new SystemValues(
            exportNumber: $exportNumber,
            importNumber: $input->importNumber,
            reference: $reference,
            date: CarbonImmutable::now(),
            creatorName: (string) $creator->full_name,
            creatorUnit: (string) $unit->name,
        );

        [$original, $cardFloor, $contentBottom] = $this->workspace->run(function (PdfSession $session) use ($template, $values, $system, $directory): array {
            $rendered = $this->printer->print($template, $values, $system, null, $session);

            // The disk answers false to a write it could not make; a path of
            // '' must never be saved as success.
            $original = Storage::disk(self::DISK)->putFileAs($directory, new File($rendered->path), 'original.pdf');

            if ($original === false) {
                throw new RuntimeException('The document file could not be written.');
            }

            return [(string) $original, $rendered->cardFloor, $rendered->contentBottom];
        });

        return [
            'origin'           => DocumentOrigin::Template,
            'template_id'      => $template->id,
            'template_version' => $template->version,
            'field_values'     => $values,
            'export_number'    => $exportNumber,
            'reference'        => $reference,
            'card_floor'       => $cardFloor,
            'content_bottom'   => $contentBottom,
            'original_path'    => $original,
            'uploaded_path'    => null,
        ];
    }

    /**
     * A live, active template for a document between managers (ruling R6):
     * one for employees is piece 4's workflow.
     *
     * @throws DocumentRouteException
     */
    private function template(int $id): DocumentTemplate
    {
        $template = DocumentTemplate::query()->active()->find($id)
            ?? throw new DocumentRouteException(self::TEMPLATE_UNAVAILABLE);

        if ($template->for_employees) {
            throw new DocumentRouteException(self::TEMPLATE_FOR_EMPLOYEES);
        }

        return $template;
    }

    /**
     * @param  array<int, int>  $approverIds
     */
    private function route(array $approverIds, Employee $generalManager): ApprovalRoute
    {
        $steps = array_map(static fn (int $id): StepDefinition => StepDefinition::namedEmployee($id), $approverIds);
        $steps[] = StepDefinition::namedEmployee((int) $generalManager->getKey())->decisive();

        return ApprovalRoute::sequential(RejectionPolicy::Advisory, ...$steps);
    }

    /**
     * Each approver, in the order named: a unit's manager, employed, not the
     * creator, not المدير العام, and named once.
     *
     * @param  array<int, int>  $approverIds
     *
     * @throws DocumentRouteException
     */
    private function checkApprovers(Employee $creator, Employee $generalManager, array $approverIds): void
    {
        $people = Employee::query()->whereKey($approverIds)->get()->keyBy('id');
        $seen = [];

        foreach ($approverIds as $id) {
            /** @var Employee|null $approver */
            $approver = $people->get($id);

            if ($approver === null) {
                throw new DocumentRouteException('المعتمد المحدد غير موجود.');
            }

            if ($id === (int) $creator->getKey()) {
                throw new DocumentRouteException('لا يمكنك اختيار نفسك معتمداً.');
            }

            if ($id === (int) $generalManager->getKey()) {
                throw new DocumentRouteException('المدير العام يُضاف تلقائياً في آخر المسار، فلا تختره بين المعتمدين.');
            }

            if (isset($seen[$id])) {
                throw new DocumentRouteException('لا يمكن تكرار المعتمد نفسه.');
            }

            $seen[$id] = true;

            if ($this->managers->unitOf($approver) === null) {
                throw new DocumentRouteException("لا يمكن اختيار «{$approver->full_name}» معتمداً لأنه لا يدير وحدة تنظيمية.");
            }

            if ($approver->status !== EmployeeStatus::Active) {
                throw new DocumentRouteException("لا يمكن اختيار «{$approver->full_name}» معتمداً لأنه ليس على رأس عمله.");
            }
        }
    }

    /**
     * Unique among the register's LIVE documents: one deleted before its first
     * signature, or closed as «لم يُستكمل» (§7.8), is sent again with the same
     * number (the table's index reads `live_import_number`).
     *
     * @throws DocumentRouteException
     */
    private function checkImportNumber(Department $register, ?int $number): void
    {
        if ($number === null) {
            return;
        }

        $taken = Document::query()
            ->where('register_unit_id', $register->getKey())
            ->where('import_number', $number)
            ->where('status', '<>', DocumentStatus::NotCompleted->value)
            ->exists();

        if ($taken) {
            throw $this->numberTaken($number);
        }
    }

    private function numberTaken(int $number): DocumentRouteException
    {
        return new DocumentRouteException("رقم الوارد {$number} مستخدم في سجل هذه الإدارة.");
    }

    private function exportNumberTaken(int $number): DocumentRouteException
    {
        return new DocumentRouteException("رقم الصادر {$number} مستخدم في سجل هذه الإدارة.");
    }

    /**
     * A unique index refused the row: another document took one of its
     * numbers between the check and here (Review Focus 3). The DB index is
     * the authority for a صادر number committed while this claim waited, so
     * that violation answers the same "used" copy as the checks.
     *
     * The index is told by the driver's own message (the PDO error info),
     * never by the exception's whole message, whose SQL names every column.
     * A violation of any other index, or of a number the row does not carry,
     * is not a number's, and goes on as it is.
     *
     * @param  array<string, mixed>  $row
     */
    private function numberTakenMeanwhile(UniqueConstraintViolationException $e, array $row): Throwable
    {
        $detail = (string) ($e->errorInfo[2] ?? $e->getPrevious()?->getMessage() ?? '');

        return match (true) {
            isset($row['export_number']) && str_contains($detail, self::EXPORT_INDEX_MARK) => $this->exportNumberTaken((int) $row['export_number']),
            isset($row['import_number']) && str_contains($detail, self::IMPORT_INDEX_MARK) => $this->numberTaken((int) $row['import_number']),
            default                                                                       => $e,
        };
    }

    /**
     * The intake's printable file becomes `original.pdf`; the file as
     * uploaded is kept beside it as `uploaded.pdf` only when it was rewritten.
     *
     * @return array{0: string, 1: string|null} the stored paths
     */
    private function storeFiles(CreateDocumentInput $input, string $directory): array
    {
        $file = $input->file ?? throw new LogicException('An upload needs its file; the request requires one without a template.');

        return $this->workspace->run(function (PdfSession $session) use ($file, $directory): array {
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
    }
}
