<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

/**
 * One broken grant-scope rule: the field it belongs to and the Arabic message.
 */
final class GrantScopeViolation
{
    public function __construct(
        public readonly string $field,
        public readonly string $message,
    ) {}
}
