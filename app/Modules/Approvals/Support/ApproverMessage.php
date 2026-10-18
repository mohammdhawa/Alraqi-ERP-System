<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

/**
 * The words of a notification to an approver, in the consumer's language —
 * the engine sends it without knowing what a leave or a document is.
 */
final class ApproverMessage
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
    ) {}
}
