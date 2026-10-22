<?php

declare(strict_types=1);

namespace App\Modules\Departments\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when the single root of the org chart (الإدارة العامة) is deleted.
 *
 * WHY the root is undeletable by any path:
 * - It is the trunk every escalation chain terminates at and every unit hangs
 *   from. Deleting it — soft OR hard — detaches the entire company, and unlike
 *   the children guard there is no "empty the subtree first" that makes it safe.
 * - The children guard would only block it incidentally (a populated root has
 *   children); a leaf root or an emptied tree would delete cleanly. So the root
 *   needs its own explicit guard in the model's `deleting` hook.
 *
 * Mirrors DepartmentHasChildrenException: a domain exception carrying a 409 (the
 * request conflicts with the resource's protected state) as data, covering every
 * delete path — controller, service, seeder, tinker, future modules. Rendering
 * belongs to ApiExceptionRenderer (C17).
 */
class DepartmentIsRootException extends \RuntimeException implements DomainException
{
    public function __construct(
        string $message = 'لا يمكن حذف الإدارة العامة، فهي الوحدة الجذر لهيكل الشركة.',
        public readonly int $statusCode = Response::HTTP_CONFLICT,
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
