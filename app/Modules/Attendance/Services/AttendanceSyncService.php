<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\AttendanceSource;
use App\Modules\Attendance\Exceptions\AttendanceException;
use App\Modules\Auth\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The offline queue — legacy's AttendanceController::sync(), moved into a
 * service (C2), with its behaviour unchanged:
 *
 * - each entry is recorded on its own, so one refusal does not block the rest;
 * - an entry without its selfie is refused as a business rule;
 * - only an AttendanceException's message — written for the employee — goes
 *   back to the client. Anything else carries SQL, bindings or server paths,
 *   so it is LOGGED with its context (the loop swallows it, so nothing else
 *   would) and reported generically.
 *
 * With Q12, an entry whose client_uuid is already saved comes back among the
 * succeeded, as the saved record.
 */
final class AttendanceSyncService
{
    private const GENERIC_FAILURE = 'تعذر حفظ هذا السجل، يرجى المحاولة لاحقاً.';

    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AttendeeResolver $attendees,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $entries  validated, in queue order
     * @param  array<int, UploadedFile|null>  $selfies  by entry index
     */
    public function sync(User $user, array $entries, array $selfies): SyncOutcome
    {
        $employee = $this->attendees->recordingEmployeeOf($user);
        $outcome = new SyncOutcome;

        foreach ($entries as $index => $entry) {
            try {
                $selfie = $selfies[$index] ?? null;
                if (! $selfie instanceof UploadedFile) {
                    throw new AttendanceException('الصورة الشخصية مطلوبة لكل سجل.');
                }

                $outcome->succeeded[] = $this->attendance->record($employee, $entry, $selfie, AttendanceSource::OfflineSync)->record;
            } catch (Throwable $e) {
                $forTheEmployee = $e instanceof AttendanceException;

                if (! $forTheEmployee) {
                    Log::error('Attendance sync entry failed', [
                        'employee_id' => $employee->getKey(),
                        'user_id'     => $user->getKey(),
                        'index'       => $index,
                        'exception'   => $e::class,
                        'error'       => $e->getMessage(),
                        'file'        => $e->getFile().':'.$e->getLine(),
                        'trace'       => array_slice($e->getTrace(), 0, 5),
                    ]);
                }

                $outcome->failed[] = [
                    'index' => $index,
                    'entry' => $entry,
                    'error' => $forTheEmployee ? $e->getMessage() : self::GENERIC_FAILURE,
                ];
            }
        }

        return $outcome;
    }
}
