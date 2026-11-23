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
use App\Modules\Documents\Pdf\PdfIntake;
use App\Modules\Documents\Pdf\PdfSession;
use App\Modules\Documents\Pdf\PdfWorkspace;
use App\Modules\Documents\Support\CreateDocumentInput;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Shared\Services\AttachmentStore;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sending a document between managers for signing (documents design §7.1).
 *
 * Everything that can be refused is refused first, before a byte is written.
 * Then the files, then one transaction (the row, the attachments, the
 * approval's submission); a throw while storing the files or inside the
 * transaction removes the folder and the stored attachments, because a file
 * is not in the transaction. A failure after the commit (an after-commit
 * callback) deletes nothing; a failed commit itself can at worst leave
 * unreferenced files, never a committed document without its own.
 *
 * WHO: the creator is the caller's own employee; the route names the
 * approvers, in the order they sign, and المدير العام always decides last.
 */
final class DocumentCreation
{
    public const DISK = 'local';

    public function __construct(
        private readonly UnitManagers $managers,
        private readonly PdfWorkspace $workspace,
        private readonly PdfIntake $intake,
        private readonly AttachmentStore $attachments,
        private readonly ApprovalService $approvals,
    ) {}

    /**
     * @throws NotAUnitManagerException
     * @throws DocumentRouteException
     */
    public function create(Employee $creator, User $by, CreateDocumentInput $input): Document
    {
        $unit = $this->managers->unitOf($creator) ?? throw new NotAUnitManagerException;

        $generalManager = $this->managers->generalManager()
            ?? throw new DocumentRouteException('لا يوجد مدير عام في الهيكل التنظيمي، فلا يمكن إرسال المستند.');

        $this->checkApprovers($creator, $generalManager, $input->approverIds);

        $register = $this->managers->registerOf($unit);
        $this->checkImportNumber($register, $input->importNumber);

        $folder = (string) Str::uuid();
        $directory = "documents/{$folder}";

        try {
            [$original, $uploaded] = $this->storeFiles($input, $directory);
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->deleteDirectory($directory);

            throw $e;
        }

        return DB::transaction(function () use ($creator, $by, $input, $unit, $register, $folder, $directory, $original, $uploaded, $generalManager): Document {
            $storedAttachments = [];

            // Inside the transaction, not around it: Laravel runs after-commit
            // callbacks (the first approver's push) once the commit is done,
            // still inside transaction(), and a throw from there must not
            // delete the files the committed document names.
            try {
                try {
                    $document = Document::create([
                        'kind'             => DocumentKind::Internal,
                        'origin'           => DocumentOrigin::Uploaded,
                        'title'            => $input->title,
                        'description'      => $input->description,
                        'creator_id'       => $creator->getKey(),
                        'creator_unit_id'  => $unit->getKey(),
                        'register_unit_id' => $register->getKey(),
                        'created_by'       => $by->getKey(),
                        'import_number'    => $input->importNumber,
                        'status'           => DocumentStatus::Pending,
                        'folder'           => $folder,
                        'original_path'    => $original,
                        'uploaded_path'    => $uploaded,
                    ]);
                } catch (UniqueConstraintViolationException $e) {
                    // Another document took the number between the check and
                    // here. Without a number the violation is not that.
                    throw $input->importNumber === null ? $e : $this->numberTaken($input->importNumber);
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

    /**
     * The intake's printable file becomes `original.pdf`; the file as
     * uploaded is kept beside it as `uploaded.pdf` only when it was rewritten.
     *
     * @return array{0: string, 1: string|null} the stored paths
     */
    private function storeFiles(CreateDocumentInput $input, string $directory): array
    {
        return $this->workspace->run(function (PdfSession $session) use ($input, $directory): array {
            $accepted = $this->intake->accept($input->file, $session);
            $disk = Storage::disk(self::DISK);

            $original = $disk->putFileAs($directory, new File($accepted->printablePath), 'original.pdf');
            $uploaded = $accepted->uploadedPath === null
                ? null
                : $disk->putFileAs($directory, new File($accepted->uploadedPath), 'uploaded.pdf');

            // The disk answers false to a write it could not make; a path of
            // '' or a missing uploaded copy must never be saved as success.
            if ($original === false || ($accepted->uploadedPath !== null && $uploaded === false)) {
                throw new \RuntimeException('The document file could not be written.');
            }

            return [(string) $original, $uploaded === null ? null : (string) $uploaded];
        });
    }
}
