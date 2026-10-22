<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Requests\EmployeeRequest;
use App\Modules\HR\Resources\EmployeeResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * GAP-12 — من لا يرى لا يكتُب (ratified 2026-09-23).
 *
 * The gap this closes was not theoretical. `hr.employees.viewSalary` decided who
 * could SEE a salary, a Sham Cash account and the nine classification axes;
 * setting them needed nothing but `hr.employees.update`. So a clerk could write
 * a pay grade, save it, and be refused the same value on the way back out —
 * and Batch 2 made it worse by widening the read gate to the axes without
 * touching the write side.
 *
 * EVERY ACTOR HERE HOLDS EXACTLY WHAT IT SAYS AND NOTHING MORE. A super admin
 * bypasses permission checks entirely, so using one would make each assertion
 * below pass for the wrong reason.
 *
 * ⚠️ THE LAST TEST IS THE ONE THAT MATTERS IN A YEAR. Closing the gap once is
 * easy; keeping the two lists in step as pay columns are added is not.
 * `test_the_write_gate_matches_the_read_gate` derives the read-gated set from
 * the Resource itself and compares it, so a column added to one side and
 * forgotten on the other fails the build instead of quietly reopening GAP-12.
 */
class EmployeeWriteGateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The permissions an ordinary HR editor holds: they may read and change
     * employees, and they may not see pay or national ids.
     *
     * @var array<int, string>
     */
    private const EDITOR = [
        'hr.employees.view',
        'hr.employees.create',
        'hr.employees.update',
    ];

    /**
     * A user holding exactly these catalogue permissions, NOT authenticated.
     *
     * The inherited `actingAsUserWithPermissions()` signs the user in, which is
     * right for the HTTP tests and wrong for the drift check — that one renders
     * the Resource twice for two different actors within a single test, so it
     * needs the users as objects rather than as the current session.
     *
     * @param  array<int, string>  $permissions
     */
    private function userHolding(array $permissions): User
    {
        $this->seedRbac();

        $role = Role::create(['name' => 'gate-test-'.uniqid()]);
        $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));

        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
    }

    // ── The salary gate ──────────────────────────────────────────────────

    public function test_an_editor_without_view_salary_cannot_set_a_salary(): void
    {
        $this->actingAsUserWithPermissions(self::EDITOR);
        $employee = Employee::factory()->create();

        $this->patchJson("/api/hr/employees/{$employee->id}", [
            'salary_mode'   => 2,
            'agreed_salary' => '750000.00',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['salary_mode', 'agreed_salary']);

        $this->assertNull($employee->fresh()->agreed_salary);
    }

    public function test_an_editor_without_view_salary_cannot_set_a_classification_axis(): void
    {
        $this->actingAsUserWithPermissions(self::EDITOR);
        $employee = Employee::factory()->create();

        $this->patchJson("/api/hr/employees/{$employee->id}", ['organizational_grade' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['organizational_grade']);

        $this->assertNull($employee->fresh()->organizational_grade);
    }

    /**
     * The trap `prohibited` would have fallen into: it treats an empty value as
     * acceptable, so a null would have CLEARED a salary the caller cannot read.
     */
    public function test_a_null_does_not_slip_past_the_gate_and_clear_the_field(): void
    {
        $this->actingAsUserWithPermissions(self::EDITOR);
        $employee = Employee::factory()->create([
            'salary_mode'   => 2,
            'agreed_salary' => '500000.00',
        ]);

        $this->patchJson("/api/hr/employees/{$employee->id}", ['agreed_salary' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['agreed_salary']);

        $this->assertSame('500000.00', $employee->fresh()->agreed_salary);
    }

    public function test_the_refusal_names_the_field_in_arabic(): void
    {
        $this->actingAsUserWithPermissions(self::EDITOR);
        $employee = Employee::factory()->create();

        $this->patchJson("/api/hr/employees/{$employee->id}", [
            'sham_cash_account' => '1234567890123456',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.sham_cash_account.0', 'لا تملك صلاحية تعديل حقول الرواتب ومعاييرها.');
    }

    public function test_the_same_editor_may_still_edit_everything_else(): void
    {
        $this->actingAsUserWithPermissions(self::EDITOR);
        $employee = Employee::factory()->create();

        $this->patchJson("/api/hr/employees/{$employee->id}", ['birth_place' => 'حلب'])
            ->assertOk();

        $this->assertSame('حلب', $employee->fresh()->birth_place);
    }

    public function test_holding_view_salary_restores_the_write(): void
    {
        $this->actingAsUserWithPermissions([...self::EDITOR, 'hr.employees.viewSalary']);
        $employee = Employee::factory()->create();

        $this->patchJson("/api/hr/employees/{$employee->id}", ['organizational_grade' => 3])
            ->assertOk();

        $this->assertSame(3, $employee->fresh()->organizational_grade);
    }

    // ── The identity gate ────────────────────────────────────────────────

    public function test_an_editor_without_view_identity_cannot_set_a_national_id(): void
    {
        $this->actingAsUserWithPermissions(self::EDITOR);
        $employee = Employee::factory()->create();

        $this->patchJson("/api/hr/employees/{$employee->id}", ['national_id' => '01010101010'])
            ->assertStatus(422)
            ->assertJsonPath('errors.national_id.0', 'لا تملك صلاحية تعديل الرقم الوطني.');

        $this->assertNull($employee->fresh()->national_id);
    }

    // ── Creation, not only updates ───────────────────────────────────────

    public function test_the_gate_applies_on_create_as_well_as_update(): void
    {
        $this->actingAsUserWithPermissions(self::EDITOR);

        $this->postJson('/api/hr/employees', [
            'first_name'    => 'سامر',
            'middle_name'   => 'خالد',
            'last_name'     => 'الحلبي',
            'gender'        => 1,
            'agreed_salary' => '750000.00',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['agreed_salary']);
    }

    // ── The anti-drift guard ─────────────────────────────────────────────

    /**
     * Derives the read-gated key set by rendering the Resource twice — once for
     * a caller holding `viewSalary` and once for a caller without it — and
     * asserts it matches the write-gated list, ignoring the `_label` keys that
     * are derived output rather than input.
     *
     * If this fails, someone added a pay column to `EmployeeResource::payBlock()`
     * and not to `EmployeeRequest::SALARY_GATED_FIELDS`, and that column is
     * readable only by payroll while writable by any editor. That is GAP-12,
     * reopened one field at a time, which is exactly how it would come back.
     */
    public function test_the_write_gate_matches_the_read_gate(): void
    {
        $employee = Employee::factory()->create();

        $withSalary = $this->renderKeys($employee, $this->userHolding([
            'hr.employees.view',
            'hr.employees.viewSalary',
        ]));

        $withoutSalary = $this->renderKeys($employee, $this->userHolding(['hr.employees.view']));

        $readGated = array_values(array_filter(
            array_diff($withSalary, $withoutSalary),
            static fn (string $key): bool => ! str_ends_with($key, '_label'),
        ));

        sort($readGated);
        $writeGated = EmployeeRequest::SALARY_GATED_FIELDS;
        sort($writeGated);

        $this->assertSame(
            $readGated,
            $writeGated,
            'EmployeeResource::payBlock() and EmployeeRequest::SALARY_GATED_FIELDS disagree. '
            ."A field gated on read and open on write is GAP-12 reopened.\nRead: "
            .implode(', ', $readGated)."\nWrite: ".implode(', ', $writeGated),
        );
    }

    /**
     * @return array<int, string>
     */
    private function renderKeys(Employee $employee, User $user): array
    {
        $request = Request::create('/api/hr/employees/'.$employee->id);
        $request->setUserResolver(static fn (): User => $user);

        return array_keys((new EmployeeResource($employee))->toArray($request));
    }
}
