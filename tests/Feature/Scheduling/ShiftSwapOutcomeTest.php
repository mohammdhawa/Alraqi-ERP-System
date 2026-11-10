<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Attendance\Contracts\ExpectedShifts;
use App\Modules\Auth\Models\Notification;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Scheduling\Enums\ShiftChangeOrigin;
use App\Modules\Scheduling\Enums\ShiftSwapStatus;
use App\Modules\Scheduling\Models\ShiftChange;
use App\Modules\Scheduling\Models\ShiftSwap;
use App\Shared\Support\NotificationEventCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Scheduling\Concerns\SwapFixtures;
use Tests\TestCase;

/**
 * Work shifts piece 2, plan Task 7: the manager's decision writes both
 * guards' days (spec §6.3); HR lists and cancels exchanges (§6.4); both are
 * told (§6.5). guardA 11-20 ⇄ guardB 11-17: A works 14–19, B 20–25.
 */
class ShiftSwapOutcomeTest extends TestCase
{
    use RefreshDatabase;
    use SwapFixtures;

    private int $swapId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-13 10:00:00');
        $this->buildGuards();

        $this->actingAsPerson($this->guardA);
        $this->swapId = (int) $this->postJson('/api/me/shift-swaps', [
            'my_duty_date' => '2026-11-20', 'partner_id' => $this->guardB->id, 'partner_duty_date' => '2026-11-17',
        ])->assertCreated()->json('data.shift_swap.id');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function swapRow(): ShiftSwap
    {
        return ShiftSwap::findOrFail($this->swapId);
    }

    private function decide(string $how, ?Employee $as = null): TestResponse
    {
        $approval = $this->swapRow()->approval;
        $this->actingAsPerson($as ?? $this->people['headA']);

        return $this->postJson("/api/approvals/{$approval->id}/steps/{$approval->steps->first()->id}/{$how}");
    }

    /** @return list<string> */
    private function dates(Employee $employee, string $from, string $to): array
    {
        return array_keys(app(ExpectedShifts::class)->shiftsFor([$employee->id], $from, $to)[$employee->id]);
    }

    private function typesFor(Employee $employee, string $type): int
    {
        return Notification::where('user_id', $this->accountOf($employee)->id)->where('type', $type)->count();
    }

    public function test_approval_writes_twelve_rows(): void
    {
        $this->decide('approve')->assertOk();

        $this->assertSame(ShiftSwapStatus::Approved, $this->swapRow()->status);
        $this->assertSame(12, ShiftChange::where('shift_swap_id', $this->swapId)->where('origin', ShiftChangeOrigin::Swap)->count());
        $this->assertSame(['2026-11-17', '2026-11-18', '2026-11-19'], $this->dates($this->guardA, '2026-11-17', '2026-11-22'));
        $this->assertSame(['2026-11-20', '2026-11-21', '2026-11-22'], $this->dates($this->guardB, '2026-11-17', '2026-11-22'));
        $this->assertSame(1, $this->typesFor($this->guardA, NotificationEventCatalogue::SHIFT_SWAP_APPROVED));
        $this->assertSame(1, $this->typesFor($this->guardB, NotificationEventCatalogue::SHIFT_SWAP_APPROVED));
    }

    public function test_each_guard_works_six_days_as_two_duties(): void
    {
        $this->decide('approve')->assertOk();
        $shifts = app(ExpectedShifts::class);

        $this->assertSame(['2026-11-14', '2026-11-15', '2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19'], $this->dates($this->guardA, '2026-11-14', '2026-11-19'));
        $this->assertCount(3, $shifts->dutyOn($this->guardA, '2026-11-14')->shifts);
        $this->assertCount(3, $shifts->dutyOn($this->guardA, '2026-11-17')->shifts);

        // At 08:01 on day four the first duty has ended: only the second holds the moment.
        $holding = $shifts->dutiesHolding($this->guardA, Carbon::parse('2026-11-17 08:01:00'));
        $this->assertSame(['2026-11-17'], array_map(static fn ($duty): string => $duty->shiftDate(), $holding));
    }

    public function test_approval_is_refused_when_leave_arrived_after_filing(): void
    {
        LeaveRequest::factory()->create(['employee_id' => $this->guardB->id, 'start_date' => '2026-11-21', 'end_date' => '2026-11-21', 'status' => LeaveStatus::Approved]);

        $this->decide('approve')->assertStatus(422)->assertJsonPath('message', 'توجد إجازة تتداخل مع إحدى المناوبتين.');

        $this->assertSame(ShiftSwapStatus::Pending, $this->swapRow()->status);
        $this->assertSame(0, ShiftChange::count());
    }

    public function test_rejection_writes_nothing_and_tells_both(): void
    {
        $this->decide('reject')->assertOk();

        $this->assertSame(ShiftSwapStatus::Rejected, $this->swapRow()->status);
        $this->assertSame(0, ShiftChange::count());
        $this->assertSame(1, $this->typesFor($this->guardA, NotificationEventCatalogue::SHIFT_SWAP_REJECTED));
        $this->assertSame(1, $this->typesFor($this->guardB, NotificationEventCatalogue::SHIFT_SWAP_REJECTED));
    }

    public function test_another_manager_cannot_decide(): void
    {
        $this->decide('approve', $this->people['hrHead'])->assertForbidden();
    }

    public function test_it_shows_in_the_managers_inbox(): void
    {
        $this->actingAsPerson($this->people['headA']);

        $this->getJson('/api/me/approvals')->assertOk()
            ->assertJsonPath('data.0.subject.title', 'تبديل مناوبة');
    }

    public function test_hr_lists_and_shows_swaps(): void
    {
        $this->decide('approve')->assertOk();
        $this->actingAsUserWithPermissions(['scheduling.view']);

        $this->getJson("/api/scheduling/swaps?status=2&employee_id={$this->guardB->id}")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->swapId)
            ->assertJsonPath('data.0.my_role', null)
            ->assertJsonPath('data.0.requester_duty.date', '2026-11-20');
        $this->getJson('/api/scheduling/swaps?status=1')->assertOk()->assertJsonCount(0, 'data');

        $this->getJson("/api/scheduling/swaps/{$this->swapId}")->assertOk()->assertJsonPath('data.partner.id', $this->guardB->id);
    }

    public function test_hr_cancels_before_the_duty_opens(): void
    {
        $this->decide('approve')->assertOk();
        Carbon::setTestNow('2026-11-16 10:00:00');
        $this->actingAsUserWithPermissions(['scheduling.manage']);

        $this->postJson("/api/scheduling/swaps/{$this->swapId}/cancel", ['reason' => 'مرض أحد الحارسين'])->assertOk()
            ->assertJsonPath('data.status', ShiftSwapStatus::Cancelled->value)
            ->assertJsonPath('data.cancel_reason', 'مرض أحد الحارسين');

        $this->assertSame(0, ShiftChange::count());
        $this->assertSame(['2026-11-20', '2026-11-21', '2026-11-22'], $this->dates($this->guardA, '2026-11-17', '2026-11-22'));
        $this->assertSame(1, $this->typesFor($this->guardA, NotificationEventCatalogue::SHIFT_SWAP_CANCELLED));
        $this->assertSame(1, $this->typesFor($this->guardB, NotificationEventCatalogue::SHIFT_SWAP_CANCELLED));
    }

    public function test_hr_cannot_cancel_after_a_duty_opens(): void
    {
        $this->decide('approve')->assertOk();
        Carbon::setTestNow('2026-11-17 07:00:00');
        $this->actingAsUserWithPermissions(['scheduling.manage']);

        $this->postJson("/api/scheduling/swaps/{$this->swapId}/cancel", ['reason' => 'x'])
            ->assertStatus(422)->assertJsonPath('message', 'لا يمكن إلغاء تبديل بدأت إحدى مناوبتيه.');
        $this->assertSame(12, ShiftChange::count());
    }

    public function test_hr_cannot_cancel_a_pending_swap(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);

        $this->postJson("/api/scheduling/swaps/{$this->swapId}/cancel", ['reason' => 'x'])
            ->assertStatus(422)->assertJsonPath('message', 'لا يمكن إلغاء إلا تبديل معتمد.');
    }

    public function test_cancelling_needs_a_reason_and_manage(): void
    {
        $this->decide('approve')->assertOk();

        $this->actingAsUserWithPermissions(['scheduling.view']);
        $this->postJson("/api/scheduling/swaps/{$this->swapId}/cancel", ['reason' => 'x'])->assertForbidden();

        $this->actingAsUserWithPermissions(['scheduling.manage']);
        $this->postJson("/api/scheduling/swaps/{$this->swapId}/cancel")->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_leave_after_an_approved_swap_charges_the_new_days(): void
    {
        $this->decide('approve')->assertOk();
        $this->actingAsPerson($this->guardA);

        $this->postJson('/api/me/leave-requests', ['leave_type_id' => $this->type('annual')->id, 'start_date' => '2026-11-17', 'end_date' => '2026-11-19'])
            ->assertCreated()->assertJsonPath('data.leave_request.requested_days', 3);
    }

    public function test_an_hr_change_cannot_overwrite_swap_days(): void
    {
        $this->decide('approve')->assertOk();
        $this->actingAsUserWithPermissions(['scheduling.manage']);

        $this->postJson("/api/hr/employees/{$this->guardB->id}/shift-changes", ['from' => '2026-11-21', 'to' => '2026-11-21', 'shift_id' => null, 'reason' => 'x'])
            ->assertStatus(422);
    }
}
