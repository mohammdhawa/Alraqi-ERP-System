<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

use App\Modules\Approvals\Enums\RejectionPolicy;
use InvalidArgumentException;

/**
 * What a consumer submits: the rejection policy and the ordered stages of
 * step definitions (design §4). The steps in one stage share a `step_order`
 * and run in parallel; the stages run in sequence.
 *
 *   leave:     sequential(Ends, directManager(), unitManager('HR'))
 *   document:  stages(Advisory, [m1, m2], [chief->decisive()])   — parallel managers
 *
 * A malformed route is a programming error in the consumer, not a request
 * the user can fix, so it throws InvalidArgumentException rather than an
 * ApprovalException.
 */
final class ApprovalRoute
{
    /**
     * @param  array<int, array<int, StepDefinition>>  $stages
     */
    private function __construct(
        public readonly RejectionPolicy $policy,
        public readonly array $stages,
    ) {
        if ($stages === []) {
            throw new InvalidArgumentException('An approval route needs at least one stage.');
        }

        foreach ($stages as $stage) {
            if ($stage === []) {
                throw new InvalidArgumentException('An approval route stage needs at least one step.');
            }
        }

        if ($policy === RejectionPolicy::Advisory) {
            $this->assertOneDecisiveLastStep();
        }
    }

    /**
     * One step per stage, in order — leave's shape.
     */
    public static function sequential(RejectionPolicy $policy, StepDefinition ...$steps): self
    {
        return new self($policy, array_map(static fn (StepDefinition $step): array => [$step], array_values($steps)));
    }

    /**
     * Explicit stages, each a list of parallel steps.
     *
     * @param  array<int, StepDefinition>  ...$stages
     */
    public static function stages(RejectionPolicy $policy, array ...$stages): self
    {
        return new self($policy, array_map('array_values', array_values($stages)));
    }

    /**
     * Under the advisory policy the outcome is the decisive step's, so there
     * must be exactly one, and it must stand alone in the last stage — legacy's
     * chief, who acts only after every manager has.
     */
    private function assertOneDecisiveLastStep(): void
    {
        $last = $this->stages[array_key_last($this->stages)];

        if (count($last) !== 1 || ! $last[0]->decisive) {
            throw new InvalidArgumentException('An advisory route must end with one decisive step, alone in its stage.');
        }

        foreach (array_slice($this->stages, 0, -1) as $stage) {
            foreach ($stage as $step) {
                if ($step->decisive) {
                    throw new InvalidArgumentException('Only the last step of an advisory route may be decisive.');
                }
            }
        }
    }
}
