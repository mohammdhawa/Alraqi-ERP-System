<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Approvals\Models\Approval;
use App\Modules\Auth\Models\Notification;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Scheduling\Enums\ShiftSwapStatus;
use App\Modules\Scheduling\Models\ShiftSwap;
use App\Shared\Support\NotificationEventCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Scheduling\Concerns\SwapFixtures;
use Tests\TestCase;

/**
 * Work shifts piece 2, plan Task 6: a guard's own exchanges under /api/me
 * (spec §6.1, §6.5).
 */
class MyShiftSwapsTest extends TestCase
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

    private function file(string $mine = '2026-11-20', string $theirs = '2026-11-17'): TestResponse
    {
        $this->actingAsPerson($this->guardA);

        return $this->postJson('/api/me/shift-swaps', [
            'my_duty_date'      => $mine,
            'partner_id'        => $this->guardB->id,
            'partner_duty_date' => $theirs,
            'reason'            => 'ظرف عائلي',
        ]);
    }

    /** @return list<string> */
    private function typesFor(Employee $employee): array
    {
        return Notification::where('user_id', $this->accountOf($employee)->id)->pluck('type')->all();
    }

    public function test_options_lists_my_duties_and_my_colleagues(): void
    {
        $this->actingAsPerson($this->guardA);

        $response = $this->getJson('/api/me/shift-swaps/options')->assertOk();

        $this->assertSame(['2026-11-14', '2026-11-20', '2026-11-26'], array_slice(array_column($response->json('data.my_duties'), 'date'), 0, 3));
        $this->assertSame([$this->guardB->id], array_column(array_column($response->json('data.partners'), 'employee'), 'id'));
        $this->assertSame('2026-11-17', $response->json('data.partners.0.duties.0.date'));
        $this->assertCount(3, $response->json('data.my_duties.0.shifts'));
    }

    public function test_a_guard_files_an_exchange(): void
    {
        $this->file()->assertCreated()
            ->assertJsonPath('data.shift_swap.status', ShiftSwapStatus::Pending->value)
            ->assertJsonPath('data.shift_swap.my_role', 'requester')
            ->assertJsonPath('data.shift_swap.requester_duty.date', '2026-11-20')
            ->assertJsonPath('data.shift_swap.partner_duty.date', '2026-11-17');

        $approval = Approval::sole();
        $this->assertCount(1, $approval->steps);
        $this->assertSame($this->people['headA']->id, (int) $approval->steps->first()->approver_id);
    }

    public function test_filing_tells_both(): void
    {
        $this->file()->assertCreated();

        $this->assertContains(NotificationEventCatalogue::SHIFT_SWAP_SUBMITTED, $this->typesFor($this->guardA));
        $this->assertSame([NotificationEventCatalogue::SHIFT_SWAP_REQUESTED], $this->typesFor($this->guardB));
    }

    public function test_a_partner_without_an_account_is_not_told(): void
    {
        $this->accountOf($this->guardB)->delete();

        $this->file()->assertCreated();

        $this->assertSame(0, Notification::where('type', NotificationEventCatalogue::SHIFT_SWAP_REQUESTED)->count());
    }

    public function test_each_refusal_is_a_422_with_its_message(): void
    {
        LeaveRequest::factory()->create(['employee_id' => $this->guardB->id, 'start_date' => '2026-11-21', 'end_date' => '2026-11-21', 'status' => LeaveStatus::Approved]);
        $this->file()->assertStatus(422)->assertJsonPath('message', 'توجد إجازة تتداخل مع إحدى المناوبتين.');

        Carbon::setTestNow('2026-11-17 07:30:00');
        $this->file()->assertStatus(422)->assertJsonPath('message', 'لا يمكن تبديل مناوبة بدأت.');

        $this->assertSame(0, ShiftSwap::count());
    }

    public function test_a_second_exchange_for_the_same_duty_is_refused(): void
    {
        $this->file()->assertCreated();

        $this->file('2026-11-20', '2026-11-23')->assertStatus(422)->assertJsonPath('message', 'إحدى المناوبتين ضمن طلب تبديل آخر.');
    }

    public function test_both_see_it_and_nobody_else_does(): void
    {
        $id = $this->file()->json('data.shift_swap.id');

        $this->getJson("/api/me/shift-swaps/{$id}")->assertOk()->assertJsonPath('data.my_role', 'requester');

        $this->actingAsPerson($this->guardB);
        $this->getJson("/api/me/shift-swaps/{$id}")->assertOk()->assertJsonPath('data.my_role', 'partner');

        $this->actingAsPerson($this->guardC);
        $this->getJson("/api/me/shift-swaps/{$id}")->assertNotFound();
    }

    public function test_the_requester_withdraws_while_pending(): void
    {
        $id = $this->file()->json('data.shift_swap.id');

        $this->actingAsPerson($this->guardB);
        $this->postJson("/api/me/shift-swaps/{$id}/cancel")->assertStatus(422)->assertJsonPath('message', 'لا يمكن سحب هذا الطلب.');

        $this->actingAsPerson($this->guardA);
        $this->postJson("/api/me/shift-swaps/{$id}/cancel")->assertOk()->assertJsonPath('data.status', ShiftSwapStatus::Withdrawn->value);

        $this->assertContains(NotificationEventCatalogue::SHIFT_SWAP_WITHDRAWN, $this->typesFor($this->guardB));
        $this->postJson("/api/me/shift-swaps/{$id}/cancel")->assertStatus(422);
    }

    public function test_the_list_is_mine_newest_first(): void
    {
        $first = $this->file()->json('data.shift_swap.id');
        $this->postJson("/api/me/shift-swaps/{$first}/cancel")->assertOk();
        $second = $this->file()->json('data.shift_swap.id');

        $this->getJson('/api/me/shift-swaps')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second)
            ->assertJsonPath('data.1.id', $first);

        $this->actingAsPerson($this->guardC);
        $this->getJson('/api/me/shift-swaps')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_account_without_an_employee_is_refused(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/me/shift-swaps')->assertForbidden();
    }
}
