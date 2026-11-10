<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Auth\Models\Notification;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Enums\ShiftChangeOrigin;
use App\Modules\Scheduling\Models\Shift;
use App\Modules\Scheduling\Models\ShiftChange;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Modules\Scheduling\Services\ScheduleAssigner;
use App\Shared\Models\AuditLog;
use App\Shared\Support\NotificationEventCatalogue;
use Database\Seeders\SchedulingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Work shifts piece 2, plan Task 4: HR's one-off changes (spec §5) —
 * /api/hr/employees/{employee}/shift-changes. 2026-11-14 is a Saturday.
 */
class ShiftChangeEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-13 10:00:00');
        $this->seed(SchedulingSeeder::class);
        $this->employee = Employee::factory()->create();
        User::factory()->create(['employee_id' => $this->employee->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function url(string $suffix = ''): string
    {
        return "/api/hr/employees/{$this->employee->id}/shift-changes{$suffix}";
    }

    private function night(): Shift
    {
        return Shift::firstOrCreate(['code' => 'NIGHT'], ['name' => 'الوردية الليلية', 'starts_at' => '22:00', 'duration_minutes' => 480, 'is_active' => true]);
    }

    private function shiftId(string $code): int
    {
        return (int) Shift::where('code', $code)->value('id');
    }

    private function assign(string $code, string $from, ?string $cycleStart = null): void
    {
        app(ScheduleAssigner::class)->assign($this->employee, WorkSchedule::where('code', $code)->sole(), $from, $cycleStart, User::factory()->create());
    }

    /** @param  array<string, mixed>  $body */
    private function record(array $body): TestResponse
    {
        return $this->postJson($this->url(), $body + ['reason' => 'تكليف بمناوبة ليلية']);
    }

    private function swapRow(string $date): ShiftChange
    {
        return ShiftChange::create(['employee_id' => $this->employee->id, 'date' => $date, 'shift_id' => null, 'origin' => ShiftChangeOrigin::Swap, 'shift_swap_id' => 1]);
    }

    public function test_hr_records_a_night_change_and_it_is_listed(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage', 'scheduling.view']);

        $this->record(['from' => '2026-11-15', 'to' => '2026-11-15', 'shift_id' => $this->night()->id])
            ->assertCreated()
            ->assertJsonPath('data.shift_changes.0.date', '2026-11-15')
            ->assertJsonPath('data.shift_changes.0.shift.code', 'NIGHT');

        $this->getJson($this->url('?from=2026-11-14&to=2026-11-20'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.origin', 1)
            ->assertJsonPath('data.0.shift.code', 'NIGHT')
            ->assertJsonPath('data.0.reason', 'تكليف بمناوبة ليلية');
    }

    public function test_a_range_writes_one_row_per_date(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);

        $this->record(['from' => '2026-11-15', 'to' => '2026-11-17', 'shift_id' => null])->assertCreated()->assertJsonCount(3, 'data.shift_changes');

        $this->assertSame(3, ShiftChange::whereNull('shift_id')->count());
    }

    public function test_a_new_change_replaces_an_old_one_on_a_date(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);
        $this->record(['from' => '2026-11-15', 'to' => '2026-11-15', 'shift_id' => $this->night()->id])->assertCreated();

        $this->record(['from' => '2026-11-15', 'to' => '2026-11-15', 'shift_id' => $this->shiftId('DAY')])->assertCreated();

        $change = ShiftChange::sole();
        $this->assertSame($this->shiftId('DAY'), (int) $change->shift_id);
        $this->assertSame(1, AuditLog::where('auditable_type', 'shift_change')->where('auditable_id', $change->id)->where('event', 'updated')->count());
    }

    public function test_a_range_over_a_swap_date_writes_nothing(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);
        $this->swapRow('2026-11-17');

        $response = $this->record(['from' => '2026-11-15', 'to' => '2026-11-18', 'shift_id' => null])->assertStatus(422);

        $this->assertStringStartsWith('2026-11-17: هذا اليوم ضمن تبديل', (string) $response->json('message'));
        $this->assertSame(0, ShiftChange::where('origin', ShiftChangeOrigin::Hr)->count());
    }

    public function test_a_seventh_back_to_back_shift_is_refused(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);
        $this->assign('GUARD', '2026-11-14', '2026-11-14');

        $this->record(['from' => '2026-11-17', 'to' => '2026-11-20', 'shift_id' => $this->shiftId('GUARD')])
            ->assertStatus(422)
            ->assertJsonPath('message', 'يتجاوز هذا التعديل ست ورديات متتالية للموظف.');
    }

    public function test_a_conflicting_recorded_check_in_refuses_everything(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);
        $this->assign('OFFICE', '2026-11-14');
        Carbon::setTestNow('2026-11-16 10:00:00');
        AttendanceRecord::factory()->checkIn()->create(['employee_id' => $this->employee->id, 'recorded_at' => '2026-11-15 08:30:00', 'shift_date' => '2026-11-15']);
        AttendanceRecord::factory()->checkOut()->create(['employee_id' => $this->employee->id, 'recorded_at' => '2026-11-15 16:00:00', 'shift_date' => '2026-11-15']);
        AttendanceRecord::factory()->checkIn()->create(['employee_id' => $this->employee->id, 'recorded_at' => '2026-11-15 23:00:00', 'shift_date' => '2026-11-15', 'outside_schedule' => true]);
        $long = Shift::create(['code' => 'LONG24', 'name' => 'LONG24', 'starts_at' => '08:00', 'duration_minutes' => 1440, 'is_active' => true]);

        $this->record(['from' => '2026-11-15', 'to' => '2026-11-15', 'shift_id' => $long->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'يتعارض هذا التعديل مع تسجيل حضور في 2026-11-15.');

        $this->assertSame(0, ShiftChange::count());
    }

    public function test_removing_a_change_re_matches(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);
        $this->assign('OFFICE', '2026-11-14');
        $this->record(['from' => '2026-11-15', 'to' => '2026-11-15', 'shift_id' => $this->night()->id])->assertCreated();
        Carbon::setTestNow('2026-11-15 21:30:00');
        $checkIn = AttendanceRecord::factory()->checkIn()->create(['employee_id' => $this->employee->id, 'recorded_at' => '2026-11-15 21:30:00', 'shift_date' => '2026-11-15']);

        $this->deleteJson($this->url('/'.ShiftChange::sole()->id))->assertOk();

        $this->assertTrue($checkIn->fresh()->outside_schedule);
        $this->assertSame(0, ShiftChange::count());
    }

    public function test_a_swap_change_cannot_be_deleted(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);
        $row = $this->swapRow('2026-11-17');

        $this->deleteJson($this->url('/'.$row->id))
            ->assertStatus(422)
            ->assertJsonPath('message', 'هذا التعديل ناتج عن تبديل مناوبة؛ يُلغى بإلغاء التبديل.');
    }

    public function test_another_persons_change_is_a_404(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);
        $other = ShiftChange::create(['employee_id' => Employee::factory()->create()->id, 'date' => '2026-11-15', 'shift_id' => null, 'origin' => ShiftChangeOrigin::Hr, 'reason' => 'x']);

        $this->deleteJson($this->url('/'.$other->id))->assertNotFound();
    }

    public function test_a_future_change_notifies_once_and_a_past_one_is_silent(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);

        $this->record(['from' => '2026-11-15', 'to' => '2026-11-17', 'shift_id' => null])->assertCreated();

        $notice = Notification::sole();
        $this->assertSame(NotificationEventCatalogue::SHIFT_CHANGE_RECORDED, $notice->type);
        $this->assertSame('تعديل الوردية', $notice->title);
        $this->assertSame('تم تعديل ورديتك من 2026-11-15 إلى 2026-11-17: يوم راحة', $notice->body);

        $this->record(['from' => '2026-11-10', 'to' => '2026-11-10', 'shift_id' => null])->assertCreated();
        $this->assertSame(1, Notification::count());
    }

    public function test_one_day_and_a_removal_word_their_notices(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);

        $this->record(['from' => '2026-11-15', 'to' => '2026-11-15', 'shift_id' => $this->night()->id])->assertCreated();
        $this->assertSame('تم تعديل ورديتك ليوم 2026-11-15: الوردية الليلية', Notification::sole()->body);

        $this->deleteJson($this->url('/'.ShiftChange::sole()->id))->assertOk();
        $removed = Notification::where('type', NotificationEventCatalogue::SHIFT_CHANGE_REMOVED)->sole();
        $this->assertSame('إلغاء تعديل الوردية', $removed->title);
        $this->assertSame('عادت ورديتك ليوم 2026-11-15 إلى جدولك.', $removed->body);
    }

    public function test_the_permissions_and_limits(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.view']);
        $this->getJson($this->url('?from=2026-11-14&to=2026-11-20'))->assertOk();
        $this->record(['from' => '2026-11-15', 'to' => '2026-11-15', 'shift_id' => null])->assertForbidden();

        $this->actingAsUserWithPermissions([]);
        $this->getJson($this->url('?from=2026-11-14&to=2026-11-20'))->assertForbidden();

        $this->actingAsUserWithPermissions(['scheduling.manage', 'scheduling.view']);
        $this->record(['from' => '2026-11-15', 'to' => '2026-12-16', 'shift_id' => null])
            ->assertStatus(422)->assertJsonValidationErrors('to');
        $this->postJson($this->url(), ['from' => '2026-11-15', 'to' => '2026-11-15', 'shift_id' => null])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->getJson($this->url('?from=2026-11-01&to=2027-02-02'))
            ->assertStatus(422)->assertJsonValidationErrors('to');
    }
}
