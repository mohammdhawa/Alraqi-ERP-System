<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Contracts\ApprovedLeave;
use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Enums\AttendanceRecordStatus;
use App\Modules\Attendance\Enums\AttendanceSource;
use App\Modules\Attendance\Exceptions\AttendanceException;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Recording a check-in or a check-out — legacy's AttendanceService::record()
 * and its assertCanCheckIn / assertCanCheckOut, with legacy's rules and
 * messages unchanged (work order §4.5; docs/ATTENDANCE_BUILD.md):
 *
 * - check-in: a working day, inside the window, one per day (a completed day
 *   included — the single-shift model), not on approved leave;
 * - check-out: a standing check-in that day, after it, and at least the
 *   minimum gap after it; the day's hours are written on the check-out row;
 * - a refused (rejected) event does not count for any of these.
 *
 * Added by the decision round: a retried submission carrying the same
 * client_uuid returns the record already saved (Q12), and the selfie is
 * stored privately (Q11).
 */
class AttendanceService
{
    public function __construct(
        private readonly AttendanceCalendar $calendar,
        private readonly ApprovedLeave $leave,
        private readonly AttendeeResolver $attendees,
        private readonly SelfieStore $selfies,
    ) {}

    /**
     * The caller records for themselves, online.
     *
     * @param  array{type: int, latitude: float|string, longitude: float|string, recorded_at: string, client_uuid?: string|null}  $data
     */
    public function recordFor(User $user, array $data, UploadedFile $selfie): RecordedAttendance
    {
        return $this->record($this->attendees->recordingEmployeeOf($user), $data, $selfie, AttendanceSource::Online);
    }

    /**
     * Validate and save one event for $employee.
     *
     * THE LOCK. "One check-in a day" and "a check-out needs a check-in" are
     * read-then-write, so a double tap or a retry could race two inserts past
     * them. Locking the employee row makes the second request wait and then
     * see the first. It is also what makes the client_uuid lookup safe; the
     * unique index is the backstop.
     *
     * THE SELFIE is stored only once the rules have passed and the client_uuid
     * has not been seen, and is deleted if anything after that fails — so a
     * refused or replayed event never leaves a file behind.
     *
     * @param  array{type: int, latitude: float|string, longitude: float|string, recorded_at: string, client_uuid?: string|null}  $data
     *
     * @throws AttendanceException when a rule refuses the event
     */
    public function record(Employee $employee, array $data, ?UploadedFile $selfie, AttendanceSource $source): RecordedAttendance
    {
        $stored = null;

        try {
            return DB::transaction(function () use ($employee, $data, $selfie, $source, &$stored): RecordedAttendance {
                Employee::whereKey($employee->getKey())->lockForUpdate()->first();

                $uuid = $data['client_uuid'] ?? null;
                if ($uuid !== null && ($saved = $this->savedUnder($employee, $uuid)) !== null) {
                    return new RecordedAttendance($saved, created: false);
                }

                $type = AttendanceEventType::from((int) $data['type']);
                $moment = $this->calendar->parse($data['recorded_at']);
                $workHours = null;

                // A phone whose clock runs ahead (parity audit S-6).
                if ($moment->gt(Carbon::now($this->calendar->timezone())->addMinutes($this->calendar->maxClockAheadMinutes()))) {
                    throw new AttendanceException('وقت التسجيل لا يمكن أن يكون في المستقبل.');
                }

                if ($type === AttendanceEventType::CheckIn) {
                    $this->assertCanCheckIn($employee, $moment);
                } else {
                    $checkIn = $this->assertCanCheckOut($employee, $moment);
                    // From the check-in to the check-out: Carbon 3's difference
                    // is signed, so the order matters (legacy's Carbon 2 was not).
                    $workHours = round($checkIn->recorded_at->floatDiffInRealHours($moment), 2);

                    // An offline check-out arriving after the day closed: the
                    // check-in is no longer missing one, so the reports agree
                    // on the day (parity audit S-2; legacy left the flag).
                    if ($checkIn->status === AttendanceRecordStatus::MissingCheckout) {
                        $checkIn->update(['status' => null]);
                    }
                }

                $stored = $selfie === null ? null : $this->selfies->store($selfie, (int) $employee->getKey(), $moment);

                return new RecordedAttendance(AttendanceRecord::create([
                    'employee_id' => $employee->getKey(),
                    'type'        => $type,
                    'source'      => $source,
                    // Normalised to the attendance timezone, so the stored wall
                    // clock, the day the rules judged and every date filter agree
                    // even when the phone sent an offset ("…Z").
                    'recorded_at' => $moment,
                    'work_hours'  => $workHours,
                    'latitude'    => $data['latitude'],
                    'longitude'   => $data['longitude'],
                    'selfie_path' => $stored,
                    'client_uuid' => $uuid,
                ]), created: true);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two submissions of one client_uuid on different connections both
            // passed the lookup: the other one won. Answer with its row.
            $this->selfies->delete($stored);
            $saved = isset($data['client_uuid']) ? $this->savedUnder($employee, $data['client_uuid']) : null;

            return $saved !== null ? new RecordedAttendance($saved, created: false) : throw $e;
        } catch (Throwable $e) {
            $this->selfies->delete($stored);

            throw $e;
        }
    }

    /**
     * @throws AttendanceException
     */
    private function assertCanCheckIn(Employee $employee, Carbon $moment): void
    {
        if (! $this->calendar->isWorkingDay($moment)) {
            // Does not name the day: the calendar is configuration, so the
            // message stays true whatever the policy becomes.
            throw new AttendanceException('لا يمكن تسجيل الحضور في يوم عطلة.');
        }

        $start = $this->calendar->checkInStart();
        $end = $this->calendar->checkInEnd();

        if ($start !== '' && $moment->lt($this->calendar->timeOn($moment, $start))) {
            throw new AttendanceException("لا يمكن تسجيل الحضور قبل الساعة {$start}.");
        }
        if ($end !== '' && $moment->gt($this->calendar->timeOn($moment, $end))) {
            throw new AttendanceException("لا يمكن تسجيل الحضور بعد الساعة {$end}.");
        }

        $date = $moment->toDateString();

        // One check-in a day, even after a completed day: a second open shift
        // is one the reports cannot pair.
        if ($this->accepted($employee)->checkIns()->onDate($date)->exists()) {
            throw new AttendanceException('لديك تسجيل حضور قائم بالفعل لهذا اليوم.');
        }

        if (! $this->calendar->allowsAttendanceDuringLeave() && $this->leave->covers((int) $employee->getKey(), $date)) {
            throw new AttendanceException('لا يمكن تسجيل الحضور أثناء إجازة معتمدة لهذا اليوم.');
        }
    }

    /**
     * @return AttendanceRecord the check-in this check-out closes
     *
     * @throws AttendanceException
     */
    private function assertCanCheckOut(Employee $employee, Carbon $moment): AttendanceRecord
    {
        $date = $moment->toDateString();
        $checkIn = $this->accepted($employee)->checkIns()->onDate($date)->orderBy('recorded_at')->first();
        $checkedOut = $this->accepted($employee)->checkOuts()->onDate($date)->exists();

        if ($checkIn === null || $checkedOut) {
            throw new AttendanceException('لا يمكن تسجيل الانصراف بدون تسجيل حضور صالح لنفس اليوم.');
        }

        if ($moment->lte($checkIn->recorded_at)) {
            throw new AttendanceException('وقت الانصراف يجب أن يكون بعد وقت الحضور.');
        }

        // Judged on the two events' own times, not on "now", so an offline
        // submission is measured by its real gap. The boundary is allowed.
        $gap = $this->calendar->minCheckoutGapMinutes();
        if ($gap > 0 && $moment->lt($checkIn->recorded_at->copy()->addMinutes($gap))) {
            throw new AttendanceException("لا يمكن تسجيل الانصراف قبل مرور {$gap} دقيقة على الحضور.");
        }

        return $checkIn;
    }

    /** @return Builder<AttendanceRecord> */
    private function accepted(Employee $employee)
    {
        return AttendanceRecord::forEmployee((int) $employee->getKey())->accepted();
    }

    private function savedUnder(Employee $employee, string $uuid): ?AttendanceRecord
    {
        return AttendanceRecord::forEmployee((int) $employee->getKey())->where('client_uuid', $uuid)->first();
    }
}
