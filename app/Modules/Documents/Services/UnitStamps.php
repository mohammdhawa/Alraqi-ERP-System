<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Models\UnitStamp;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Units' official stamps (documents design §10.2): uploaded by the system
 * administrator alone (D23.1), stored encrypted (I.9), every version kept.
 * A replacement never changes a document already stamped (I.10): the old
 * version stays readable, marked replaced.
 *
 * WHO is the route's: `can:documents.stamps.manage`.
 */
final class UnitStamps
{
    public const DIRECTORY = 'stamps';

    public function __construct(private readonly SealedImages $sealed) {}

    public function current(Department $unit): ?UnitStamp
    {
        return UnitStamp::query()
            ->where('department_id', $unit->getKey())
            ->whereNull('replaced_at')
            ->latest('id')
            ->first();
    }

    /**
     * The cache key set while the system administrators have been told this
     * unit has no stamp (documents design §7.2): they are told once per unit,
     * until a stamp is uploaded.
     */
    public static function missingKey(Department $unit): string
    {
        return "documents.stamp-missing.{$unit->getKey()}";
    }

    public function replace(Department $unit, UploadedFile $image, User $by): UnitStamp
    {
        $path = $this->sealed->put((string) file_get_contents((string) $image->getRealPath()), self::DIRECTORY);

        try {
            $stamp = DB::transaction(function () use ($unit, $image, $by, $path): UnitStamp {
                // Two uploads at once would otherwise both stay current.
                Department::query()->whereKey($unit->getKey())->lockForUpdate()->first();

                $this->current($unit)?->update(['replaced_at' => now()]);

                return UnitStamp::create([
                    'department_id' => $unit->getKey(),
                    'path'          => $path,
                    'mime_type'     => (string) $image->getMimeType(),
                    'uploaded_by'   => $by->getKey(),
                ]);
            });
        } catch (Throwable $e) {
            $this->sealed->delete($path);

            throw $e;
        }

        Cache::forget(self::missingKey($unit));

        return $stamp;
    }

    public function bytes(UnitStamp $stamp): string
    {
        return $this->sealed->get($stamp->path);
    }

    /**
     * Every non-deleted unit with no current stamp, the root first.
     *
     * @return Collection<int, Department>
     */
    public function unitsWithout(): Collection
    {
        // A subquery, not a relation: Departments knows nothing of stamps.
        return Department::query()
            ->whereNotIn('id', UnitStamp::query()->whereNull('replaced_at')->select('department_id'))
            ->orderBy('level')
            ->orderBy('id')
            ->get();
    }
}
