<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Exceptions\DocumentRouteException;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentCounter;
use Illuminate\Support\Facades\DB;

/**
 * The صادر numbers of an إدارة's register (documents design §8.4), with
 * legacy's counter rules. Knows nothing of approvals.
 *
 * Not final: the creation race test binds a subclass.
 */
class ExportNumbers
{
    /** The number the register gives next, or null while it is cold. */
    public function next(Department $register): ?int
    {
        $current = (int) DocumentCounter::query()
            ->where('unit_id', $register->getKey())
            ->value('current_value');

        return $current === 0 ? null : $current + 1;
    }

    /** Whether a document of the register, deleted ones included, carries the number. */
    public function isUsed(Department $register, int $number): bool
    {
        return Document::withTrashed()
            ->where('register_unit_id', $register->getKey())
            ->where('export_number', $number)
            ->exists();
    }

    /**
     * Claim a number: the typed one, or the counter's next. Must run inside a
     * transaction; the counter row is locked until it ends.
     *
     * @throws DocumentRouteException
     */
    public function claim(Department $register, ?int $typed): int
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('ExportNumbers::claim must run inside a transaction.');
        }

        if ($typed !== null && ($typed < 1 || $typed > 999999999)) {
            throw new DocumentRouteException('رقم الصادر يجب أن يكون بين 1 و 999999999.');
        }

        // upsert, not firstOrCreate: two first claims at once must not fail on
        // the unique insert. Not insertOrIgnore either: on MySQL a duplicate
        // INSERT IGNORE takes a SHARED lock on the existing row, and the
        // lockForUpdate below then deadlocks with the other waiters (1213).
        // The upsert's duplicate path takes an exclusive lock, so waiters run
        // one at a time. The update list touches only the key, never
        // current_value. Then the row is read under its lock.
        DB::table('document_counters')->upsert([
            'unit_id'       => $register->getKey(),
            'current_value' => 0,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], ['unit_id'], ['unit_id']);

        $counter = DocumentCounter::query()
            ->where('unit_id', $register->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ($typed === null) {
            if ($counter->current_value === 0) {
                throw new DocumentRouteException('هذا أول مستند صادر في الإدارة، يرجى إدخال رقم الصادر لتحديد نقطة البداية.');
            }

            $next = $counter->current_value + 1;

            if ($next > 999999999) {
                throw new DocumentRouteException('رقم الصادر يجب أن يكون بين 1 و 999999999.');
            }

            $counter->update(['current_value' => $next]);

            return $next;
        }

        // A plain read, on purpose. It can miss a number committed while this
        // claim waited on the counter lock (MySQL REPEATABLE READ snapshot).
        // The DB unique index (register_unit_id, export_number) is the
        // authority: it then refuses the document insert, and the creation
        // service (Task 6) turns that violation into «رقم الصادر {n} مستخدم في
        // سجل هذه الإدارة.». A locking read here takes gap locks on that index
        // that deadlock creators in neighbouring registers.
        if ($this->isUsed($register, $typed)) {
            throw new DocumentRouteException("رقم الصادر {$typed} مستخدم في سجل هذه الإدارة.");
        }

        // Above the counter it moves the counter; below, it is taken as is.
        if ($typed > $counter->current_value) {
            $counter->update(['current_value' => $typed]);
        }

        return $typed;
    }
}
