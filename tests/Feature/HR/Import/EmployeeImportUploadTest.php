<?php

declare(strict_types=1);

namespace Tests\Feature\HR\Import;

use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Import\WorkbookReader;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeImportRun;
use App\Modules\HR\Services\EmployeeImportService;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * POST /api/hr/employee-imports — upload HR's one file (the صرح columns
 * merged in, 2026-10-24) and get the dry run's report (EMPLOYEE_IMPORT_UPLOAD_DESIGN.md §4.2, U5).
 *
 * The workbooks are faked at the WorkbookReader seam, keyed on the stored
 * file's name; the reader has its own test against a real xlsx.
 */
class EmployeeImportUploadTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/hr/employee-imports';

    public const PERMISSIONS = ['hr.employees.import', 'hr.employees.viewSalary', 'hr.employees.viewIdentity'];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(DepartmentSeeder::class);
        $this->app->instance(WorkbookReader::class, new FakeImportReader);
        FakeImportReader::reset();
    }

    private function workbook(string $name = 'Program_HR_10_2026.xlsx', int $kilobytes = 10): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kilobytes, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    /** @return array<int, string> every file left under hr-import/ */
    private function storedFiles(): array
    {
        return Storage::disk('local')->allFiles('hr-import');
    }

    public function test_a_dry_run_stores_the_files_and_changes_nothing(): void
    {
        $this->actingAsUserWithPermissions(self::PERMISSIONS);

        $response = $this->post(self::URL, ['workbook' => $this->workbook()]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 1)
            ->assertJsonPath('data.status_label', 'تم الفحص')
            ->assertJsonPath('data.summary.created', 1);
        $this->assertSame(0, Employee::count());
        $this->assertCount(1, $this->storedFiles());
        $this->assertArrayNotHasKey('sarh_name', $response->json('data'));
        $this->assertArrayNotHasKey('workbook_path', $response->json('data'));
        $this->assertArrayNotHasKey('workbook_sha256', $response->json('data'));
    }

    public function test_a_sarh_file_is_refused_with_the_way_forward(): void
    {
        // HR merges the صرح columns into their own file (project owner,
        // 2026-10-24). An old client still sending it is told so, never
        // silently ignored.
        $this->actingAsUserWithPermissions(self::PERMISSIONS);

        $this->post(self::URL, ['workbook' => $this->workbook(), 'sarh' => $this->workbook('مستقبل صرح 9-2026.xlsx')])
            ->assertUnprocessable()
            ->assertJsonPath('errors.sarh.0', 'ملف صرح لم يعد مستخدماً — ادمج بياناته في ملف الموارد البشرية.');

        $this->assertSame(0, EmployeeImportRun::count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_a_check_records_the_rows_it_would_import(): void
    {
        // What can be excluded later is fixed here (design §12.2): each
        // re-check replaces the report, so eligibility is not read from it.
        $this->actingAsUserWithPermissions(self::PERMISSIONS);
        FakeImportReader::$rows = [
            4 => FakeImportReader::person(582974643, 'نور أحمد الشامي'),
            5 => ['ID_Sarah' => null, 'الإسم الثلاثي' => 'سامر خالد الحلبي'],
        ];

        $this->post(self::URL, ['workbook' => $this->workbook()])
            ->assertCreated()
            ->assertJsonPath('data.importable_rows', [4])
            ->assertJsonPath('data.excluded_rows', []);
    }

    public function test_a_file_that_cannot_be_stored_leaves_nothing_behind(): void
    {
        // A store that throws, or returns false, leaves no folder and no run
        // behind (final review, Part 1).
        $user = $this->actingAsUserWithPermissions(self::PERMISSIONS);
        $imports = app(EmployeeImportService::class);

        foreach (['throws', 'false'] as $failure) {
            try {
                $imports->check($user, new UnstorableFile($failure));
                $this->fail("A {$failure} store was not refused.");
            } catch (RuntimeException) {
            }
        }

        $this->assertSame([], $this->storedFiles());
        $this->assertSame(0, EmployeeImportRun::count());
    }

    public function test_the_report_carries_rows_changes_and_missing(): void
    {
        $this->actingAsUserWithPermissions(self::PERMISSIONS);
        Employee::factory()->create();

        $report = $this->post(self::URL, ['workbook' => $this->workbook()])->assertCreated()->json('data.report');

        $this->assertSame(['rows', 'changes', 'not_in_file'], array_keys($report));
        $this->assertSame('نور أحمد الشامي', $report['rows'][0]['name']);
        $this->assertNull($report['rows'][0]['employee_number'], 'a dry run hands out no number');
        $this->assertCount(1, $report['not_in_file']);
        $this->assertFalse($report['not_in_file'][0]['imported']);
    }

    public function test_the_swapped_files_are_refused_with_the_missing_sheet(): void
    {
        $this->actingAsUserWithPermissions(self::PERMISSIONS);
        FakeImportReader::$refuse = 'workbook.xlsx';

        $this->post(self::URL, ['workbook' => $this->workbook('مستقبل صرح 9-2026.xlsx')])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'ملف الموارد: لا توجد ورقة باسم «قاعدة البيانات». الأوراق الموجودة: Sheet1');

        $this->assertSame(0, EmployeeImportRun::count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_original_names_are_kept_verbatim_and_never_used_as_paths(): void
    {
        $this->actingAsUserWithPermissions(self::PERMISSIONS);
        $name = "\u{200F}\u{200F}Program_HR_10_2026.xlsx";

        $this->post(self::URL, ['workbook' => $this->workbook($name)])
            ->assertCreated()
            ->assertJsonPath('data.workbook_name', $name);

        $run = EmployeeImportRun::sole();
        $this->assertStringEndsWith('/workbook.xlsx', $run->workbook_path);
        $this->assertStringStartsWith('hr-import/runs/', $run->workbook_path);
    }

    public function test_validation(): void
    {
        $this->actingAsUserWithPermissions(self::PERMISSIONS);

        $this->post(self::URL, [])->assertUnprocessable()->assertJsonValidationErrors('workbook');
        $this->post(self::URL, ['workbook' => UploadedFile::fake()->create('hr.csv', 10, 'text/csv')])
            ->assertUnprocessable()->assertJsonValidationErrors('workbook');
        $this->post(self::URL, ['workbook' => $this->workbook(kilobytes: 20 * 1024 + 1)])
            ->assertUnprocessable()->assertJsonValidationErrors('workbook');

        $this->assertSame(0, EmployeeImportRun::count());
    }

    public function test_authorization(): void
    {
        $this->actingAsUserWithPermissions(['hr.employees.view']);
        $this->post(self::URL, ['workbook' => $this->workbook()])->assertForbidden();

        $this->actingAsUserWithPermissions(['hr.employees.import', 'hr.employees.viewIdentity']);
        $this->post(self::URL, ['workbook' => $this->workbook()])
            ->assertForbidden()
            ->assertJsonPath('message', 'يتطلب الاستيراد صلاحيتَي عرض الرواتب وأرقام الهوية على مستوى الشركة.');

        $this->actingAsScopedUser(self::PERMISSIONS, ScopeType::Unit, Department::where('code', 'ENG')->value('id'));
        $this->post(self::URL, ['workbook' => $this->workbook()])->assertForbidden();

        $this->assertSame([], $this->storedFiles());
    }

    public function test_a_held_lock_refuses_with_409(): void
    {
        $this->actingAsUserWithPermissions(self::PERMISSIONS);
        $lock = Cache::lock('hr-employee-import', 60);
        $lock->get();

        $this->post(self::URL, ['workbook' => $this->workbook()])
            ->assertStatus(409)
            ->assertJsonPath('message', 'يجري استيراد آخر الآن، حاول بعد قليل.');

        $lock->release();
        $this->assertSame([], $this->storedFiles());
    }

    public function test_the_check_is_audited_without_personal_data(): void
    {
        $user = $this->actingAsUserWithPermissions(self::PERMISSIONS);

        $this->post(self::URL, ['workbook' => $this->workbook()])->assertCreated();

        $row = DB::table('audit_logs')->where('event', 'employees_import_checked')->sole();
        $this->assertSame($user->id, (int) $row->user_id);
        $this->assertStringContainsString('"run_id"', (string) $row->new_values);
        $this->assertStringNotContainsString('الشامي', (string) $row->new_values);
    }
}

/** An uploaded file whose store fails — by throwing, or by returning false. */
class UnstorableFile extends UploadedFile
{
    public function __construct(private readonly string $failure)
    {
        $path = tempnam(sys_get_temp_dir(), 'hrx');
        file_put_contents($path, 'hr');
        parent::__construct($path, 'Program_HR_10_2026.xlsx', null, null, true);
    }

    public function storeAs($path, $name = null, $options = []): string|false
    {
        if ($this->failure === 'throws') {
            throw new RuntimeException('disk full');
        }

        return false;
    }
}
