<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

/**
 * What a request is, in its consumer's words — the line an approver reads in
 * the inbox and at the top of the request screen (2026-10-18): «إجازة سنوية»
 * over «3 أيام · 2026-10-20 → 2026-10-22». The engine shows it without
 * knowing what a leave is.
 */
final class ApprovalSummary
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $summary = null,
    ) {}
}
