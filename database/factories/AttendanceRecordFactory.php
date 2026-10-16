<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Enums\AttendanceRecordStatus;
use App\Modules\Attendance\Enums\AttendanceSource;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A row as the service would have written it, for tests that need history in
 * place. Bypasses the service's rules on purpose: tests of those rules go
 * through the endpoints.
 *
 * @extends Factory<AttendanceRecord>
 */
class AttendanceRecordFactory extends Factory
{
    protected $model = AttendanceRecord::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'type'        => AttendanceEventType::CheckIn,
            'source'      => AttendanceSource::Online,
            'recorded_at' => '2026-06-21 08:00:00',
            'latitude'    => 33.5,
            'longitude'   => 36.3,
            'selfie_path' => null,
        ];
    }

    public function checkIn(): static
    {
        return $this->state(['type' => AttendanceEventType::CheckIn]);
    }

    public function checkOut(): static
    {
        return $this->state(['type' => AttendanceEventType::CheckOut, 'work_hours' => 8.0]);
    }

    public function missingCheckout(): static
    {
        return $this->state(['status' => AttendanceRecordStatus::MissingCheckout]);
    }
}
