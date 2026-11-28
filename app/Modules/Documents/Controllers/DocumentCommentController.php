<?php

declare(strict_types=1);

namespace App\Modules\Documents\Controllers;

use App\Modules\Documents\Exceptions\DocumentDeletedException;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentComment;
use App\Modules\Documents\Requests\DocumentCommentRequest;
use App\Modules\HR\Models\Employee;
use App\Modules\Me\Services\SelfEmployee;
use App\Shared\Traits\ApiRespond;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The thread on a document (documents design §7.5): any party but the
 * recipient of a sent document may read it and add to it. Who is a party is
 * the route's `can:comment,document`; the recipient is also refused here, so
 * not even the administrator's bypass lets them in (§5, call 5).
 */
class DocumentCommentController extends Controller
{
    use ApiRespond;

    /**
     * GET /api/documents/{document}/comments — oldest first, not paged (a
     * document's thread is short).
     */
    public function index(Request $request, Document $document): JsonResponse
    {
        $this->refuseTheRecipient($request, $document);
        $this->refuseIfDeleted($document);

        $comments = $document->comments()
            ->with(['employee' => static fn ($employee) => $employee->withTrashed()])
            ->get();

        return $this->success($comments->map($this->present(...))->all());
    }

    /**
     * POST /api/documents/{document}/comments — the author is the caller's
     * own employee, never an id in the request.
     */
    public function store(DocumentCommentRequest $request, Document $document, SelfEmployee $self): JsonResponse
    {
        $this->refuseTheRecipient($request, $document);
        $this->refuseIfDeleted($document);

        $employee = $self->of($request->user());

        $comment = $document->comments()->create([
            'employee_id' => $employee->id,
            'body'        => $request->body(),
        ]);
        $comment->setRelation('employee', $employee);

        return $this->created(
            data: $this->present($comment),
            message: 'تمت إضافة التعليق.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DocumentComment $comment): array
    {
        $author = $comment->employee;

        return [
            'id'         => $comment->id,
            'body'       => $comment->body,
            'author'     => $author instanceof Employee ? ['id' => $author->id, 'full_name' => $author->full_name] : null,
            'created_at' => $comment->created_at?->toIso8601String(),
        ];
    }

    private function refuseTheRecipient(Request $request, Document $document): void
    {
        if ($document->isRecipient($request->user())) {
            throw new AuthorizationException('ليس لديك صلاحية لتنفيذ هذا الإجراء.');
        }
    }

    private function refuseIfDeleted(Document $document): void
    {
        if ($document->trashed()) {
            throw new DocumentDeletedException;
        }
    }
}
