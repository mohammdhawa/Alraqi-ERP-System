<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * User administration coverage (auth.users.* permissions).
 *
 * Verifies the user CRUD endpoints and that CheckPermission enforces each one:
 * a role-less user is rejected with 403, while an admin can list, create,
 * update, and delete accounts. Self-deletion is blocked as a lockout guard.
 *
 * Accounts carry no name of their own: creating one links an employee, and the
 * account's display name is that employee's name.
 */
class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_index_lists_users_with_roles(): void
    {
        $admin = $this->actingAsAdmin();

        $this->getJson('/api/auth/users')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['email' => $admin->email])
            ->assertJsonFragment(['admin']); // the admin's role name
    }

    public function test_create_user(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::create(['name' => 'New Person']);

        $this->postJson('/api/auth/users', [
            'employee_id' => $employee->id,
            'email'       => 'new.person@example.com',
            'password'    => 'secret-password',
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            // The account's display name is the linked employee's name.
            ->assertJsonPath('data.name', 'New Person')
            ->assertJsonPath('data.email', 'new.person@example.com')
            ->assertJsonPath('data.employee_id', $employee->id)
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('users', [
            'email'       => 'new.person@example.com',
            'employee_id' => $employee->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user_created']);

        // Password is stored hashed, never in plaintext.
        $user = User::where('email', 'new.person@example.com')->first();
        $this->assertNotSame('secret-password', $user->password);
        $this->assertTrue(Hash::check('secret-password', $user->password));
    }

    public function test_create_user_requires_an_employee_link(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/auth/users', [
            'email'    => 'orphan@example.com',
            'password' => 'secret-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employee_id');
    }

    public function test_create_user_rejects_employee_already_linked(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::create(['name' => 'Taken']);
        User::factory()->create(['employee_id' => $employee->id]);

        $this->postJson('/api/auth/users', [
            'employee_id' => $employee->id, // already owns an account
            'email'       => 'second@example.com',
            'password'    => 'secret-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employee_id');
    }

    public function test_create_user_rejects_duplicate_email_and_short_password(): void
    {
        $admin = $this->actingAsAdmin();
        $employee = Employee::create(['name' => 'Clash']);

        $this->postJson('/api/auth/users', [
            'employee_id' => $employee->id, // valid, so only email+password fail
            'email'       => $admin->email, // already taken
            'password'    => 'short',       // < 8 chars
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_create_user_forbidden_without_permission(): void
    {
        $this->actingAsRolelessUser();

        $this->postJson('/api/auth/users', [
            'email'    => 'nope@example.com',
            'password' => 'secret-password',
        ])->assertForbidden();
    }

    public function test_update_user_changes_fields_and_password(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::create(['name' => 'Jane Employee']);
        $user = User::factory()->create([
            'email'       => 'old@example.com',
            'is_active'   => true,
            'employee_id' => $employee->id,
        ]);

        $this->putJson("/api/auth/users/{$user->id}", [
            'email'     => 'updated@example.com',
            'password'  => 'brand-new-password',
            'is_active' => false,
        ])
            ->assertOk()
            // Name is unchanged — it tracks the linked employee, not the payload.
            ->assertJsonPath('data.name', 'Jane Employee')
            ->assertJsonPath('data.email', 'updated@example.com')
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('users', [
            'id'        => $user->id,
            'email'     => 'updated@example.com',
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user_updated']);

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
    }

    public function test_update_user_can_relink_employee(): void
    {
        $this->actingAsAdmin();
        $first  = Employee::create(['name' => 'First Person']);
        $second = Employee::create(['name' => 'Second Person']);
        $user   = User::factory()->create(['employee_id' => $first->id]);

        $this->putJson("/api/auth/users/{$user->id}", [
            'employee_id' => $second->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Second Person')
            ->assertJsonPath('data.employee_id', $second->id);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'employee_id' => $second->id]);
    }

    public function test_update_user_ignores_empty_password(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->create(['password' => Hash::make('keep-this-password')]);

        $this->putJson("/api/auth/users/{$user->id}", [
            'password' => '', // empty: must not change the password
        ])->assertOk();

        // The original password still verifies.
        $this->assertTrue(Hash::check('keep-this-password', $user->fresh()->password));
    }

    public function test_delete_user_removes_it(): void
    {
        $this->actingAsAdmin();
        $target = User::factory()->create();

        $this->deleteJson("/api/auth/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user_deleted']);
    }

    public function test_cannot_delete_own_account(): void
    {
        $admin = $this->actingAsAdmin();

        $this->deleteJson("/api/auth/users/{$admin->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكنك حذف حسابك الخاص.');

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_update_and_delete_forbidden_without_permission(): void
    {
        $this->seedRbac();
        $target = User::factory()->create();

        $this->actingAsRolelessUser();

        $this->putJson("/api/auth/users/{$target->id}", ['is_active' => false])->assertForbidden();
        $this->deleteJson("/api/auth/users/{$target->id}")->assertForbidden();
    }

    public function test_users_index_forbidden_without_permission(): void
    {
        $this->actingAsRolelessUser();

        $this->getJson('/api/auth/users')
            ->assertForbidden()
            ->assertJsonPath('message', 'ليس لديك الصلاحيات الكافية لتنفيذ هذا الإجراء.');
    }
}
