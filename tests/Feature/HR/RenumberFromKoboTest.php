<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeImportSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * hr:renumber-from-kobo — development rows numbered before the Kobo id became
 * the number get it now (KOBO_EMPLOYEE_NUMBER_DESIGN.md §5).
 */
class RenumberFromKoboTest extends TestCase
{
    use RefreshDatabase;

    /** An imported person: number $number, key kobo:$kobo, an account signed in once. */
    private function imported(string $number, string $kobo): Employee
    {
        $employee = Employee::factory()->create(['employee_number' => $number]);
        EmployeeImportSource::remember("kobo:{$kobo}", $employee);
        User::factory()->create(['employee_id' => $employee->id, 'password' => 'a-password-of-mine', 'must_change_password' => false]);
        $this->postJson('/api/auth/login', ['login' => $number, 'password' => 'a-password-of-mine'])->assertOk();

        return $employee;
    }

    public function test_imported_people_get_their_kobo_id_and_lose_their_sessions(): void
    {
        $employee = $this->imported('700000001', '582974643');
        $user = User::where('employee_id', $employee->id)->sole();
        $this->assertSame(1, $user->refreshTokens()->count());

        $this->artisan('hr:renumber-from-kobo')
            ->expectsOutputToContain('Renumbered 1, already numbered 0, skipped 0 (number taken), refused 0, deleted 0 (left alone).')
            ->assertSuccessful();

        $this->assertSame('582974643', $employee->fresh()->employee_number);
        $this->assertSame(0, $user->refreshTokens()->count(), 'their login changed');
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_deleted_people_are_left_alone(): void
    {
        // A deleted person holds no Kobo id (I1): giving them one would keep
        // it from its next owner.
        $gone = $this->imported('700000001', '582974643');
        $gone->delete();

        $this->artisan('hr:renumber-from-kobo')
            ->expectsOutputToContain('Renumbered 0, already numbered 0, skipped 0 (number taken), refused 0, deleted 1 (left alone).')
            ->assertSuccessful();

        $this->assertSame("700000001-deleted-{$gone->id}", Employee::withTrashed()->find($gone->id)->employee_number);
    }

    public function test_people_without_a_kobo_key_are_left_alone(): void
    {
        $byHand = Employee::factory()->create(['employee_number' => '700000002']);

        $this->artisan('hr:renumber-from-kobo')->assertSuccessful();

        $this->assertSame('700000002', $byHand->fresh()->employee_number);
    }

    public function test_running_twice_changes_nothing(): void
    {
        $employee = $this->imported('700000001', '582974643');
        $this->artisan('hr:renumber-from-kobo')->assertSuccessful();

        $this->artisan('hr:renumber-from-kobo')
            ->expectsOutputToContain('Renumbered 0, already numbered 1, skipped 0 (number taken), refused 0, deleted 0 (left alone).')
            ->assertSuccessful();

        $this->assertSame('582974643', $employee->fresh()->employee_number);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $employee = $this->imported('700000001', '582974643');
        $user = User::where('employee_id', $employee->id)->sole();

        $this->artisan('hr:renumber-from-kobo', ['--dry-run' => true])
            ->expectsOutputToContain('Renumbered 1, already numbered 0, skipped 0 (number taken), refused 0, deleted 0 (left alone).')
            ->assertSuccessful();

        $this->assertSame('700000001', $employee->fresh()->employee_number);
        $this->assertSame(1, $user->refreshTokens()->count());
    }

    public function test_a_taken_number_is_skipped_and_reported(): void
    {
        $blocked = $this->imported('700000001', '582974643');
        $holder = Employee::factory()->create(['employee_number' => '582974643']);
        $free = $this->imported('700000003', '582974644');

        $this->artisan('hr:renumber-from-kobo')
            ->expectsOutputToContain('Renumbered 1, already numbered 0, skipped 1 (number taken), refused 0, deleted 0 (left alone).')
            ->expectsOutputToContain("Skipped employee #{$blocked->id}: 582974643 is held by employee #{$holder->id}.")
            ->assertSuccessful();

        $this->assertSame('700000001', $blocked->fresh()->employee_number);
        $this->assertSame('582974644', $free->fresh()->employee_number);
    }

    public function test_a_refused_number_is_reported_and_the_rest_are_still_renumbered(): void
    {
        // A key the rule now refuses (leading zero) — the model says no.
        $bad = $this->imported('700000001', '0582974643');
        $good = $this->imported('700000003', '582974644');

        $this->artisan('hr:renumber-from-kobo')
            ->expectsOutputToContain('Renumbered 1, already numbered 0, skipped 0 (number taken), refused 1, deleted 0 (left alone).')
            ->expectsOutputToContain("Refused employee #{$bad->id} (kobo:0582974643): رقم كوبو يتكوّن من أرقام فقط.")
            ->assertSuccessful();

        $this->assertSame('700000001', $bad->fresh()->employee_number);
        $this->assertSame('582974644', $good->fresh()->employee_number);
    }

    public function test_a_dry_run_meets_the_same_refusals_as_the_real_run(): void
    {
        $bad = $this->imported('700000001', '0582974643');
        $good = $this->imported('700000003', '582974644');
        $user = User::where('employee_id', $good->id)->sole();

        $this->artisan('hr:renumber-from-kobo', ['--dry-run' => true])
            ->expectsOutputToContain('Renumbered 1, already numbered 0, skipped 0 (number taken), refused 1, deleted 0 (left alone).')
            ->assertSuccessful();

        $this->assertSame('700000001', $bad->fresh()->employee_number);
        $this->assertSame('700000003', $good->fresh()->employee_number);
        $this->assertSame(1, $user->refreshTokens()->count(), 'the rollback keeps their session');
    }
}
