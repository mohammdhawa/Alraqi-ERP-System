<?php

declare(strict_types=1);

namespace App\Modules\HR\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a job title that staff still hold — now or at some point in their
 * posting history — would be deleted.
 *
 * WHY THE GUARD IS NEEDED AT ALL, given both FKs are nullOnDelete: that is
 * exactly the problem. `restrictOnDelete` would refuse the delete, but these
 * columns are deliberately nullOnDelete so that dissolving a title does not
 * take its holders with it. The consequence is that a soft delete — which is an
 * UPDATE and fires no FK behaviour at all — would leave the title's rows intact
 * and pointing at a row every default query hides, so an employee's title would
 * silently render as blank and a payslip's historical posting would lose the
 * job it was computed for.
 *
 * So the refusal lives in the model's `deleting` hook, which every writer
 * crosses (C4), rather than being left to a constraint that cannot fire.
 *
 * 409, not 422: nothing about the request is malformed. It conflicts with
 * protected state — the same reasoning that makes deleting a system role a 409.
 */
class JobTitleInUseException extends \RuntimeException implements DomainException
{
    public function __construct(
        string $message = 'لا يمكن حذف المسمى الوظيفي لأنه مرتبط بموظفين أو بسجلات تعيين.',
        public readonly int $statusCode = Response::HTTP_CONFLICT,
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
