<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Approvals\Enums\StepResolver;
use App\Modules\Auth\Models\User;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Scheduling\Enums\ShiftChangeOrigin;
use App\Modules\Scheduling\Enums\WorkScheduleKind;
use App\Modules\Scheduling\Models\Shift;
use App\Modules\Scheduling\Models\ShiftChange;
use App\Modules\Scheduling\Models\ShiftSwap;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Modules\Scheduling\Services\ScheduleAssigner;
use App\Modules\Scheduling\Services\ShiftSwapApprovalRoute;
use App\Modules\Scheduling\Services\ShiftSwapCheck;
use App\Modules\Scheduling\Services\ShiftSwapPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Scheduling\Concerns\SwapFixtures;
use Tests\TestCase;

/**
 * Work shifts piece 2, plan Task 5: the rules an exchange must pass at filing
 * and again before approval (spec §6.2), and what it would write (§6.3).
 */
class ShiftSwapCheckTest extends TestCase
{
    use RefreshDatabase;
    use SwapFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-13 10:00:00');
        $this->buildGuards();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function check(ShiftSwap $swap, bool $atFiling = true): ?string
    {
        return app(ShiftSwapCheck::class)->violation($swap, $atFiling);
    }

    private function guardShiftId(): int
    {
        return (int) Shift::where('code', 'GUARD')->value('id');
    }

    public function test_opposite_teams_pass(): void
    {
        $this->assertNull($this->check($this->exchange($this->guardA, '2026-11-20', $this->guardB, '2026-11-17')));
    }

    public function test_with_oneself_is_refused(): void
    {
        $this->assertSame('لا يمكنك تبديل مناوبة مع نفسك.', $this->check($this->exchange($this->guardA, '2026-11-20', $this->guardA, '2026-11-14')));
    }

    public function test_a_partner_who_has_left_is_refused(): void
    {
        $this->guardB->update(['termination_date' => '2026-11-16']);

        $this->assertSame('الزميل المحدد ليس على رأس عمله.', $this->check($this->exchange($this->guardA, '2026-11-20', $this->guardB, '2026-11-17')));
    }

    public function test_an_office_partner_is_refused(): void
    {
        $office = $this->person($this->units['a1']);

        $this->assertSame('تبديل المناوبات متاح لموظفي الحراسة فقط.', $this->check($this->exchange($this->guardA, '2026-11-20', $office, '2026-11-17')));
    }

    public function test_another_manager_is_refused_at_filing_only(): void
    {
        $swap = $this->exchange($this->guardA, '2026-11-20', $this->guardC, '2026-11-17');

        $this->assertSame('يجب أن يكون للزميل المدير المباشر نفسه.', $this->check($swap));
        $this->assertNull($this->check($swap, atFiling: false));
    }

    public function test_a_date_that_is_not_a_duty_start_is_refused(): void
    {
        $this->assertSame('التاريخ المحدد ليس بداية مناوبة.', $this->check($this->exchange($this->guardA, '2026-11-15', $this->guardB, '2026-11-17')));
    }

    public function test_the_same_duty_is_refused(): void
    {
        $twin = $this->person($this->units['a1']);
        $this->assignGuard($twin, '2026-11-14');

        $this->assertSame('لا يمكن تبديل المناوبة بنفسها.', $this->check($this->exchange($this->guardA, '2026-11-20', $twin, '2026-11-20')));
    }

    public function test_an_opened_duty_is_refused(): void
    {
        Carbon::setTestNow('2026-11-17 07:30:00');

        $this->assertSame('لا يمكن تبديل مناوبة بدأت.', $this->check($this->exchange($this->guardA, '2026-11-20', $this->guardB, '2026-11-17')));
    }

    public function test_a_duty_in_another_live_swap_is_refused(): void
    {
        $this->exchange($this->guardA, '2026-11-20', $this->guardB, '2026-11-23')->save();

        $this->assertSame('إحدى المناوبتين ضمن طلب تبديل آخر.', $this->check($this->exchange($this->guardA, '2026-11-20', $this->guardB, '2026-11-17')));
    }

    public function test_an_hr_change_on_a_written_date_is_refused(): void
    {
        ShiftChange::create(['employee_id' => $this->guardB->id, 'date' => '2026-11-21', 'shift_id' => null, 'origin' => ShiftChangeOrigin::Hr, 'reason' => 'x']);

        $this->assertSame('يوجد تعديل على أحد أيام التبديل.', $this->check($this->exchange($this->guardA, '2026-11-20', $this->guardB, '2026-11-17')));
    }

    public function test_pending_leave_on_either_duty_is_refused(): void
    {
        LeaveRequest::factory()->create(['employee_id' => $this->guardB->id, 'start_date' => '2026-11-21', 'end_date' => '2026-11-21', 'status' => LeaveStatus::Pending]);

        $this->assertSame('توجد إجازة تتداخل مع إحدى المناوبتين.', $this->check($this->exchange($this->guardA, '2026-11-20', $this->guardB, '2026-11-17')));
    }

    public function test_overrides_for_opposite_teams(): void
    {
        $guard = $this->guardShiftId();
        $overrides = app(ShiftSwapPlan::class)->overrides($this->exchange($this->guardA, '2026-11-20', $this->guardB, '2026-11-17'));

        $this->assertSame(
            ['2026-11-20' => null, '2026-11-21' => null, '2026-11-22' => null, '2026-11-17' => $guard, '2026-11-18' => $guard, '2026-11-19' => $guard],
            $overrides[$this->guardA->id],
        );
        $this->assertSame(
            ['2026-11-17' => null, '2026-11-18' => null, '2026-11-19' => null, '2026-11-20' => $guard, '2026-11-21' => $guard, '2026-11-22' => $guard],
            $overrides[$this->guardB->id],
        );
    }

    public function test_overrides_for_offset_duties_take_the_taken_shift(): void
    {
        $guard = $this->guardShiftId();
        $offset = $this->person($this->units['a1']);
        $this->assignGuard($offset, '2026-11-15');

        $overrides = app(ShiftSwapPlan::class)->overrides($this->exchange($offset, '2026-11-15', $this->guardA, '2026-11-14'));

        // offset gives 15–17 and takes 14–16: works 14, 15, 16; 17 off.
        $this->assertSame(['2026-11-15' => $guard, '2026-11-16' => $guard, '2026-11-17' => null, '2026-11-14' => $guard], $overrides[$offset->id]);
        // guardA gives 14–16 and takes 15–17: works 15, 16, 17; 14 off.
        $this->assertSame(['2026-11-14' => null, '2026-11-15' => $guard, '2026-11-16' => $guard, '2026-11-17' => $guard], $overrides[$this->guardA->id]);
    }

    public function test_the_route_is_one_direct_manager_step(): void
    {
        $route = ShiftSwapApprovalRoute::make();

        $this->assertCount(1, $route->stages);
        $this->assertCount(1, $route->stages[0]);
        $this->assertSame(StepResolver::DirectManager, $route->stages[0][0]->resolver);
    }

    public function test_a_partner_who_leaves_before_the_duty_he_takes_is_refused(): void
    {
        $this->guardB->update(['termination_date' => '2026-11-19']);

        $this->assertSame('الزميل المحدد ليس على رأس عمله.', $this->check($this->exchange($this->guardA, '2026-11-20', $this->guardB, '2026-11-17')));
    }

    public function test_leave_on_an_own_duty_between_the_two_does_not_refuse(): void
    {
        $guard = $this->guardShiftId();
        $nine = WorkSchedule::createWithDays(
            ['code' => 'G9', 'name' => 'حراسة 9', 'kind' => WorkScheduleKind::Cycle, 'cycle_days' => 9],
            [0 => $guard, 1 => $guard, 2 => $guard, 3 => null, 4 => null, 5 => null, 6 => null, 7 => null, 8 => null],
        );
        $a = $this->person($this->units['a1']);
        $c = $this->person($this->units['a1']);
        app(ScheduleAssigner::class)->assign($a, $nine, '2026-11-13', '2026-11-14', User::factory()->create());
        app(ScheduleAssigner::class)->assign($c, $nine, '2026-11-13', '2026-11-20', User::factory()->create());
        $swap = $this->exchange($a, '2026-11-14', $c, '2026-11-29');
        $this->assertNull($this->check($swap));

        // a's own duty 11-23..25 lies between the two exchanged duties.
        LeaveRequest::factory()->create(['employee_id' => $a->id, 'start_date' => '2026-11-23', 'end_date' => '2026-11-25', 'status' => LeaveStatus::Approved]);

        $this->assertNull($this->check($swap));
    }
}
