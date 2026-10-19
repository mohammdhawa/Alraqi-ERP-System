<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Models\Notification;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveRequest;
use App\Shared\Support\NotificationEventCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Leave\Concerns\LeaveFixtures;
use Tests\TestCase;

/**
 * Undoing an excuse filed in error — legacy's LeaveExcuseCancellationTest
 * (LEAVE_BUILD.md §6). Everything the excuse did unwinds from the one status
 * change, because every consequence was derived from it: the day is an
 * absence again, a deducted day comes back, the period is free. The row is
 * kept with who undid it and why.
 */
class LeaveExcuseCancellationTest extends TestCase
{
    use LeaveFixtures;
    use RefreshDatabase;

    private const BOTH = ['leave.excuses.create', 'leave.excuses.delete'];

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLeaveTree();
        $this->hr = $this->actingAsScopedUser(self::BOTH, ScopeType::Company);
    }

    private function fileExcuse(string $type = 'annual', array $body = [], ?Employee $employee = null): int
    {
        return $this->postJson('/api/leave/excuses', $body + [
            'employee_id'   => ($employee ?? $this->people['staff'])->id,
            'leave_type_id' => $this->type($type)->id,
            'start_date'    => '2026-06-21',
            'end_date'      => '2026-06-22',
        ])->assertCreated()->json('data.leave_request.id');
    }

    private function undo(int $id, array $body = []): TestResponse
    {
        return $this->deleteJson("/api/leave/excuses/{$id}", $body);
    }

    private function board(): TestResponse
    {
        return $this->getJson('/api/attendance/report?date=2026-06-21');
    }

    // ── What unwinds ─────────────────────────────────────────────────────

    public function test_undoing_turns_the_excused_day_back_into_an_absence(): void
    {
        $id = $this->fileExcuse();

        $this->undo($id, ['reason' => 'سُجّل للموظف الخطأ'])
            ->assertOk()
            ->assertJsonPath('message', 'تم التراجع عن العذر.')
            ->assertJsonPath('data.leave_request.status', LeaveStatus::Cancelled->value)
            ->assertJsonPath('data.leave_request.cancelled_by.id', $this->hr->id)
            ->assertJsonPath('data.leave_request.cancellation_reason', 'سُجّل للموظف الخطأ')
            ->assertJsonPath('data.balance.used_days', 0);

        $this->assertNotNull(LeaveRequest::find($id)->cancelled_at, 'the row is kept');

        $this->actingAsScopedUser(['attendance.records.view'], ScopeType::Company);
        $this->board()->assertOk()->assertJsonCount(0, 'data.excused')->assertJsonCount(0, 'data.on_leave');
    }

    public function test_a_non_deducting_undo_charges_nothing(): void
    {
        $id = $this->fileExcuse('official_mission', ['reason' => 'مهمة']);

        $this->undo($id)->assertOk()->assertJsonPath('data.balance.used_days', 0)->assertJsonPath('data.balance.non_deducting_days', 0);
    }

    public function test_undoing_a_forced_excuse_clears_the_overdraft(): void
    {
        LeaveBalance::create(['employee_id' => $this->people['staff']->id, 'year' => 2026, 'allocated_days' => 1, 'adjustment_days' => 0]);
        $id = $this->fileExcuse(body: ['force' => true]);

        $this->undo($id)->assertOk()->assertJsonPath('data.balance.over_balance_days', 0)->assertJsonPath('data.balance.remaining_days', 1);
    }

    public function test_the_same_period_can_be_excused_again_at_once(): void
    {
        $this->undo($this->fileExcuse())->assertOk();

        $this->fileExcuse('emergency');
    }

    // ── The employee ─────────────────────────────────────────────────────

    public function test_the_employee_is_told_their_absence_is_back(): void
    {
        $this->undo($this->fileExcuse(), ['reason' => 'خطأ في التاريخ'])->assertOk();

        $told = Notification::where('user_id', $this->accountOf($this->people['staff'])->id)
            ->where('type', NotificationEventCatalogue::LEAVE_EXCUSE_CANCELLED)->sole();

        $this->assertSame('تم التراجع عن عذر غيابك', $told->title);
        $this->assertSame('تم التراجع عن (إجازة سنوية) المسجّلة من 2026-06-21 إلى 2026-06-22، وعاد الغياب كما كان. وقد أُعيد يوم الرصيد المخصوم. السبب: خطأ في التاريخ', $told->body);
    }

    public function test_a_free_excuse_undone_without_a_reason_says_only_that(): void
    {
        $this->undo($this->fileExcuse('official_mission', ['reason' => 'مهمة']))->assertOk();

        $this->assertSame(
            'تم التراجع عن (مأمورية رسمية) المسجّلة من 2026-06-21 إلى 2026-06-22، وعاد الغياب كما كان.',
            Notification::where('type', NotificationEventCatalogue::LEAVE_EXCUSE_CANCELLED)->sole()->body,
        );
    }

    public function test_the_employee_sees_it_cancelled_in_their_list(): void
    {
        $this->undo($this->fileExcuse())->assertOk();
        $this->actingAsPerson($this->people['staff']);

        $this->getJson('/api/me/leave-requests')->assertOk()->assertJsonPath('data.0.status', LeaveStatus::Cancelled->value);
    }

    // ── Refusals ─────────────────────────────────────────────────────────

    public function test_undoing_twice_is_refused(): void
    {
        $id = $this->fileExcuse();
        $this->undo($id)->assertOk();

        $this->undo($id)->assertStatus(422)->assertJsonPath('message', 'تم التراجع عن هذا العذر مسبقاً.');
        $this->assertSame(1, Notification::where('type', NotificationEventCatalogue::LEAVE_EXCUSE_CANCELLED)->count());
    }

    public function test_an_employees_own_request_cannot_be_undone_here(): void
    {
        $request = LeaveRequest::factory()->forType('annual')->approved()->create(['employee_id' => $this->people['staff']->id]);

        $this->undo($request->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'يمكن التراجع هنا عن الأعذار التي سجّلتها الموارد البشرية فقط؛ طلب الموظف يُقرَّر عبر مسار اعتماده.');
    }

    public function test_the_reason_is_optional_and_bounded(): void
    {
        $id = $this->fileExcuse();

        $this->undo($id, ['reason' => str_repeat('س', 2001)])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->undo($id)->assertOk()->assertJsonPath('data.leave_request.cancellation_reason', null);
    }

    // ── Who may undo ─────────────────────────────────────────────────────

    public function test_a_grant_that_does_not_reach_the_employee_is_refused(): void
    {
        $id = $this->fileExcuse(employee: $this->people['bStaff']);
        $this->actingAsScopedUser(self::BOTH, ScopeType::Unit, $this->units['a']->id);

        $this->undo($id)->assertForbidden()->assertJsonPath('message', 'هذا الموظف خارج نطاق صلاحياتك.');
        $this->assertSame(LeaveStatus::Approved, LeaveRequest::find($id)->status);
    }

    public function test_a_manager_scoped_over_the_unit_may_undo_it(): void
    {
        $id = $this->fileExcuse();

        // headA manages unit A; a «الوحدات التي أديرها» grant reaches A1.
        $role = Role::create(['name' => 'excuse-undo-'.uniqid()]);
        $role->permissions()->sync(Permission::where('name', 'leave.excuses.delete')->pluck('id'));
        $manager = $this->accountOf($this->people['headA']);
        $manager->roles()->attach($role->id, ['scope_type' => ScopeType::Managed->value]);
        Sanctum::actingAs($manager);

        $this->undo($id)->assertOk();
    }

    public function test_the_employee_cannot_undo_their_own_excuse(): void
    {
        $id = $this->fileExcuse();
        $this->actingAsPerson($this->people['staff']);

        $this->undo($id)->assertForbidden()->assertJsonPath('message', 'ليس لديك الصلاحيات الكافية لتنفيذ هذا الإجراء.');
    }
}
