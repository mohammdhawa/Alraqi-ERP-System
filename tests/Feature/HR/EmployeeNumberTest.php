<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\HR\Exceptions\EmployeeNumberException;
use App\Modules\HR\Models\Employee;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\DevDepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The employee number is the person's Kobo id (KOBO_EMPLOYEE_NUMBER_DESIGN.md
 * §3): required at creation, digits only, never changed — guarded by the
 * model itself, beneath every writer.
 */
class EmployeeNumberTest extends TestCase
{
    use RefreshDatabase;

    private function refusal(callable $write): string
    {
        try {
            $write();
        } catch (EmployeeNumberException $e) {
            return $e->getMessage();
        }

        $this->fail('The write was not refused.');
    }

    public function test_an_employee_cannot_be_created_without_a_number(): void
    {
        $this->assertSame('رقم كوبو مطلوب.', $this->refusal(
            fn () => Employee::factory()->make(['employee_number' => null])->save(),
        ));
        $this->assertSame(0, Employee::count());
    }

    public function test_a_number_must_be_digits(): void
    {
        $this->assertSame('رقم كوبو يتكوّن من أرقام فقط.', $this->refusal(
            fn () => Employee::factory()->create(['employee_number' => 'EMP-00042']),
        ));
        $this->assertSame('رقم كوبو يتكوّن من أرقام فقط.', $this->refusal(
            fn () => Employee::factory()->create(['employee_number' => str_repeat('1', 21)]),
        ));
        // The API's rule, beneath every writer: 0582974643 and 582974643 would be two people.
        $this->assertSame('رقم كوبو يتكوّن من أرقام فقط.', $this->refusal(
            fn () => Employee::factory()->create(['employee_number' => '0582974643']),
        ));

        $this->assertSame('582974643', Employee::factory()->create(['employee_number' => '582974643'])->employee_number);
    }

    public function test_a_number_never_changes(): void
    {
        $employee = Employee::factory()->create(['employee_number' => '582974643']);

        $this->assertSame('لا يمكن تغيير الرقم الوظيفي بعد إنشائه.', $this->refusal(
            fn () => $employee->update(['employee_number' => '582974644']),
        ));
        $this->assertSame('582974643', $employee->fresh()->employee_number);

        $employee->fresh()->update(['phone' => '0933111222']);
        $this->assertSame('0933111222', $employee->fresh()->phone);
    }

    public function test_an_existing_non_digit_number_can_still_be_saved(): void
    {
        // A development row numbered before this change.
        $employee = Employee::factory()->create(['employee_number' => '582974643']);
        DB::table('employees')->where('id', $employee->id)->update(['employee_number' => 'EMP-00001']);

        $employee->fresh()->update(['phone' => '0933111222']);

        $this->assertSame('EMP-00001', $employee->fresh()->employee_number);
    }

    public function test_renumber_to_is_the_one_way_past_the_guard(): void
    {
        $employee = Employee::factory()->create(['employee_number' => '700000001']);

        $employee->renumberTo('582974644');
        $this->assertSame('582974644', $employee->fresh()->employee_number);

        $this->assertSame('رقم كوبو يتكوّن من أرقام فقط.', $this->refusal(fn () => $employee->renumberTo('EMP-1')));
    }

    // ── A deleted employee frees their number (I1, 2026-10-24) ───────────

    public function test_deleting_frees_the_number(): void
    {
        $gone = Employee::factory()->create(['employee_number' => '582974643']);
        $gone->delete();

        $this->assertSame("582974643-deleted-{$gone->id}", Employee::withTrashed()->find($gone->id)->employee_number);
        $this->assertSame('582974643', Employee::factory()->create(['employee_number' => '582974643'])->employee_number);
    }

    public function test_restoring_takes_the_number_back(): void
    {
        $employee = Employee::factory()->create(['employee_number' => '582974643']);
        $employee->delete();

        Employee::withTrashed()->find($employee->id)->restore();

        $this->assertSame('582974643', $employee->fresh()->employee_number);
    }

    public function test_restoring_is_refused_when_someone_else_has_the_number_now(): void
    {
        $gone = Employee::factory()->create(['employee_number' => '582974643']);
        $gone->delete();
        Employee::factory()->create(['employee_number' => '582974643']);

        $this->assertSame('الرقم الوظيفي 582974643 صار لموظف آخر — لا يُستعاد هذا الموظف.', $this->refusal(
            fn () => Employee::withTrashed()->find($gone->id)->restore(),
        ));
        $this->assertTrue(Employee::withTrashed()->find($gone->id)->trashed());
    }

    public function test_the_factory_gives_unique_digit_numbers(): void
    {
        $numbers = Employee::factory()->count(3)->create()->pluck('employee_number')->all();

        foreach ($numbers as $number) {
            $this->assertMatchesRegularExpression('/^\d{1,20}$/', $number);
        }
        $this->assertCount(3, array_unique($numbers));
    }

    public function test_the_dev_seeder_gives_digit_numbers_and_leaves_existing_ones(): void
    {
        $this->seedRbac();
        $this->seed(DepartmentSeeder::class);
        $this->seed(DevDepartmentSeeder::class);
        $first = Employee::withTrashed()->orderBy('id')->pluck('employee_number', 'id')->all();
        $this->assertNotEmpty($first);
        foreach ($first as $number) {
            $this->assertMatchesRegularExpression('/^\d{1,20}$/', $number);
        }

        // A seeded row numbered before this change survives a re-seed.
        $oldId = array_key_first($first);
        DB::table('employees')->where('id', $oldId)->update(['employee_number' => 'EMP-00001']);

        $this->seed(DevDepartmentSeeder::class);

        $second = Employee::withTrashed()->orderBy('id')->pluck('employee_number', 'id')->all();
        $this->assertSame('EMP-00001', $second[$oldId]);
        unset($first[$oldId], $second[$oldId]);
        $this->assertSame($first, $second, 'a second run changes no number');
    }
}
