<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a built-in system role (is_system, e.g. super_admin) is renamed,
 * edited, or deleted.
 *
 * WHY system roles are immutable through the app:
 * - super_admin is the RBAC bootstrap. Renaming it would silently break the
 *   Gate::before bypass (which matches on the name), and deleting it would leave
 *   nobody able to administer permissions. Protecting it only by a controller
 *   check on the literal name left a service/model path wide open, so the guard
 *   lives in RoleService — every mutation path crosses it.
 *
 * Mirrors DepartmentIsRootException: a domain exception carrying a 409 (the
 * request conflicts with the resource's protected state) as data, rendered
 * centrally. Assigning or unassigning a system role to a user is NOT blocked —
 * only mutating the role itself is.
 */
class SystemRoleException extends \RuntimeException implements DomainException
{
    public function __construct(
        string $message = 'لا يمكن تعديل أو حذف دور نظام.',
        public readonly int $statusCode = Response::HTTP_CONFLICT,
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
