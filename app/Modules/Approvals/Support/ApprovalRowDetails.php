<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

use App\Modules\Approvals\Contracts\Approvable;
use App\Modules\Approvals\Models\ApprovalStep;
use Illuminate\Support\Collection;

/**
 * Fills each inbox row's `details` before the page renders: the resource must
 * not query, and each subject type answers for its whole page at once. Found
 * from the subjects themselves, so a new consumer needs no edit here.
 */
final class ApprovalRowDetails
{
    /**
     * @param  Collection<int, ApprovalStep>  $steps  each with its approval's subject loaded
     * @param  bool  $decided  the rows are what the caller decided
     */
    public static function attach(Collection $steps, bool $decided): void
    {
        $subjects = $steps
            ->map(static fn (ApprovalStep $step) => $step->approval->approvable)
            ->filter(static fn ($subject): bool => $subject instanceof Approvable);

        foreach ($subjects->groupBy(static fn (Approvable $subject): string => $subject::class) as $class => $group) {
            $details = $class::approverRowDetails($group, $decided);

            foreach ($steps as $step) {
                $subject = $step->approval->approvable;

                if ($subject instanceof $class) {
                    $step->rowDetails = $details[$subject->getKey()] ?? null;
                }
            }
        }
    }
}
