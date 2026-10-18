<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Contracts;

use App\Modules\Approvals\Enums\ApproverNotice;
use App\Modules\Approvals\Models\Approval;
use App\Modules\Approvals\Support\ApproverMessage;
use App\Modules\HR\Models\Employee;

/**
 * What a subject implements to be approved by the engine (design §5).
 *
 * Implemented by an Eloquent model with a morph alias: the approval points at
 * it, and the approver's notification references it, so the tap opens the
 * subject's own screen.
 *
 * The engine owns the steps and the approval's status; the subject owns its
 * own status, and `onApprovalDecided()` is the ONLY place it is written.
 */
interface Approvable
{
    /**
     * Whose request this is. The chain resolves from this person.
     */
    public function approvalRequester(): Employee;

    /**
     * Asked before the approval that would complete it is recorded. Null to
     * allow it, or why not, in Arabic — the approver sees it as a 422 and
     * nothing changes. Leave answers with legacy's balance re-check.
     */
    public function canBeApproved(): ?string;

    /**
     * The approval reached approved, rejected or cancelled. Runs inside the
     * engine's transaction, so the subject's status and the approval's commit
     * together or not at all. The consumer sends its own receipts and outcomes
     * from here, in its own words.
     */
    public function onApprovalDecided(Approval $approval): void;

    /**
     * The words of a notification to an approver, for each moment the engine
     * notifies one.
     */
    public function describeForApprover(ApproverNotice $notice): ApproverMessage;
}
