<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Documents\Models\DocumentReferenceSequence;
use Illuminate\Support\Facades\DB;

/**
 * A template document's reference, PREFIX-YEAR-SEQ (documents design §8.4).
 * The year is `now()`'s, in the app timezone. Knows nothing of approvals.
 */
final class ReferenceNumbers
{
    /** Must run inside a transaction; the sequence row is locked until it ends. */
    public function claim(string $prefix): string
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('ReferenceNumbers::claim must run inside a transaction.');
        }

        $year = now()->year;

        // upsert, not insertOrIgnore: on MySQL a duplicate INSERT IGNORE takes
        // a shared lock that deadlocks with the lockForUpdate below (1213);
        // the upsert's duplicate path takes an exclusive one. The update list
        // touches only the keys, never current_value.
        DB::table('document_reference_sequences')->upsert([
            'prefix'        => $prefix,
            'year'          => $year,
            'current_value' => 0,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], ['prefix', 'year'], ['prefix']);

        $sequence = DocumentReferenceSequence::query()
            ->where('prefix', $prefix)
            ->where('year', $year)
            ->lockForUpdate()
            ->firstOrFail();

        $next = $sequence->current_value + 1;
        $sequence->update(['current_value' => $next]);

        return sprintf('%s-%d-%04d', $prefix, $year, $next);
    }
}
