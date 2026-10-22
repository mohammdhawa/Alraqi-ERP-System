<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Termination disables the account (EMPLOYEE_APP_REQUIREMENTS.md §7.1 C,
 * work order §4.1).
 *
 * Attendance runs through the app, so an account a departed employee can still
 * use is a working attendance app in the wrong hands. The rule is bound at the
 * model, not the endpoint, because the Batch 3 importer and any console path
 * terminate people without going through HTTP.
 *
 * Model-level cases call the model directly; one case goes through the API to
 * prove the endpoint reaches the same hook.
 */
class TerminationDisablesAccountTest extends TestCase
{
    use RefreshDatabase;

    private function employeeWithAccount(): array
    {
        $employee = Employee::factory()->named('نور الشامي')->create();
        $user = User::factory()->create([
            'employee_id' => $employee->id,
            'password'    => Hash::make('secret-password'),
        ]);

        return [$employee, $user];
    }

    /** @return array{access_token: string, refresh_token: string} */
    private function signIn(Employee $employee): array
    {
        return $this->postJson('/api/auth/login', [
            'login'    => $employee->employee_number,
            'password' => 'secret-password',
        ])->assertOk()->json('data');
    }

    public function test_terminating_an_employee_disables_their_account(): void
    {
        [$employee, $user] = $this->employeeWithAccount();

        $employee->update(['status' => EmployeeStatus::Terminated]);

        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_terminating_ends_the_sessions_already_open(): void
    {
        [$employee, $user] = $this->employeeWithAccount();
        $tokens = $this->signIn($employee);

        $employee->update(['status' => EmployeeStatus::Terminated]);

        // Disabling alone would leave the access token working until it
        // expires; the app's next request has to fail, not the one after.
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(0, $user->refreshTokens()->where('revoked', false)->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($tokens['access_token'])->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_the_disabled_account_is_recorded_in_the_audit_log(): void
    {
        [$employee, $user] = $this->employeeWithAccount();

        $employee->update(['status' => EmployeeStatus::Terminated]);

        $this->assertDatabaseHas('audit_logs', [
            'event'          => 'updated',
            'auditable_type' => $user->getMorphClass(),
            'auditable_id'   => $user->id,
        ]);
    }

    public function test_terminating_through_the_api_disables_the_account_too(): void
    {
        [$employee, $user] = $this->employeeWithAccount();
        $this->actingAsAdmin();

        $this->putJson("/api/hr/employees/{$employee->id}", [
            'status'           => EmployeeStatus::Terminated->value,
            'termination_date' => now()->toDateString(),
        ])->assertOk();

        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_rehiring_does_not_re_enable_the_account(): void
    {
        [$employee, $user] = $this->employeeWithAccount();
        $employee->update(['status' => EmployeeStatus::Terminated]);

        $employee->update(['status' => EmployeeStatus::Active, 'termination_date' => null]);

        // Re-enabling is somebody deciding this person may sign in again —
        // an administrator's act, not a side effect of a status edit.
        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_suspending_leaves_the_account_alone(): void
    {
        [$employee, $user] = $this->employeeWithAccount();

        $employee->update(['status' => EmployeeStatus::Suspended]);

        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_an_employee_without_an_account_can_be_terminated(): void
    {
        $employee = Employee::factory()->named('سامر الحلبي')->create();

        $employee->update(['status' => EmployeeStatus::Terminated]);

        $this->assertSame(EmployeeStatus::Terminated, $employee->fresh()->status);
    }

    public function test_editing_a_terminated_employee_leaves_a_re_enabled_account_alone(): void
    {
        [$employee, $user] = $this->employeeWithAccount();
        $employee->update(['status' => EmployeeStatus::Terminated]);
        $user->fresh()->update(['is_active' => true]);

        $employee->update(['phone' => '0999000111']);

        // The hook acts on the change to terminated, not on every save of a
        // terminated row: an administrator who re-enabled the account on
        // purpose is not overruled by a phone edit.
        $this->assertTrue($user->fresh()->is_active);
    }
}
