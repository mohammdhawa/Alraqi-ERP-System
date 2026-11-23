<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Resources\DocumentResource;
use App\Modules\HR\Models\Employee;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * The documents needing attention (documents design §7.8): the pending ones
 * no one can move as they stand, for the system administrator to reassign
 * or close. Each says why:
 *
 * - `former_general_manager` — the decider's pending step is held by someone
 *   who is no longer المدير العام (while the post is vacant, by anyone);
 * - `approver_manages_no_unit` — the step now asked is held by someone who
 *   manages no active unit, or whose employee record was deleted, so they
 *   cannot sign. A deleted المدير العام reads as a vacant post (the root's
 *   `manager` relation excludes deleted employees).
 *
 * A document that is both is listed once, as the first: the decider's step
 * goes to the new المدير العام either way.
 *
 * Read in PHP over the pending documents, which are few, so each rule is
 * written once: the `generalManager()` and `unitOf()` that signing and
 * reassigning ask.
 */
final class DocumentsNeedingAttention
{
    public const FORMER_GENERAL_MANAGER = 'former_general_manager';

    public const APPROVER_MANAGES_NO_UNIT = 'approver_manages_no_unit';

    public function __construct(private readonly UnitManagers $managers) {}

    /**
     * Newest first, each loaded for `DocumentResource`, with the step that
     * holds it and why.
     *
     * @return LengthAwarePaginator<int, array{document: Document, reason: string, step: ApprovalStep}>
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        $generalManager = $this->managers->generalManager();
        $generalManagerId = $generalManager === null ? null : (int) $generalManager->getKey();

        // One question per approver, however many documents they hold.
        $known = [];
        $managesAUnit = function (?Employee $approver) use (&$known): bool {
            return $approver !== null
                && ($known[(int) $approver->getKey()] ??= $this->managers->unitOf($approver) !== null);
        };

        $rows = Document::query()
            ->where('status', DocumentStatus::Pending)
            ->with('approval.steps.approver')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Document $document): ?array => $this->attentionOf($document, $generalManagerId, $managesAUnit))
            ->filter()
            ->values();

        $page = Paginator::resolveCurrentPage();
        $slice = $rows->forPage($page, $perPage)->values();

        (new Collection($slice->pluck('document')->all()))->loadMissing(DocumentResource::RELATIONS);

        return new LengthAwarePaginator($slice, $rows->count(), $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
        ]);
    }

    /**
     * @param  Closure(?Employee): bool  $managesAUnit
     * @return array{document: Document, reason: string, step: ApprovalStep}|null
     */
    private function attentionOf(Document $document, ?int $generalManagerId, Closure $managesAUnit): ?array
    {
        $approval = $document->approval;

        if ($approval === null || ! $approval->isPending()) {
            return null;
        }

        $pending = $approval->steps->filter(static fn (ApprovalStep $step): bool => $step->isPending());

        $decider = $pending->first(static fn (ApprovalStep $step): bool => $step->decisive
            && (int) $step->approver_id !== $generalManagerId);

        if ($decider !== null) {
            return ['document' => $document, 'reason' => self::FORMER_GENERAL_MANAGER, 'step' => $decider];
        }

        $order = $approval->currentOrder();

        $stuck = $pending->first(static fn (ApprovalStep $step): bool => $step->step_order === $order
            && ! $managesAUnit($step->approver));

        return $stuck === null ? null : ['document' => $document, 'reason' => self::APPROVER_MANAGES_NO_UNIT, 'step' => $stuck];
    }
}
