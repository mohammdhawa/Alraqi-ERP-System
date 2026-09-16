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
            ->assertJsonFragment(['super_admin']); // the acting user's role name
    }

    /**
     * The list says WHO each account belongs to, not just an id to go and
     * resolve. `employee_id` is untouched — it is the write contract — and
     * `employee` is the same person as an object beside it (C31).
     *
     * `employee_number` is in the stub because two people at this headcount
     * genuinely share الاسم الثلاثي, so the name alone does not identify the
     * account holder on screen.
     */
    public function test_users_index_embeds_the_linked_employee(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->named('سامر خليل')->create();
        User::factory()->create(['employee_id' => $employee->id, 'email' => 'samer@example.com']);

        $row = collect($this->getJson('/api/auth/users')->assertOk()->json('data'))
            ->firstWhere('email', 'samer@example.com');

        $this->assertSame($employee->id, $row['employee_id']);
        $this->assertSame([
            'id'              => $employee->id,
            'full_name'       => 'سامر خليل',
            'employee_number' => $employee->employee_number,
        ], $row['employee']);
    }

    /**
     * An unlinked account (employee_id null — a service account, or one whose
     * employee was deleted) reports `employee: null`.
     *
     * ⚠️ NULL, NOT ABSENT, and that is the whole point of the assertion. In
     * this API a missing key means "you may not see this field" (C11), so an
     * embedded object that vanished would tell a client it lacks permission
     * when the truth is there is nobody to name.
     */
    public function test_an_unlinked_account_reports_a_null_employee_rather_than_omitting_it(): void
    {
        $admin = $this->actingAsAdmin(); // the factory leaves employee_id null

        $row = collect($this->getJson('/api/auth/users')->assertOk()->json('data'))
            ->firstWhere('email', $admin->email);

        $this->assertNull($row['employee_id']);
        $this->assertArrayHasKey('employee', $row, 'the embed must be present even with nobody to embed');
        $this->assertNull($row['employee']);
    }

    /**
     * The embed is eager-loaded, not resolved per row.
     *
     * This is a paginated list, so a lazy load would be one `employees` query
     * PER ACCOUNT — invisible with the two rows most tests create and a page of
     * 15 queries once the real staff have logins. Asserting the count is what
     * keeps `with('employee')` in UserService from being dropped as decoration.
     */
    public function test_the_employee_embed_does_not_n_plus_one(): void
    {
        $this->actingAsAdmin();

        foreach (range(1, 3) as $i) {
            User::factory()->create([
                'employee_id' => Employee::factory()->named("موظف {$i}")->create()->id,
            ]);
        }

        $queries = [];
        \DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'from "employees"')) {
                $queries[] = $query->sql;
            }
        });

        $this->getJson('/api/auth/users')->assertOk();

        $this->assertCount(
            1,
            $queries,
            'expected ONE employees query for the whole page; got '.count($queries)
                .' — the employee relation is probably being lazy-loaded per user again',
        );
    }

    public function test_create_user(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->named('New Person')->create();

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
            // …and the same person as an object, on the create path too.
            ->assertJsonPath('data.employee.id', $employee->id)
            ->assertJsonPath('data.employee.full_name', 'New Person')
            ->assertJsonPath('data.employee.employee_number', $employee->employee_number)
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
        $employee = Employee::factory()->named('Taken')->create();
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
        $employee = Employee::factory()->named('Clash')->create();

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
        $employee = Employee::factory()->named('Jane Employee')->create();
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
        $first  = Employee::factory()->named('First Person')->create();
        $second = Employee::factory()->named('Second Person')->create();
        $user   = User::factory()->create(['employee_id' => $first->id]);

        $this->putJson("/api/auth/users/{$user->id}", [
            'employee_id' => $second->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Second Person')
            ->assertJsonPath('data.employee_id', $second->id)
            // The embed follows the re-link. UserService reloads the relation
            // after the write, so this cannot echo the employee the model was
            // holding when the request came in.
            ->assertJsonPath('data.employee.id', $second->id)
            ->assertJsonPath('data.employee.full_name', 'Second Person');

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

    public function test_delete_user_soft_deletes_it_and_preserves_audit_attribution(): void
    {
        $this->actingAsAdmin();
        $target = User::factory()->create();

        // An action by the target, recorded BEFORE they are deleted. This is the
        // row the old hard delete used to anonymise.
        $target->update(['is_active' => false]);

        $this->deleteJson("/api/auth/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        // The row survives, so every reference to it stays resolvable.
        $this->assertSoftDeleted('users', ['id' => $target->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user_deleted']);

        // The point of the whole change: audit rows still name who did it.
        // audit_logs.user_id is nullOnDelete, so a hard delete nulled these.
        $this->assertDatabaseHas('audit_logs', [
            // Morph alias, not the class name (C12) — audit rows survive a class
            // moving between modules, which the legacy merge will do.
            'auditable_type' => 'user',
            'auditable_id'   => $target->id,
            'event'          => 'updated',
        ]);

        // The account is gone as far as every normal query is concerned.
        $this->assertNull(User::find($target->id));
    }

    public function test_deleted_user_cannot_authenticate(): void
    {
        $this->actingAsAdmin();
        $target = User::factory()->create();
        $token  = $target->createToken('test')->plainTextToken;

        $this->deleteJson("/api/auth/users/{$target->id}")->assertOk();

        // Sanctum::actingAs (used by actingAsAdmin) pins the guard's user for
        // the rest of the test and would shadow the bearer token below, so drop
        // the resolved guards first and let the header actually be the identity.
        $this->app['auth']->forgetGuards();

        // A soft delete does not fire the FK cascade that used to clear tokens,
        // so the service revokes them explicitly. Either way the answer is 401.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
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

    /**
     * §10 test 2. Users and Roles were the only two resources with no
     * unauthenticated check at all.
     */
    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/auth/users')->assertUnauthorized();
    }

    /**
     * §10 test 4 — the one people skip, and the one that catches the
     * highest-impact mistake: a write route guarded by .view.
     *
     * A ROLELESS user (which is what the other tests here use) proves only that
     * *something* is enforced. This proves the write routes are guarded by their
     * OWN permissions, by giving the caller read access and nothing else.
     */
    public function test_a_read_only_user_cannot_write(): void
    {
        $target   = User::factory()->create();
        $employee = Employee::factory()->named('Someone')->create();

        $this->actingAsUserWithPermissions(['auth.users.view']);

        $this->getJson('/api/auth/users')->assertOk();

        $this->postJson('/api/auth/users', [
            'employee_id' => $employee->id,
            'email'       => 'nope@example.com',
            'password'    => 'secret-password',
        ])->assertForbidden();

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
