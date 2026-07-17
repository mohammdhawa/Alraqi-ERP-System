<?php

declare(strict_types=1);

namespace App\Modules\Departments\Support;

/**
 * HierarchyViolation
 *
 * A single broken hierarchy invariant: which field it concerns and the Arabic
 * message describing it. DepartmentHierarchyGuard returns a list of these, and
 * each consumer renders them in its own idiom:
 *
 *   - Form requests push each one onto the validator ($field => $message),
 *     producing the standard 422 field-level response.
 *   - The Department model's saving hook throws DepartmentHierarchyException
 *     carrying the first message.
 *
 * The `field` names map to request input keys (`level`, `parent_id`) so the
 * front-door 422 UX is preserved exactly.
 */
final class HierarchyViolation
{
    public function __construct(
        public readonly string $field,
        public readonly string $message,
    ) {}
}
