<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

use App\Modules\Approvals\Enums\StepResolver;

/**
 * One step of a route, before it is resolved to a person: a resolver and its
 * argument (design §4).
 */
final class StepDefinition
{
    private function __construct(
        public readonly StepResolver $resolver,
        public readonly ?string $ref = null,
        public readonly bool $decisive = false,
    ) {}

    public static function directManager(): self
    {
        return new self(StepResolver::DirectManager);
    }

    /**
     * The manager of the unit with this `departments.code`.
     */
    public static function unitManager(string $unitCode): self
    {
        return new self(StepResolver::UnitManager, $unitCode);
    }

    public static function namedEmployee(int $employeeId): self
    {
        return new self(StepResolver::NamedEmployee, (string) $employeeId);
    }

    /**
     * Under the advisory policy, this step's decision ends the approval
     * (legacy's chief).
     */
    public function decisive(): self
    {
        return new self($this->resolver, $this->ref, true);
    }
}
