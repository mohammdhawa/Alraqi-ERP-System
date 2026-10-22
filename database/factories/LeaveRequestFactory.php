<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Enums\LeaveOrigin;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A two-day pending annual request, by default. The types are the nine the
 * migration seeds, so `forType()` picks one by code; `deducts_balance` is the
 * type's at creation — the snapshot a real filing takes.
 *
 * @extends Factory<LeaveRequest>
 */
class LeaveRequestFactory extends Factory
{
    protected $model = LeaveRequest::class;

    public function definition(): array
    {
        return [
            'employee_id'     => Employee::factory(),
            'leave_type_id'   => fn (): int => LeaveType::where('code', 'annual')->value('id'),
            'origin'          => LeaveOrigin::Request,
            'deducts_balance' => fn (array $attributes): bool => (bool) LeaveType::whereKey($attributes['leave_type_id'])->value('deducts_balance'),
            'reason'          => null,
            'start_date'      => '2026-06-21',
            'end_date'        => '2026-06-22',
            'requested_days'  => 2,
            'status'          => LeaveStatus::Pending,
        ];
    }

    public function forType(string $code): static
    {
        return $this->state(fn (): array => ['leave_type_id' => LeaveType::where('code', $code)->value('id')]);
    }

    public function approved(): static
    {
        return $this->state(fn (): array => ['status' => LeaveStatus::Approved, 'decided_at' => now()]);
    }

    /** An HR excuse: approved when filed, no chain. */
    public function excuse(): static
    {
        return $this->state(fn (): array => ['origin' => LeaveOrigin::HrExcuse, 'status' => LeaveStatus::Approved, 'decided_at' => now()]);
    }
}
