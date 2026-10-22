<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\UserService;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The account model ratified 2026-09-22 (START_HERE.md §9).
 *
 * WHAT THIS PROTECTS. Attendance is recorded through the company app, so an
 * account is a payroll input: an employee who cannot sign in cannot be marked
 * present, and Stage 4 reads that silence as absence. Three properties make the
 * difference between that working and not, and each is asserted here rather
 * than described:
 *
 *   1. Staff sign in with an employee number, and an account needs no email.
 *   2. A password somebody else chose can do NOTHING until it is replaced.
 *   3. Replacing it ends every other session.
 *
 * Property 2 is the one most likely to be quietly broken by a later change —
 * a route added outside the `api` group, or a middleware reordering — which is
 * why it is asserted through HTTP against a real protected endpoint rather than
 * by calling the middleware directly.
 */
class AccountModelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An account for a site worker: an employee number, a password, no email.
     */
    private function siteWorker(string $number = 'EMP-00042', array $attributes = []): User
    {
        $employee = Employee::factory()->create(['employee_number' => $number]);

        return User::factory()->create(array_merge([
            'employee_id' => $employee->id,
            'email'       => null,
            'password'    => 'temporary-password',
        ], $attributes));
    }

    private function login(string $identifier, string $password = 'temporary-password'): TestResponse
    {
        return $this->postJson('/api/auth/login', [
            'login'    => $identifier,
            'password' => $password,
        ]);
    }

    public function test_an_employee_signs_in_with_their_employee_number(): void
    {
        $this->siteWorker();

        $this->login('EMP-00042')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token']]);
    }

    /**
     * The whole reason the column was made nullable. If this fails, most of the
     * company cannot have an account at all.
     */
    public function test_an_account_needs_no_email_address(): void
    {
        $user = $this->siteWorker();

        $this->assertNull($user->fresh()->email);
        $this->login('EMP-00042')->assertOk();
    }

    /**
     * Accounts with no employee — the technical department, super_admin — have
     * no number, so the email branch has to keep working.
     */
    public function test_an_account_without_an_employee_still_signs_in_by_email(): void
    {
        User::factory()->create([
            'employee_id' => null,
            'email'       => 'tech@alraqi.test',
            'password'    => 'temporary-password',
        ]);

        $this->login('tech@alraqi.test')->assertOk();
    }

    public function test_an_unknown_identifier_is_rejected_without_revealing_which_part_was_wrong(): void
    {
        $this->siteWorker();

        $this->login('EMP-99999')->assertUnauthorized();
        $this->login('EMP-00042', 'not-the-password')->assertUnauthorized();
    }

    /**
     * A number belonging to a soft-deleted employee must stop resolving — the
     * relation is matched with whereHas, which excludes trashed rows, and that
     * is deliberate rather than incidental.
     */
    public function test_a_deleted_employee_can_no_longer_sign_in_by_number(): void
    {
        $user = $this->siteWorker();
        $user->employee->delete();

        $this->login('EMP-00042')->assertUnauthorized();
    }

    // ── The forced change ────────────────────────────────────────────────

    public function test_a_temporary_password_cannot_reach_a_protected_endpoint(): void
    {
        $user = $this->siteWorker(attributes: ['must_change_password' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/hr/employees')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    /**
     * 403, not 401. A client that sees a 401 refreshes its token or bounces the
     * user to the login screen — both of which loop forever here, because the
     * token is fine and re-authenticating changes nothing.
     */
    public function test_the_refusal_is_not_an_authentication_failure(): void
    {
        $user = $this->siteWorker(attributes: ['must_change_password' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/departments')
            ->assertStatus(403);
    }

    public function test_the_three_allowed_routes_stay_reachable(): void
    {
        $user = $this->siteWorker(attributes: ['must_change_password' => true]);

        $this->actingAs($user, 'sanctum')->getJson('/api/auth/me')->assertOk();
        $this->actingAs($user, 'sanctum')->postJson('/api/auth/logout')->assertOk();
    }

    /**
     * The client cannot know to show the change screen unless the payload says
     * so — and if it guesses wrong it walks into a wall of 403s.
     */
    public function test_me_reports_that_the_password_must_change(): void
    {
        $user = $this->siteWorker(attributes: ['must_change_password' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true);
    }

    public function test_changing_the_password_clears_the_flag_and_opens_the_api(): void
    {
        $user = $this->siteWorker(attributes: ['must_change_password' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/password', [
                'current_password'      => 'temporary-password',
                'password'              => 'a-password-of-my-own',
                'password_confirmation' => 'a-password-of-my-own',
            ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token']]);

        $user->refresh();

        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->password_changed_at);

        $this->login('EMP-00042', 'a-password-of-my-own')->assertOk();
    }

    public function test_the_current_password_must_be_proved(): void
    {
        $user = $this->siteWorker(attributes: ['must_change_password' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/password', [
                'current_password'      => 'not-the-temporary-one',
                'password'              => 'a-password-of-my-own',
                'password_confirmation' => 'a-password-of-my-own',
            ])
            ->assertUnauthorized();

        $this->assertTrue($user->fresh()->must_change_password);
    }

    /**
     * Otherwise the flag clears while the credential HR handed out stays live,
     * which is the one outcome the flag exists to prevent.
     */
    public function test_the_new_password_must_differ_from_the_old_one(): void
    {
        $user = $this->siteWorker(attributes: ['must_change_password' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/password', [
                'current_password'      => 'temporary-password',
                'password'              => 'temporary-password',
                'password_confirmation' => 'temporary-password',
            ])
            ->assertStatus(422);

        $this->assertTrue($user->fresh()->must_change_password);
    }

    /**
     * The temporary password was known to whoever issued it. If someone else
     * used it first, the change has to end their session — otherwise it locks
     * nobody out and the whole ceremony is decorative.
     */
    public function test_changing_the_password_revokes_every_other_session(): void
    {
        $user = $this->siteWorker(attributes: ['must_change_password' => true]);

        $stolen = $this->login('EMP-00042')->assertOk()->json('data.refresh_token');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/password', [
                'current_password'      => 'temporary-password',
                'password'              => 'a-password-of-my-own',
                'password_confirmation' => 'a-password-of-my-own',
            ])
            ->assertOk();

        $this->postJson('/api/auth/refresh', ['refresh_token' => $stolen])
            ->assertUnauthorized();
    }

    // ── Who sets the flag ────────────────────────────────────────────────

    /**
     * The rule lives in UserService rather than in the importer, so it covers
     * the 242 accounts the import will make AND the one an admin makes next
     * month. A password chosen by somebody else is a shared secret either way.
     */
    public function test_an_admin_created_account_must_change_its_password(): void
    {
        $employee = Employee::factory()->create();

        $user = app(UserService::class)->create([
            'employee_id' => $employee->id,
            'password'    => 'issued-by-hr',
        ]);

        $this->assertTrue($user->must_change_password);
    }

    public function test_an_admin_password_reset_forces_a_change_again(): void
    {
        $user = $this->siteWorker(attributes: ['must_change_password' => false]);

        app(UserService::class)
            ->update($user, ['password' => 'reset-by-hr']);

        $this->assertTrue($user->fresh()->must_change_password);
    }

    /**
     * An update that does not touch the password must not re-raise the flag —
     * otherwise deactivating someone, or correcting their email, would lock
     * them out of an account whose password is perfectly fine.
     */
    public function test_an_unrelated_update_does_not_force_a_change(): void
    {
        $user = $this->siteWorker(attributes: ['must_change_password' => false]);

        app(UserService::class)
            ->update($user, ['is_active' => true]);

        $this->assertFalse($user->fresh()->must_change_password);
    }
}
