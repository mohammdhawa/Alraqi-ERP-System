<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Leave\Concerns\LeaveFixtures;
use Tests\TestCase;

/**
 * An employee who is not on duty — suspended or terminated — takes no action,
 * and no action is taken on them (the project owner, 2026-10-20, parity audit
 * C-3): their records stay readable, but nothing new is written.
 *
 * - A suspended employee cannot sign in, and suspending them ends the sessions
 *   they had open. (Termination already disables the account.) Back on duty,
 *   they sign in again with the same account.
 * - Their own leave: no filing, no withdrawal.
 * - HR's acts on them: no excuse or its undo, no return to work, no balance
 *   change, no check-out correction, no refusal or its undo.
 */
class InactiveEmployeeTest extends TestCase
{
    use LeaveFixtures;
    use RefreshDatabase;

    private const REFUSAL = 'لا يمكن تنفيذ هذا الإجراء: الموظف ليس على رأس عمله.';

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::for('auth-login', fn () => Limit::none());
        RateLimiter::for('auth-refresh', fn () => Limit::none());
        $this->buildLeaveTree();
    }

    private function suspend(Employee $employee): void
    {
        $employee->update(['status' => EmployeeStatus::Suspended]);
    }

    private function signIn(Employee $employee): TestResponse
    {
        return $this->postJson('/api/auth/login', ['login' => $employee->employee_number, 'password' => 'secret-password']);
    }

    private function withPassword(Employee $employee): User
    {
        $user = $this->accountOf($employee);
        $user->update(['password' => Hash::make('secret-password')]);

        return $user;
    }

    private function asHr(array $permissions): void
    {
        $this->actingAsScopedUser($permissions, ScopeType::Company);
    }

    // ── Signing in ───────────────────────────────────────────────────────

    public function test_a_suspended_employee_cannot_sign_in(): void
    {
        $this->withPassword($this->people['headA']);
        $this->suspend($this->people['headA']);

        $this->signIn($this->people['headA'])
            ->assertForbidden()
            ->assertJsonPath('message', 'لا يمكن تسجيل الدخول: الموظف موقوف عن العمل.');
    }

    public function test_suspending_ends_the_sessions_already_open(): void
    {
        $user = $this->withPassword($this->people['headA']);
        $tokens = $this->signIn($this->people['headA'])->assertOk()->json('data');

        $this->suspend($this->people['headA']);

        $this->assertSame(0, $user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($tokens['access_token'])->getJson('/api/auth/me')->assertUnauthorized();
        $this->postJson('/api/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertUnauthorized();
    }

    public function test_the_account_stays_enabled_and_works_again_once_back_on_duty(): void
    {
        $user = $this->withPassword($this->people['headA']);
        $this->suspend($this->people['headA']);
        $this->assertTrue($user->fresh()->is_active);

        $this->people['headA']->update(['status' => EmployeeStatus::Active]);

        $this->signIn($this->people['headA'])->assertOk();
    }

    public function test_an_account_with_no_employee_still_signs_in(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret-password')]);

        $this->postJson('/api/auth/login', ['login' => $user->email, 'password' => 'secret-password'])->assertOk();
    }

    // ── Their own leave ──────────────────────────────────────────────────

    public function test_a_suspended_employee_cannot_file_leave(): void
    {
        $this->suspend($this->people['staff']);
        Sanctum::actingAs($this->accountOf($this->people['staff']));

        $this->postJson('/api/me/leave-requests', [
            'leave_type_id' => $this->type('annual')->id, 'start_date' => '2026-06-21', 'end_date' => '2026-06-22',
        ])->assertUnprocessable()->assertJsonPath('message', self::REFUSAL);

        $this->assertSame(0, LeaveRequest::count());
    }

    public function test_a_suspended_employee_cannot_withdraw_leave(): void
    {
        $this->actingAsPerson($this->people['staff']);
        $id = $this->postJson('/api/me/leave-requests', [
            'leave_type_id' => $this->type('annual')->id, 'start_date' => '2026-06-21', 'end_date' => '2026-06-22',
        ])->assertCreated()->json('data.leave_request.id');

        $this->suspend($this->people['staff']);
        Sanctum::actingAs($this->accountOf($this->people['staff']));

        $this->postJson("/api/me/leave-requests/{$id}/cancel")
            ->assertUnprocessable()->assertJsonPath('message', self::REFUSAL);
    }

    // ── HR's acts on them ────────────────────────────────────────────────

    public function test_hr_cannot_file_an_excuse_for_a_suspended_or_terminated_employee(): void
    {
        $this->suspend($this->people['staff']);
        $this->people['bStaff']->update(['status' => EmployeeStatus::Terminated]);
        $this->asHr(['leave.excuses.create']);

        foreach (['staff', 'bStaff'] as $key) {
            $this->postJson('/api/leave/excuses', [
                'employee_id'   => $this->people[$key]->id,
                'leave_type_id' => $this->type('annual')->id,
                'start_date'    => '2026-06-21',
                'end_date'      => '2026-06-21',
            ])->assertUnprocessable()->assertJsonPath('message', self::REFUSAL);
        }

        $this->assertSame(0, LeaveRequest::count());
    }

    public function test_hr_cannot_undo_an_excuse_of_a_suspended_employee(): void
    {
        $excuse = LeaveRequest::factory()->forType('annual')->excuse()->create([
            'employee_id' => $this->people['staff']->id, 'start_date' => '2026-06-21', 'end_date' => '2026-06-21', 'requested_days' => 1,
        ]);
        $this->suspend($this->people['staff']);
        $this->asHr(['leave.excuses.delete']);

        $this->deleteJson("/api/leave/excuses/{$excuse->id}")
            ->assertUnprocessable()->assertJsonPath('message', self::REFUSAL);
    }

    public function test_hr_cannot_record_a_return_to_work_for_a_suspended_employee(): void
    {
        $leave = LeaveRequest::factory()->forType('annual')->approved()->create([
            'employee_id' => $this->people['staff']->id, 'start_date' => '2026-06-21', 'end_date' => '2026-06-25', 'requested_days' => 5,
        ]);
        $this->suspend($this->people['staff']);
        $this->asHr(['leave.requests.returnToWork']);

        $this->postJson("/api/leave/requests/{$leave->id}/return-to-work", ['returned_on' => '2026-06-23'])
            ->assertUnprocessable()->assertJsonPath('message', self::REFUSAL);

        $this->assertSame('2026-06-25', $leave->fresh()->end_date->toDateString());
    }

    public function test_hr_cannot_change_a_suspended_employees_balance(): void
    {
        $this->suspend($this->people['staff']);
        $this->asHr(['leave.balances.update']);

        $this->putJson("/api/leave/balances/{$this->people['staff']->id}/2026", ['allocated_days' => 20])
            ->assertUnprocessable()->assertJsonPath('message', self::REFUSAL);
    }

    public function test_hr_cannot_correct_refuse_or_unrefuse_a_suspended_employees_record(): void
    {
        $checkIn = AttendanceRecord::factory()->checkIn()->create(['employee_id' => $this->people['staff']->id, 'recorded_at' => '2026-06-21 08:00:00']);
        $refused = AttendanceRecord::factory()->checkIn()->create([
            'employee_id' => $this->people['staff']->id, 'recorded_at' => '2026-06-22 08:00:00',
            'rejected_at' => '2026-06-22 12:00:00', 'rejection_reason' => 1, 'rejection_batch' => 'batch-1',
        ]);
        $this->suspend($this->people['staff']);
        $this->asHr(['attendance.records.correct', 'attendance.records.reject']);

        $this->patchJson("/api/attendance/records/{$checkIn->id}/checkout", ['checked_out_at' => '2026-06-21 16:00:00'])
            ->assertUnprocessable()->assertJsonPath('message', self::REFUSAL);
        $this->patchJson("/api/attendance/records/{$checkIn->id}/reject", ['reason' => 1])
            ->assertUnprocessable()->assertJsonPath('message', self::REFUSAL);
        $this->deleteJson("/api/attendance/records/{$refused->id}/reject")
            ->assertUnprocessable()->assertJsonPath('message', self::REFUSAL);

        $this->assertSame(2, AttendanceRecord::count());
        $this->assertNull($checkIn->fresh()->rejected_at);
        $this->assertNotNull($refused->fresh()->rejected_at);
    }

    public function test_their_records_stay_readable(): void
    {
        $this->suspend($this->people['staff']);
        $this->asHr(['leave.balances.view']);

        $this->getJson('/api/leave/balances?year=2026')
            ->assertOk()
            ->assertJsonFragment(['id' => $this->people['staff']->id]);
    }
}
