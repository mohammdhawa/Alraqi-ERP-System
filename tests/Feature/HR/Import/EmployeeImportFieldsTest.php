<?php

declare(strict_types=1);

namespace Tests\Feature\HR\Import;

use App\Modules\Departments\Models\Department;
use App\Modules\HR\Enums\Gender;
use App\Modules\HR\Import\EmployeeImportFields;
use App\Modules\HR\Import\EmployeeRowMapper;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\JobTitle;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The words the import's "changes" list uses (design §5.1): every field the
 * importer can write has an Arabic label, and every value reads as a person
 * would say it — not an enum number or a unit id.
 */
class EmployeeImportFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_field_the_mapper_writes_has_a_label(): void
    {
        $mapped = (new EmployeeRowMapper)->map(array_fill_keys(EmployeeRowMapper::WORKBOOK_COLUMNS, null));

        $fields = [
            ...array_keys($mapped->attributes),
            ...array_keys($mapped->openingPosting),
            ...EmployeeRowMapper::KEPT_WHEN_BLANK,
            'wives_count', 'sons_count', 'daughters_count', 'department_id', 'job_title_id',
        ];

        $this->assertSame([], array_values(array_diff($fields, array_keys(EmployeeImportFields::LABELS))));
    }

    public function test_display_renders_enums_units_titles_dates_and_booleans(): void
    {
        $this->seed(DepartmentSeeder::class);
        $employee = new Employee;
        $section = Department::where('name', 'قسم التخطيط والدراسات')->sole();
        $title = JobTitle::create(['name' => 'مهندس معماري']);

        $this->assertSame(Gender::Male->label(), EmployeeImportFields::display($employee, 'gender', Gender::Male));
        $this->assertSame('الإدارة الهندسية › قسم التخطيط والدراسات', EmployeeImportFields::display($employee, 'department_id', $section->id));
        $this->assertSame('مهندس معماري', EmployeeImportFields::display($employee, 'job_title_id', $title->id));
        $this->assertSame('2022-01-01', EmployeeImportFields::display($employee, 'hire_date', now()->setDate(2022, 1, 1)));
        $this->assertSame('نعم', EmployeeImportFields::display($employee, 'has_hazard_pay', true));
        $this->assertSame('لا', EmployeeImportFields::display($employee, 'has_hazard_pay', false));
        $this->assertNull(EmployeeImportFields::display($employee, 'phone', null));
    }
}
