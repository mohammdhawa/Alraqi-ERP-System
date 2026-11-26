<?php

declare(strict_types=1);

namespace App\Modules\Documents\Controllers;

use App\Modules\Approvals\Enums\Decision;
use App\Modules\Approvals\Enums\StepStatus;
use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Documents\Exceptions\DocumentDeletedException;
use App\Modules\Documents\Exceptions\ViewAllDocumentsForbiddenException;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Requests\CloseDocumentRequest;
use App\Modules\Documents\Requests\CreateDocumentRequest;
use App\Modules\Documents\Requests\DecideDocumentRequest;
use App\Modules\Documents\Requests\DocumentsIndexRequest;
use App\Modules\Documents\Resources\DocumentResource;
use App\Modules\Documents\Services\DocumentClosing;
use App\Modules\Documents\Services\DocumentCreation;
use App\Modules\Documents\Services\DocumentDecisions;
use App\Modules\Documents\Services\DocumentDeletion;
use App\Modules\Documents\Services\DocumentsNeedingAttention;
use App\Modules\Me\Services\SelfEmployee;
use App\Shared\Models\Attachment;
use App\Shared\Services\AttachmentStore;
use App\Shared\Traits\ApiRespond;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documents between managers (documents design §7, §16).
 */
class DocumentController extends Controller
{
    use ApiRespond;

    /**
     * GET /api/documents?box=incoming|outgoing|all — the incoming box leads
     * with the documents whose turn is the caller's (§7.7).
     */
    public function index(DocumentsIndexRequest $request): JsonResponse
    {
        $user = $request->user();
        $employeeId = $user->employee_id === null ? null : (int) $user->employee_id;
        $box = $request->box();

        $documents = Document::query();

        match ($box) {
            'incoming' => $documents->whereHas('approval.steps', static fn (Builder $steps) => $steps->where('approver_id', $employeeId ?? 0)),
            'outgoing' => $documents->where('documents.creator_id', $employeeId ?? 0),
            default    => $user->hasPermission('documents.view') ? null : throw new ViewAllDocumentsForbiddenException,
        };

        if (($status = $request->status()) !== null) {
            $documents->where('documents.status', $status->value);
        }

        if (($search = $request->search()) !== null) {
            $documents->matching($search);
        }

        if ($box === 'incoming' && $employeeId !== null) {
            $documents->orderByRaw($this->myTurnSql().' desc', [
                (new Document)->getMorphClass(),
                $employeeId,
                StepStatus::Pending->value,
                StepStatus::Pending->value,
            ]);
        }

        $page = $documents
            ->orderByDesc('documents.id')
            ->with(DocumentResource::RELATIONS)
            ->paginate($this->perPage($request));

        return $this->paginated(DocumentResource::collection($page));
    }

    /**
     * GET /api/documents/{document} — one document, to its parties and to
     * `documents.view` (the route's `can:view`); a deleted one answers 410.
     */
    public function show(Request $request, Document $document): JsonResponse
    {
        $this->refuseIfDeleted($document);

        return $this->success(DocumentResource::make($this->withRelations($document))->resolve($request));
    }

    /**
     * GET /api/documents/{document}/file — the printed copy once a decision
     * has made one, else the original; never cached.
     */
    public function file(Document $document): StreamedResponse
    {
        $this->refuseIfDeleted($document);

        $path = $document->printed_path ?? $document->original_path;
        $disk = Storage::disk('local');

        abort_if($path === null || ! $disk->exists($path), 404);

        return $disk->response($path, headers: [
            'Content-Type'  => 'application/pdf',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * GET /api/documents/{document}/attachments/{attachment} — a supporting
     * file, streamed and never cached. Bound scoped to the document: another
     * document's file is a 404.
     */
    public function attachment(Document $document, Attachment $attachment, AttachmentStore $store): StreamedResponse
    {
        $this->refuseIfDeleted($document);

        return $store->stream($attachment);
    }

    /**
     * POST /api/documents — send a document for signing. The creator is the
     * caller's own employee, never an id in the request.
     */
    public function store(CreateDocumentRequest $request, SelfEmployee $self, DocumentCreation $creation): JsonResponse
    {
        $user = $request->user();

        $document = $creation->create($self->of($user), $user, $request->toInput());

        return $this->created(
            data: DocumentResource::make($this->withRelations($document))->resolve($request),
            message: 'تم إرسال المستند للاعتماد.',
        );
    }

    /**
     * POST /api/documents/{document}/decision — approve or reject the
     * caller's current step, with its card and the rebuilt printed copy.
     */
    public function decide(DecideDocumentRequest $request, Document $document, DocumentDecisions $decisions): JsonResponse
    {
        $this->refuseIfDeleted($document);

        $decision = $request->decision();

        $document = $decisions->decide(
            $document,
            $request->user(),
            $decision,
            $request->note(),
            $request->confirmation(),
            $request->password(),
        );

        return $this->success(
            data: DocumentResource::make($this->withRelations($document))->resolve($request),
            message: $decision === Decision::Approve ? 'تمت الموافقة.' : 'تم الرفض.',
        );
    }

    /**
     * DELETE /api/documents/{document} — the creator's or the administrator's,
     * before any signature. A document already deleted answers 410.
     */
    public function destroy(Request $request, Document $document, DocumentDeletion $deletion): JsonResponse
    {
        $this->refuseIfDeleted($document);

        $deletion->delete($document, $request->user());

        return $this->success(message: 'تم حذف المستند.');
    }

    /**
     * POST /api/documents/{document}/close — the system administrator closes
     * a pending document «لم يُستكمل» (§7.8). A deleted one answers 410.
     */
    public function close(CloseDocumentRequest $request, Document $document, DocumentClosing $closing): JsonResponse
    {
        $this->refuseIfDeleted($document);

        $document = $closing->close($document, $request->user(), $request->reason());

        return $this->success(
            data: DocumentResource::make($this->withRelations($document))->resolve($request),
            message: 'أُغلق المستند: لم يُستكمل.',
        );
    }

    /**
     * GET /api/documents/attention — the system administrator's list of the
     * pending documents no one can move as they stand, each with why (§7.8).
     */
    public function attention(Request $request, DocumentsNeedingAttention $attention): JsonResponse
    {
        $page = $attention->paginate($this->perPage($request));

        $page->through(static fn (array $row): array => DocumentResource::make($row['document'])->resolve($request) + [
            'attention' => [
                'reason'   => $row['reason'],
                'step_id'  => $row['step']->id,
                'approver' => $row['step']->approver === null ? null : [
                    'id'        => $row['step']->approver->id,
                    'full_name' => $row['step']->approver->full_name,
                ],
            ],
        ]);

        return $this->paginated(JsonResource::collection($page));
    }

    private function refuseIfDeleted(Document $document): void
    {
        if ($document->trashed()) {
            throw new DocumentDeletedException;
        }
    }

    /**
     * Whether the caller holds a pending step at the approval's current
     * order, as the engine reads it: the lowest order still pending.
     * Bindings: the morph alias, the caller, then the pending status twice.
     */
    private function myTurnSql(): string
    {
        $steps = (new ApprovalStep)->getTable();

        return "exists (select 1 from approvals a join {$steps} s on s.approval_id = a.id"
            .' where a.approvable_type = ? and a.approvable_id = documents.id and a.deleted_at is null'
            .' and s.deleted_at is null and s.approver_id = ? and s.status = ?'
            ." and s.step_order = (select min(m.step_order) from {$steps} m"
            .' where m.approval_id = a.id and m.status = ? and m.deleted_at is null))';
    }

    private function withRelations(Document $document): Document
    {
        return $document->load(DocumentResource::RELATIONS);
    }
}
