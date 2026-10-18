<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

use App\Modules\Approvals\Contracts\Approvable;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * What every approvals response eager-loads, so its Resources never query
 * (C31) — one place, because the live read, the decisions, the withdrawal and
 * the reassignment all render the same approval.
 *
 * THE SUBJECTS ARE OF MANY TYPES. A page of the inbox holds leave requests
 * now and documents in November, each summarised through its own relations.
 * `morphWith` loads, per type, what that type's `approvalSummary()` reads —
 * found from the morph map, so a new consumer needs no edit here.
 */
final class ApprovalRelations
{
    /**
     * The live state of one approval (design §7).
     *
     * @return array<int|string, mixed>
     */
    public static function liveState(): array
    {
        return [
            'requester',
            'approvable'                         => self::subject(),
            'steps.approver',
            'steps.actedBy.employee',
            'reassignments.fromEmployee',
            'reassignments.toEmployee',
            'reassignments.performedBy.employee',
        ];
    }

    /**
     * An inbox page: each step's approval, its requester and its subject.
     *
     * @return array<int|string, mixed>
     */
    public static function inbox(): array
    {
        return [
            'approval.requester',
            'approval.approvable' => self::subject(),
        ];
    }

    private static function subject(): \Closure
    {
        return static function (MorphTo $subject): void {
            $relations = [];

            foreach (Relation::morphMap() as $class) {
                if (is_subclass_of($class, Approvable::class)) {
                    $relations[$class] = $class::approvalSummaryRelations();
                }
            }

            $subject->morphWith($relations);
        };
    }
}
