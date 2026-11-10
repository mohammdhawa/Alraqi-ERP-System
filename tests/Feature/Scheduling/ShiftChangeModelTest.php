<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Enums\ShiftChangeOrigin;
use App\Modules\Scheduling\Exceptions\SchedulingException;
use App\Modules\Scheduling\Models\Shift;
use App\Modules\Scheduling\Models\ShiftChange;
use App\Shared\Models\AuditLog;
use Database\Seeders\SchedulingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Work shifts piece 2, plan Task 1: a person's shift on a date (spec §3.1) —
 * one row per person per date, a null shift a day off, and the rules every
 * writer is bound by.
 */
class ShiftChangeModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-13 10:00:00');
        $this->seed(SchedulingSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function night(bool $active = true): Shift
    {
        return Shift::create(['code' => 'NIGHT', 'name' => 'الوردية الليلية', 'starts_at' => '22:00', 'duration_minutes' => 480, 'is_active' => $active]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function change(Employee $employee, array $overrides = []): ShiftChange
    {
        return ShiftChange::create([
            'employee_id' => $employee->id,
            'date'        => '2026-11-15',
            'shift_id'    => null,
            'origin'      => ShiftChangeOrigin::Hr,
            'reason'      => 'تكليف',
            ...$overrides,
        ]);
    }

    public function test_a_change_is_one_per_person_per_date(): void
    {
        $employee = Employee::factory()->create();
        $this->change($employee);

        $this->expectException(QueryException::class);
        $this->change($employee, ['shift_id' => $this->night()->id]);
    }

    public function test_a_null_shift_is_a_day_off_and_is_stored(): void
    {
        $change = $this->change(Employee::factory()->create());

        $this->assertNull($change->fresh()->shift_id);
        $this->assertSame('2026-11-15', $change->fresh()->date->toDateString());
        $this->assertSame(ShiftChangeOrigin::Hr, $change->fresh()->origin);
    }

    public function test_an_inactive_shift_is_refused(): void
    {
        $this->expectExceptionObject(new SchedulingException('الوردية المحددة غير مفعّلة.'));
        $this->change(Employee::factory()->create(), ['shift_id' => $this->night(active: false)->id]);
    }

    public function test_an_hr_change_needs_a_reason(): void
    {
        $this->expectExceptionObject(new SchedulingException('سبب التعديل مطلوب.'));
        $this->change(Employee::factory()->create(), ['reason' => '  ']);
    }

    public function test_origin_and_swap_id_must_agree(): void
    {
        $this->expectExceptionObject(new SchedulingException('مصدر التعديل لا يطابق التبديل.'));
        $this->change(Employee::factory()->create(), ['origin' => ShiftChangeOrigin::Swap, 'reason' => null]);
    }

    public function test_a_shift_a_change_uses_is_in_use(): void
    {
        $night = $this->night();
        $this->assertFalse($night->isInUse());

        $this->change(Employee::factory()->create(), ['shift_id' => $night->id]);
        $this->assertTrue($night->isInUse());

        try {
            $night->update(['starts_at' => '21:00']);
            $this->fail('A used shift\'s start changed.');
        } catch (SchedulingException) {
        }

        $this->expectException(SchedulingException::class);
        $night->delete();
    }

    public function test_deleting_and_updating_a_change_are_audited(): void
    {
        $change = $this->change(Employee::factory()->create());
        $change->update(['shift_id' => $this->night()->id]);
        $change->delete();

        $this->assertSame(3, AuditLog::query()->where('auditable_type', 'shift_change')->where('auditable_id', $change->id)->count());
    }
}
