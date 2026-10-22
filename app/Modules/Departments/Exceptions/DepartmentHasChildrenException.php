<?php

declare(strict_types=1);

namespace App\Modules\Departments\Exceptions;

use App\Shared\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a department that still has non-trashed children is deleted.
 *
 * WHY this exists as a real exception and not a boolean return:
 * - The guard lives in a model `deleting` hook, which can only stop a delete
 *   by returning false (silent, no reason) or by throwing. Silent failure on
 *   a foundation table is worse than an error.
 * - Every caller — controller, service, seeder, tinker, a future module — gets
 *   the same refusal and the same Arabic reason.
 *
 * WHY IT NO LONGER RENDERS ITSELF: it used to carry a render() that hand-built
 * the envelope, to avoid needing an edit to bootstrap/app.php per module. That
 * goal was right; the means duplicated the envelope across five classes and put
 * HTTP knowledge into code thrown from a model boot hook (C16, C17 — GAP-4).
 * Implementing DomainException keeps the zero-config property — the central
 * renderer matches the INTERFACE, so no bootstrap edit is needed — while
 * leaving exactly one place that knows what an envelope looks like.
 */
class DepartmentHasChildrenException extends \RuntimeException implements DomainException
{
    public function __construct(
        string $message = 'لا يمكن حذف وحدة تنظيمية تحتوي على وحدات فرعية. الرجاء نقل أو حذف الوحدات الفرعية أولًا.',
        public readonly int $statusCode = Response::HTTP_CONFLICT,
    ) {
        parent::__construct($message);
    }

    /**
     * The status this failure maps to, as data (C17). ApiExceptionRenderer
     * turns it into a response; this class never does.
     */
    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
