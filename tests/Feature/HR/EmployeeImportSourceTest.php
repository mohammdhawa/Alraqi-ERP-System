<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\HR\Exceptions\ImportSourceConflictException;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeImportSource;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The importer's staging table — source person → employee (decision 11).
 *
 * The importer runs several times against HR's corrections before the real
 * cut. Without a key that survives between runs, run two duplicates run one;
 * and decision 10 keeps that key out of `employees`. This table is that key,
 * and it is also what HR is shown: this row of this sheet became this person.
 */
class EmployeeImportSourceTest extends TestCase
{
    use RefreshDatabase;

    private function remember(string $key, Employee $employee, int $row = 7): EmployeeImportSource
    {
        return EmployeeImportSource::remember($key, $employee, [
            'source_file'  => 'Program_HR_10_2026.xlsx',
            'source_sheet' => 'قاعدة البيانات',
            'source_row'   => $row,
            'source_name'  => 'نور الشامي',
        ]);
    }

    public function test_a_remembered_key_finds_its_employee_on_the_next_run(): void
    {
        $employee = Employee::factory()->named('نور الشامي')->create();
        $this->remember('id_sarah:582974643', $employee);

        $this->assertTrue($employee->is(EmployeeImportSource::employeeFor('id_sarah:582974643')));
    }

    public function test_an_unknown_key_finds_nobody(): void
    {
        $this->assertNull(EmployeeImportSource::employeeFor('id_sarah:000000000'));
    }

    public function test_a_soft_deleted_employee_is_still_found(): void
    {
        $employee = Employee::factory()->named('نور الشامي')->create();
        $this->remember('id_sarah:582974643', $employee);
        $employee->delete();

        // The importer must restore this row, not insert a second person
        // beside it — the same trap trashed rows set for users.employee_id.
        $found = EmployeeImportSource::employeeFor('id_sarah:582974643');

        $this->assertNotNull($found);
        $this->assertTrue($found->trashed());
        $this->assertTrue($employee->is($found));
    }

    public function test_a_re_run_updates_where_the_row_was_read_rather_than_adding_one(): void
    {
        $employee = Employee::factory()->named('نور الشامي')->create();
        $this->remember('id_sarah:582974643', $employee, row: 7);

        // HR sorted the sheet between runs.
        $this->remember('id_sarah:582974643', $employee, row: 31);

        $this->assertSame(1, EmployeeImportSource::count());
        $this->assertSame(31, EmployeeImportSource::sole()->source_row);
    }

    public function test_a_key_is_never_silently_re_pointed_at_someone_else(): void
    {
        $first = Employee::factory()->named('نور الشامي')->create();
        $second = Employee::factory()->named('سامر الحلبي')->create();
        $this->remember('id_sarah:582974643', $first);

        try {
            $this->remember('id_sarah:582974643', $second);
            $this->fail('Re-pointing a key must be refused.');
        } catch (ImportSourceConflictException) {
            // Reported, not absorbed: a key that now names a different person
            // is a source error for a human to resolve.
        }

        $this->assertTrue($first->is(EmployeeImportSource::employeeFor('id_sarah:582974643')));
    }

    public function test_one_person_may_be_reached_by_several_keys(): void
    {
        // A rehire: their old row in إنهاء عمل and their current row in
        // قاعدة البيانات are the same person.
        $employee = Employee::factory()->named('نور الشامي')->create();
        $this->remember('id_sarah:582974643', $employee);
        $this->remember('leaver:نور الشامي|1990', $employee);

        $this->assertSame(2, $employee->importSources()->count());
    }

    public function test_the_database_refuses_a_duplicate_key(): void
    {
        $employee = Employee::factory()->named('نور الشامي')->create();
        $this->remember('id_sarah:582974643', $employee);

        $this->expectException(QueryException::class);

        EmployeeImportSource::query()->insert([
            'source_key'  => 'id_sarah:582974643',
            'employee_id' => $employee->id,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }
}
