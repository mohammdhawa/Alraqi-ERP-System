<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Resources;

use App\Modules\Approvals\Contracts\Approvable;
use App\Modules\Approvals\Models\Approval;

/**
 * The request an approval is for, as the inbox and the request screen embed
 * it: its morph alias and id — what a client opens, the same pair a
 * notification's `reference` carries — and its consumer's summary
 * (2026-10-18).
 *
 * `type` and `id` come from the approval's own columns, so they are there
 * even when the subject is not (removed since): title and summary are then
 * null rather than the keys missing (C31). Reads the `approvable` its caller
 * eager-loaded (ApprovalRelations).
 */
final class ApprovalSubjectStub
{
    /**
     * @return array{type: string, id: int, title: ?string, summary: ?string}
     */
    public static function of(Approval $approval): array
    {
        $subject = $approval->approvable;
        $summary = $subject instanceof Approvable ? $subject->approvalSummary() : null;

        return [
            'type'    => (string) $approval->approvable_type,
            'id'      => (int) $approval->approvable_id,
            'title'   => $summary?->title,
            'summary' => $summary?->summary,
        ];
    }
}
