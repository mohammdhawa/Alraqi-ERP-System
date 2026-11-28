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
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Exceptions\DocumentRouteException;
use App\Modules\Documents\Exceptions\NotAUnitManagerException;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Support\CreateDocumentInput;
use App\Modules\Documents\Support\SystemValues;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Shared\Services\AttachmentStore;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
 * The original itself, either way, is `DocumentOriginals`' (shared with
 * documents to employees).
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
    public function __construct(
        private readonly UnitManagers $managers,
        private readonly DocumentOriginals $originals,
        private readonly AttachmentStore $attachments,
        private readonly ApprovalService $approvals,
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
            $this->originals->checkTemplate(
                $this->originals->template($input->templateId, forEmployees: false),
                $input->fieldValues,
                $register,
                $input->exportNumber,
            );
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
            $templateId = $input->templateId;

            // Inside the transaction: the template re-read — the version
            // recorded and the page printed are this read's — then its values
            // checked again (ruling R4), its numbers claimed and its page
            // printed, so an edit or a deactivation since the checks is
            // caught there.
            return $this->persist($input, $by, $generalManager, $directory, fn (): array => $row + $this->originals->printTemplate(
                $this->originals->template($templateId, forEmployees: false),
                $input->fieldValues,
                $register,
                $input->exportNumber,
                SystemValues::unnumbered((string) $creator->full_name, (string) $unit->name, $input->importNumber),
                null,
                $directory,
            )->columns());
        }

        // Before the transaction (ruling R1); it removes the folder if it throws.
        $columns = $row + $this->originals->storeUpload($input->file, $directory)->columns();

        return $this->persist($input, $by, $generalManager, $directory, static fn (): array => $columns);
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
                $document = $this->originals->createDocument($columns());

                foreach ($input->attachments as $file) {
                    $storedAttachments[] = $this->attachments->store($file, $document, $by)->path;
                }

                $this->approvals->submit($document, $this->route($input->approverIds, $generalManager));

                return $document;
            } catch (Throwable $e) {
                $this->originals->discard($directory);
                $this->attachments->deleteFiles($storedAttachments);

                throw $e;
            }
        });
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
            throw $this->originals->importNumberTaken($number);
        }
    }
}
