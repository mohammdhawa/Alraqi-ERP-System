<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Exceptions\SelfieNotFoundException;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;

/**
 * Which stored selfie a caller may see (Q11). There is no public URL; the
 * controller streams the path returned here.
 *
 * - The employee: their OWN records only. A colleague's record id answers 404
 *   — whether it exists is not theirs to learn.
 * - A reviewer: any record the route's `can:view` let through.
 */
final class SelfieAccess
{
    public function __construct(
        private readonly AttendeeResolver $attendees,
    ) {}

    /**
     * @throws ModelNotFoundException not one of the caller's records
     * @throws SelfieNotFoundException
     */
    public function own(User $user, int $recordId): string
    {
        $record = AttendanceRecord::forEmployee((int) $this->attendees->employeeOf($user)->getKey())->findOrFail($recordId);

        return $this->of($record);
    }

    /**
     * @throws SelfieNotFoundException no selfie, or its file is gone
     */
    public function of(AttendanceRecord $record): string
    {
        $path = $record->selfie_path;

        if ($path === null || ! Storage::disk(SelfieStore::DISK)->exists($path)) {
            throw new SelfieNotFoundException;
        }

        return $path;
    }
}
