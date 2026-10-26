<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\UserService;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\Gender;
use App\Modules\HR\Models\Employee;
use App\Shared\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * An employee added by hand gets their account in the same request, as the
 * import's do (EMPLOYEE_ACCOUNT_ON_CREATE_DESIGN.md): «كل موظف له حساب دخول».
 */
class EmployeeAccountOnCreateTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $overrides */
    private function store(array $overrides = []): TestResponse
    {
        $this->actingAsAdmin();

        return $this->postJson('/api/hr/employees', array_merge([
            'employee_number' => '582974643',
            'first_name'      => 'نور',
            'middle_name'     => 'أحمد',
            'last_name'       => 'الشامي',
            'gender'          => Gender::Female->value,
        ], $overrides));
    }

    public function test_store_creates_the_account_and_returns_its_password_once(): void
    {
        $response = $this->store()
            ->assertCreated()
            ->assertJsonPath('message', 'تم إنشاء الموظف وحساب دخوله.')
            ->assertJsonPath('account.login', '582974643');

        $employee = Employee::where('employee_number', '582974643')->sole();
        $user = User::where('employee_id', $employee->id)->sole();
        $this->assertSame($user->id, $response->json('account.user_id'));
        $this->assertTrue($user->must_change_password);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->email);
        $this->assertSame(0, $user->roles()->count());

        $password = $response->json('account.temporary_password');
        $this->assertMatchesRegularExpression('/^[abcdefghjkmnpqrstuvwxyz23456789]{10}$/', $password);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['login' => '582974643', 'password' => $password])->assertOk();
        $this->postJson('/api/auth/login', ['login' => '582974643', 'password' => 'not-'.$password])->assertUnauthorized();
    }

    public function test_the_account_is_audited_and_its_password_is_nowhere_else(): void
    {
        $response = $this->store()->assertCreated();
        $password = $response->json('account.temporary_password');
        $employeeId = $response->json('data.id');

        $this->assertTrue(AuditLog::where('event', 'user_created')->exists());
        $this->assertStringNotContainsString($password, AuditLog::all()->toJson());
        $this->assertStringNotContainsString($password, $this->getJson("/api/hr/employees/{$employeeId}")->assertOk()->getContent());
        $this->assertStringNotContainsString($password, $this->getJson('/api/auth/users')->assertOk()->getContent());
    }

    public function test_a_terminated_employee_gets_a_disabled_account_and_no_password(): void
    {
        $response = $this->store(['status' => EmployeeStatus::Terminated->value])->assertCreated();

        $this->assertNull($response->json('account.temporary_password'));
        $this->assertFalse(User::where('employee_id', $response->json('data.id'))->sole()->is_active);
    }

    public function test_a_suspended_employee_gets_an_enabled_account_and_a_password(): void
    {
        $response = $this->store(['status' => EmployeeStatus::Suspended->value])->assertCreated();

        $this->assertNotNull($response->json('account.temporary_password'));
        $this->assertTrue(User::where('employee_id', $response->json('data.id'))->sole()->is_active);
    }

    public function test_an_account_that_cannot_be_created_takes_the_employee_with_it(): void
    {
        $this->mock(UserService::class)->shouldReceive('create')->andThrow(new RuntimeException('no account'));

        $this->store()->assertServerError();

        $this->assertSame(0, Employee::withTrashed()->where('employee_number', '582974643')->count());
    }
}
