<?php

declare(strict_types=1);

namespace Tests\Feature\HR\Import;

use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Enums\ImportRunStatus;
use App\Modules\HR\Models\EmployeeImportRun;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * The upload import's run record and who may run one
 * (EMPLOYEE_IMPORT_UPLOAD_DESIGN.md §4.1, §4.3).
 */
class EmployeeImportRunTest extends TestCase
{
    use RefreshDatabase;

    private const IMPORT = 'hr.employees.import';

    public function test_is_expired_after_sixty_minutes_or_when_marked(): void
    {
        $user = $this->actingAsAdmin();
        $run = EmployeeImportRun::create([
            'created_by'      => $user->id,
            'status'          => ImportRunStatus::Checked,
            'workbook_name'   => 'Program_HR_10_2026.xlsx',
            'workbook_sha256' => str_repeat('a', 64),
            'summary'         => ['created' => 1],
            'checked_at'      => now(),
            'expires_at'      => now()->addMinutes(60),
        ]);

        $this->travel(59)->minutes();
        $this->assertFalse($run->isExpired());

        $this->travel(1)->minutes();
        $this->assertTrue($run->isExpired());

        $this->travelBack();
        $run->status = ImportRunStatus::Expired;
        $this->assertTrue($run->isExpired());
    }

    public function test_the_statuses_have_arabic_labels(): void
    {
        $this->assertSame('تم الفحص', ImportRunStatus::Checked->label());
        $this->assertSame('تم الاستيراد', ImportRunStatus::Executed->label());
        $this->assertSame('منتهي الصلاحية', ImportRunStatus::Expired->label());
    }

    public function test_the_policy_needs_both_field_permissions_company_wide(): void
    {
        $both = $this->actingAsUserWithPermissions([self::IMPORT, 'hr.employees.viewSalary', 'hr.employees.viewIdentity']);
        $this->assertTrue(Gate::forUser($both)->allows('import', EmployeeImportRun::class));

        $noIdentity = $this->actingAsUserWithPermissions([self::IMPORT, 'hr.employees.viewSalary']);
        $refusal = Gate::forUser($noIdentity)->inspect('import', EmployeeImportRun::class);
        $this->assertTrue($refusal->denied());
        $this->assertSame('يتطلب الاستيراد صلاحيتَي عرض الرواتب وأرقام الهوية على مستوى الشركة.', $refusal->message());

        $noSalary = $this->actingAsUserWithPermissions([self::IMPORT, 'hr.employees.viewIdentity']);
        $this->assertTrue(Gate::forUser($noSalary)->denies('import', EmployeeImportRun::class));

        $this->seed(DepartmentSeeder::class);
        $scoped = $this->actingAsScopedUser(
            [self::IMPORT, 'hr.employees.viewSalary', 'hr.employees.viewIdentity'],
            ScopeType::Unit,
            Department::where('code', 'ENG')->value('id'),
        );
        $this->assertTrue(Gate::forUser($scoped)->denies('import', EmployeeImportRun::class), 'a unit grant reaches part of the company');

        $this->assertTrue(Gate::forUser($this->actingAsAdmin())->allows('import', EmployeeImportRun::class));
    }
}
