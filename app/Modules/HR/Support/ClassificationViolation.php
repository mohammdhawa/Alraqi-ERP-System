<?php

declare(strict_types=1);

namespace App\Modules\HR\Support;

/**
 * ClassificationViolation
 *
 * A single broken employee invariant: which field it concerns and the Arabic
 * message describing it. EmployeeClassificationGuard returns a list of these,
 * and each consumer renders them in its own idiom:
 *
 *   - EmployeeRequest pushes each one onto the validator ($field => $message),
 *     producing the standard 422 field-level response.
 *   - The Employee model's saving hook throws EmployeeClassificationException
 *     carrying the first message.
 *
 * The `field` names map to request input keys (`engineering_rank`,
 * `agreed_salary`) so the front-door 422 UX is preserved exactly.
 *
 * Deliberately identical in shape to the Departments module's
 * HierarchyViolation. The two are not merged into one shared class because
 * nothing yet needs them to be, and a shared abstraction built for two callers
 * that never grow to three is a dependency between modules bought for nothing.
 */
final class ClassificationViolation
{
    public function __construct(
        public readonly string $field,
        public readonly string $message,
    ) {}
}
