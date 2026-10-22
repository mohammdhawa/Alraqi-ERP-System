<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/me/attendance — the employee's own records (legacy's
 * /attendance/my-records), with legacy's filters: a day, a range, a month, a
 * year — today by default — then type, missing check-out, corrected, refused
 * and an hours range. Sorts are allow-listed; an unknown one is a 422 (C30).
 */
class MyAttendanceRecordsTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-21 18:00:00');

        $this->employee = Employee::factory()->create();
        Sanctum::actingAs(User::factory()->create(['employee_id' => $this->employee->id, 'must_change_password' => false]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function day(string $date, ?Employee $employee = null, float $hours = 8.0): void
    {
        $employee ??= $this->employee;
        AttendanceRecord::factory()->checkIn()->create(['employee_id' => $employee->id, 'recorded_at' => "{$date} 08:00:00"]);
        AttendanceRecord::factory()->checkOut()->create(['employee_id' => $employee->id, 'recorded_at' => "{$date} 16:00:00", 'work_hours' => $hours]);
    }

    /** @param  array<string, mixed>  $query */
    private function list(array $query = []): TestResponse
    {
        return $this->getJson('/api/me/attendance?'.http_build_query($query));
    }

    public function test_only_the_callers_own_records_are_listed(): void
    {
        $this->day('2026-06-21');
        $this->day('2026-06-21', Employee::factory()->create());

        $this->list()->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
        $this->assertSame([$this->employee->id], array_unique(array_column($this->list()->json('data'), 'employee_id')));
    }

    public function test_without_a_date_filter_it_is_today_as_legacy(): void
    {
        $this->day('2026-06-21');
        $this->day('2026-06-20');

        $this->list()->assertJsonCount(2, 'data');
    }

    public function test_a_day_a_range_a_month_and_a_year(): void
    {
        $this->day('2026-06-21');
        $this->day('2026-06-10');
        $this->day('2026-05-31');
        $this->day('2025-12-31');

        $this->list(['date' => '2026-06-10'])->assertJsonPath('meta.total', 2);
        $this->list(['from' => '2026-05-31', 'to' => '2026-06-10'])->assertJsonPath('meta.total', 4);
        $this->list(['month' => 6, 'year' => 2026])->assertJsonPath('meta.total', 4);
        $this->list(['month' => 6])->assertJsonPath('meta.total', 4, 'the month defaults to this year');
        $this->list(['year' => 2025])->assertJsonPath('meta.total', 2);
    }

    public function test_the_flag_and_type_filters(): void
    {
        $this->day('2026-06-21', hours: 3.0);
        AttendanceRecord::factory()->checkIn()->missingCheckout()->create(['employee_id' => $this->employee->id, 'recorded_at' => '2026-06-20 08:00:00']);

        $this->list(['year' => 2026, 'type' => AttendanceEventType::CheckOut->value])->assertJsonPath('meta.total', 1);
        $this->list(['year' => 2026, 'missing_checkout' => 1])->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.status_label', 'انصراف مفقود');
        $this->list(['year' => 2026, 'work_hours_min' => 2, 'work_hours_max' => 4])->assertJsonPath('meta.total', 1);
        $this->list(['year' => 2026, 'is_corrected' => 1])->assertJsonPath('meta.total', 0);
        $this->list(['year' => 2026, 'rejected' => 0])->assertJsonPath('meta.total', 3);
    }

    public function test_newest_first_by_default_and_an_allow_listed_sort(): void
    {
        $this->day('2026-06-21');

        $this->list()->assertJsonPath('data.0.type', AttendanceEventType::CheckOut->value);
        $this->list(['sort' => 'recorded_at'])->assertJsonPath('data.0.type', AttendanceEventType::CheckIn->value);
    }

    public function test_bad_filters_and_unknown_sorts_are_422(): void
    {
        $this->list(['sort' => 'employee_id'])->assertStatus(422)->assertJsonValidationErrors('sort');
        $this->list(['to' => '2026-06-01', 'from' => '2026-06-10'])->assertStatus(422)->assertJsonValidationErrors('to');
        $this->list(['work_hours_min' => 5, 'work_hours_max' => 2])->assertStatus(422)->assertJsonValidationErrors('work_hours_max');
        $this->list(['month' => 13])->assertStatus(422)->assertJsonValidationErrors('month');
    }

    public function test_a_suspended_employee_still_reads_their_history(): void
    {
        $this->employee->update(['status' => EmployeeStatus::Suspended]);
        $this->day('2026-06-21');

        $this->list()->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_an_account_with_no_employee_is_refused(): void
    {
        Sanctum::actingAs(User::factory()->create(['must_change_password' => false]));

        $this->list()->assertForbidden();
    }
}
