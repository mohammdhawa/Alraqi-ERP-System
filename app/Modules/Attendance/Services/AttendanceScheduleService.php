<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Support\AttendanceCalendar;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * The attendance records' housekeeping: the selfie purge the decision round
 * added (Q11). The check-out reminder and the day close moved to the shift
 * clock (AttendanceShiftClock, work shifts spec §8). Legacy's monthly
 * cleanup — which deleted every record older than a month — is NOT ported
 * (D7): payroll reads these rows.
 *
 * The purge writes in bulk, so it adds no audit row per record: the row
 * itself says what happened (`selfie_purged_at`).
 */
class AttendanceScheduleService
{
    public function __construct(
        private readonly AttendanceCalendar $calendar,
    ) {}

    /**
     * Delete the selfie FILES of records older than the retention (three
     * months by default, from the start of today) and say so on each row. The
     * records themselves stay forever. A file already gone is marked all the
     * same, so the row never points at nothing.
     *
     * @return int records purged
     */
    public function purgeSelfies(): int
    {
        $months = max(1, (int) config('attendance.selfie_retention_months', 3));
        $cutoff = Carbon::now($this->calendar->timezone())->startOfDay()->subMonthsNoOverflow($months);
        $disk = Storage::disk(SelfieStore::DISK);
        $purged = 0;

        AttendanceRecord::query()
            ->whereNotNull('selfie_path')
            ->where('recorded_at', '<', $cutoff)
            ->select(['id', 'selfie_path'])
            ->chunkById(200, function (Collection $records) use ($disk, &$purged): void {
                $disk->delete($records->pluck('selfie_path')->all());

                $purged += AttendanceRecord::query()
                    ->whereKey($records->modelKeys())
                    ->update(['selfie_path' => null, 'selfie_purged_at' => Carbon::now($this->calendar->timezone())]);
            });

        return $purged;
    }
}
