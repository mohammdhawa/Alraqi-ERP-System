<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

/**
 * The one door a document step's decision goes through (documents design
 * §7.3). A step is open only while the callable given to `through()` runs, so
 * the general approvals endpoint, which never opens it, cannot decide a
 * document's step by the back door.
 */
final class DecisionGate
{
    /** @var array<int, true> */
    private array $open = [];

    public function through(int $stepId, callable $work): mixed
    {
        $opened = ! isset($this->open[$stepId]);
        $this->open[$stepId] = true;

        try {
            return $work();
        } finally {
            if ($opened) {
                unset($this->open[$stepId]);
            }
        }
    }

    public function isOpen(int $stepId): bool
    {
        return isset($this->open[$stepId]);
    }
}
