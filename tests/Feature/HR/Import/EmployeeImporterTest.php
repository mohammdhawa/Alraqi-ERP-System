<?php

declare(strict_types=1);

namespace Tests\Feature\HR\Import;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Import\EmployeeImporter;
use App\Modules\HR\Import\ImportReport;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeImportSource;
use App\Modules\HR\Models\JobTitle;
use Database\Seeders\DepartmentSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The employee importer (work order §4.4, Batch 3).
 *
 * The properties the work order asks for, each pinned: re-runnable without
 * duplicates (the staging key), corrections that are not transfers, reports
 * rather than absorbs, accounts only once and their passwords only in the
 * credentials it hands back, soft-deleted rows restored rather than inserted
 * beside, and a dry run that writes nothing.
 */
class EmployeeImporterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DepartmentSeeder::class);
    }

    /** @param  array<string, mixed>  $override */
    private function row(int $kobo = 582974643, array $override = []): array
    {
        return array_merge([
            'أسماء الإدارات'               => 'الإدارة الهندسية',
            'القسم'                        => 'قسم التخطيط والدراسات',
            'المكتب'                       => 'المعماري',
            'المسمى الوظيفي'               => 'مهندس معماري',
            'السوية التنظيمية'             => 4,
            'نوع النشاط'                   => 'هندسي',
            'مكان الراتب'                  => 'مكتب رئيسي - هندسي',
            'الحالة'                       => 'متعاقد',
            'الرتبة الهندسية'              => 'مشارك2',
            'الإسم الثلاثي'                => 'نور أحمد الشامي',
            'المرحلة الدراسية'             => 'كلية',
            'المحافظة'                     => 'حلب',
            'الحالة الاجتماعية'            => 'متزوج',
            'رقم الشام كاش'                => '9200274887373820',
            'تاريخ التعيين'                => new DateTimeImmutable('2022-01-01'),
            'مكان العمل بداية التعيين'     => 'الموقع العام',
            'المدير المباشر بداية التعيين' => 'أبو يعرب',
            'حالة الراتب ( نظام / إتفاق )' => 'نظام رواتب',
            'الجنس'                        => 'ذكر',
            'ID_Sarah'                     => $kobo,
        ], $override);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int|string, array<string, mixed>>  $sarh
     */
    private function import(array $rows, array $sarh = [], bool $dryRun = false): ImportReport
    {
        return app(EmployeeImporter::class)->run($rows, $sarh, $dryRun, sourceFile: 'Program_HR_10_2026.xlsx');
    }

    // ── A first run ──────────────────────────────────────────────────────

    public function test_a_row_becomes_an_employee_in_its_unit_with_its_title(): void
    {
        $report = $this->import([$this->row()]);

        $employee = Employee::sole();
        $this->assertSame('نور', $employee->first_name);
        $this->assertSame('ENG-PLAN-ARCH', $employee->department->code);
        $this->assertSame('مهندس معماري', $employee->jobTitle->name);
        $this->assertSame(1, $report->count('created'));
    }

    public function test_the_row_is_remembered_under_its_kobo_key(): void
    {
        $this->import([$this->row()]);

        $this->assertTrue(Employee::sole()->is(EmployeeImportSource::employeeFor('kobo:582974643')));
    }

    public function test_an_account_is_created_with_a_temporary_password_handed_back_once(): void
    {
        $report = $this->import([$this->row()]);

        $employee = Employee::sole();
        $user = User::where('employee_id', $employee->id)->sole();
        $this->assertTrue($user->must_change_password);

        $credential = $report->credentials()[0];
        $this->assertSame($employee->employee_number, $credential['employee_number']);
        $this->assertTrue(Hash::check($credential['password'], $user->password));
    }

    public function test_the_opening_posting_keeps_the_workbooks_first_workplace_and_manager(): void
    {
        $this->import([$this->row()]);

        $opening = Employee::sole()->assignments()->sole();
        $this->assertSame('الموقع العام', $opening->legacy_workplace);
        $this->assertSame('أبو يعرب', $opening->legacy_manager_name);
        $this->assertSame('2022-01-01', $opening->from_date->toDateString());
    }

    public function test_the_starting_title_is_kept_as_text_beside_the_current_one_on_a_single_posting(): void
    {
        $this->import([$this->row(override: ['المسمى الوظيفي بداية التعيين' => 'مهندس متدرب'])]);

        // Option B (2026-10-15): one posting with today's title, no invented
        // date for when the title changed.
        $opening = Employee::sole()->assignments()->sole();
        $this->assertSame('مهندس معماري', $opening->jobTitle->name);
        $this->assertSame('مهندس متدرب', $opening->legacy_job_title);
        $this->assertFalse(JobTitle::where('name', 'مهندس متدرب')->exists(), 'the starting title is text, not a catalogue entry');
    }

    public function test_job_titles_are_deduplicated_by_name(): void
    {
        $this->import([
            $this->row(1),
            $this->row(2, ['الإسم الثلاثي' => 'سامر خالد الحلبي', 'المسمى الوظيفي' => 'مهندس  معماري', 'رقم الشام كاش' => null]),
        ]);

        $this->assertSame(1, JobTitle::where('name', 'مهندس معماري')->count());
        $this->assertSame(2, Employee::count());
    }

    // ── Re-running ───────────────────────────────────────────────────────

    public function test_a_second_run_duplicates_nothing_and_hands_out_no_new_password(): void
    {
        $first = $this->import([$this->row()]);
        $password = User::sole()->password;

        $second = $this->import([$this->row()]);

        $this->assertSame(1, Employee::count());
        $this->assertSame(1, User::count());
        $this->assertSame($password, User::sole()->password, 'an existing account is left alone');
        $this->assertSame([], $second->credentials());
        $this->assertSame(1, $second->count('updated'));
        $this->assertCount(1, $first->credentials());
    }

    public function test_a_corrected_unit_on_a_re_run_is_a_correction_not_a_transfer(): void
    {
        $this->import([$this->row()]);

        $this->import([$this->row(override: ['المكتب' => 'الإنشائي'])]);

        $employee = Employee::sole();
        $this->assertSame('ENG-PLAN-STRU', $employee->department->code);
        $this->assertCount(1, $employee->assignments()->get(), 'no transfer posting invented');
    }

    public function test_a_soft_deleted_employee_and_account_are_restored_not_duplicated(): void
    {
        $this->import([$this->row()]);
        $employee = Employee::sole();
        User::sole()->delete();
        $employee->delete();

        $report = $this->import([$this->row()]);

        $this->assertSame(1, Employee::withTrashed()->count());
        $this->assertFalse($employee->fresh()->trashed());
        $this->assertSame(1, User::withTrashed()->count());
        $this->assertFalse(User::withTrashed()->sole()->trashed());
        $this->assertCount(1, $report->credentials(), 'a restored account gets a fresh temporary password');
        $this->assertSame(1, $report->count('restored'));
    }

    // ── Reporting rather than absorbing ──────────────────────────────────

    public function test_a_row_without_a_kobo_id_is_skipped_and_reported(): void
    {
        $report = $this->import([$this->row(), $this->row(override: ['ID_Sarah' => null, 'الإسم الثلاثي' => 'موظف جديد بلا رقم', 'رقم الشام كاش' => null])]);

        $this->assertSame(1, Employee::count());
        $this->assertSame(1, $report->count('skipped'));
        $this->assertSame('موظف جديد بلا رقم', $report->rows()[1]['name']);
    }

    public function test_the_same_kobo_id_twice_in_one_file_is_reported(): void
    {
        $report = $this->import([$this->row(), $this->row(override: ['الإسم الثلاثي' => 'سامر خالد الحلبي', 'رقم الشام كاش' => null])]);

        $this->assertSame(1, Employee::count());
        $this->assertSame(1, $report->count('skipped'));
    }

    public function test_a_unit_the_tree_lacks_still_imports_the_person_and_reports_it(): void
    {
        $report = $this->import([$this->row(override: ['أسماء الإدارات' => 'إدارة الجودة والسلامة والمهنية', 'القسم' => null, 'المكتب' => null])]);

        $this->assertNull(Employee::sole()->department_id);
        $this->assertNotEmpty(array_filter($report->rows()[0]['issues'], fn ($i) => $i['severity'] === 'error'));
    }

    public function test_a_sham_cash_account_already_held_by_someone_else_is_dropped_and_reported(): void
    {
        $report = $this->import([
            4 => $this->row(1),
            9 => $this->row(2, ['الإسم الثلاثي' => 'سامر خالد الحلبي']),
        ]);

        $this->assertSame(2, Employee::count());
        $this->assertNull(EmployeeImportSource::employeeFor('kobo:2')->sham_cash_account);
        $issue = array_values(array_filter($report->rows()[1]['issues'], fn ($i) => $i['field'] === 'sham_cash_account'))[0];
        // The holder's sheet row, so HR can find the other row even on a dry
        // run, whose employee numbers are never kept.
        $this->assertStringContainsString('السطر 4', $issue['message']);
    }

    public function test_a_row_the_model_refuses_does_not_stop_the_others(): void
    {
        // A negative agreed salary passes the mapper (it is a number) and is
        // refused by the classification guard — a refusal only the model makes.
        $report = $this->import([
            $this->row(1, ['حالة الراتب ( نظام / إتفاق )' => 'اتفاق', 'الراتب المتفق عليه' => -5]),
            $this->row(2, ['الإسم الثلاثي' => 'سامر خالد الحلبي', 'رقم الشام كاش' => null]),
        ]);

        $this->assertSame(1, $report->count('failed'));
        $this->assertSame(1, $report->count('created'));
        $this->assertSame('سامر', Employee::sole()->first_name);
        $this->assertSame(0, User::whereNull('employee_id')->count(), 'no orphan account from the failed row');
        $this->assertNotEmpty($report->rows()[0]['issues']);
    }

    // ── Dry run ──────────────────────────────────────────────────────────

    public function test_a_dry_run_reports_what_would_happen_and_writes_nothing(): void
    {
        $report = $this->import([$this->row()], dryRun: true);

        $this->assertSame(1, $report->count('created'));
        $this->assertSame(0, Employee::count());
        $this->assertSame(0, User::count());
        $this->assertSame(0, JobTitle::count());
        $this->assertSame(0, EmployeeImportSource::count());
        $this->assertSame([], $report->credentials(), 'no password exists for an account that was never made');
    }
}
