<?php

declare(strict_types=1);

namespace Tests\Feature\HR\Import;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Import\WorkbookException;
use App\Modules\HR\Import\WorkbookReader;
use App\Modules\HR\Models\Employee;
use Database\Seeders\DepartmentSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * hr:import-employees (work order §4.4): the files it writes, and the rule
 * that a temporary password is written to the credentials file and nowhere
 * else — not the report, not the console.
 *
 * The workbooks are faked at the WorkbookReader seam; the reader has its own
 * test against a real xlsx.
 */
class ImportEmployeesCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DepartmentSeeder::class);

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hr-import-'.bin2hex(random_bytes(4));
        mkdir($this->dir);

        $this->app->instance(WorkbookReader::class, new class implements WorkbookReader
        {
            public function rows(string $path, string $sheet, int $headerRow, ?array $columns = null): array
            {
                if (str_contains($path, 'missing')) {
                    throw new WorkbookException("File not found: {$path}");
                }

                return str_contains($path, 'sarh')
                    ? [2 => ['_id' => 582974643, 'الاسم الأول' => 'نور', 'اسم الاب' => 'أحمد', 'الكنية' => 'الشامي', 'اسم الام' => 'فاطمة']]
                    : [4 => [
                        'ID_Sarah'       => 582974643,
                        'الإسم الثلاثي'  => 'نور أحمد الشامي',
                        'أسماء الإدارات' => 'الإدارة الهندسية',
                        'القسم'          => 'قسم التخطيط والدراسات',
                        'المكتب'         => 'المعماري',
                        'المسمى الوظيفي' => 'مهندس معماري',
                        'الحالة'         => 'متعاقد',
                        'الجنس'          => 'ذكر',
                        'تاريخ التعيين'  => new DateTimeImmutable('2022-01-01'),
                    ], 5 => [
                        'ID_Sarah'      => null,
                        'الإسم الثلاثي' => 'سامر خالد الحلبي',
                    ]];
            }
        });
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: []);
        rmdir($this->dir);

        parent::tearDown();
    }

    private function path(string $name): string
    {
        return $this->dir.DIRECTORY_SEPARATOR.$name;
    }

    /** @return array<int, array<int, mixed>> every row of every sheet */
    private function cells(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();

        return $rows;
    }

    // ── A real run ───────────────────────────────────────────────────────

    public function test_a_real_run_refuses_to_start_without_a_credentials_file(): void
    {
        $this->artisan('hr:import-employees', ['workbook' => 'hr.xlsx', '--report' => $this->path('r.xlsx'), '--force' => true])
            ->expectsOutputToContain('--credentials')
            ->assertFailed();

        $this->assertSame(0, Employee::count());
    }

    public function test_a_real_run_refuses_a_credentials_file_that_already_exists(): void
    {
        file_put_contents($this->path('c.xlsx'), 'last run');

        $this->artisan('hr:import-employees', [
            'workbook' => 'hr.xlsx', '--report' => $this->path('r.xlsx'), '--credentials' => $this->path('c.xlsx'), '--force' => true,
        ])->assertFailed();

        $this->assertSame(0, Employee::count());
        $this->assertSame('last run', file_get_contents($this->path('c.xlsx')));
    }

    public function test_a_real_run_imports_and_writes_the_passwords_to_the_credentials_file_only(): void
    {
        $this->artisan('hr:import-employees', [
            'workbook' => 'hr.xlsx', '--sarh' => 'sarh.xlsx',
            '--report' => $this->path('r.xlsx'), '--credentials' => $this->path('c.xlsx'), '--force' => true,
        ])->assertSuccessful();

        $employee = Employee::sole();
        $this->assertSame('فاطمة', $employee->mother_name);

        [$header, $credential] = $this->cells($this->path('c.xlsx'));
        $this->assertSame(['الرقم الوظيفي', 'الاسم', 'كلمة المرور المؤقتة'], $header);
        $this->assertSame($employee->employee_number, $credential[0]);
        $password = $credential[2];
        $this->assertTrue(Hash::check($password, User::sole()->password));

        $report = json_encode($this->cells($this->path('r.xlsx')), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($password, $report);
        $this->assertStringContainsString($employee->employee_number, $report);
    }

    public function test_the_console_never_shows_a_password(): void
    {
        Artisan::call('hr:import-employees', [
            'workbook' => 'hr.xlsx', '--report' => $this->path('r.xlsx'), '--credentials' => $this->path('c.xlsx'), '--force' => true,
        ]);
        $output = Artisan::output();

        $password = $this->cells($this->path('c.xlsx'))[1][2];
        $this->assertStringNotContainsString($password, $output);
        $this->assertStringContainsString('Temporary passwords for 1 new account(s)', $output);
    }

    public function test_a_real_run_asks_first_and_does_nothing_on_no(): void
    {
        $this->artisan('hr:import-employees', [
            'workbook' => 'hr.xlsx', '--report' => $this->path('r.xlsx'), '--credentials' => $this->path('c.xlsx'),
        ])
            ->expectsConfirmation('This writes employees and creates accounts for real. Did you review a dry run\'s report?', 'no')
            ->assertFailed();

        $this->assertSame(0, Employee::count());
        $this->assertFileDoesNotExist($this->path('c.xlsx'));
    }

    // ── A dry run ────────────────────────────────────────────────────────

    public function test_a_dry_run_writes_the_report_and_nothing_else(): void
    {
        $this->artisan('hr:import-employees', [
            'workbook' => 'hr.xlsx', '--dry-run' => true, '--report' => $this->path('r.xlsx'), '--credentials' => $this->path('c.xlsx'),
        ])->assertSuccessful();

        $this->assertSame(0, Employee::count());
        $this->assertSame(0, User::count());
        $this->assertFileDoesNotExist($this->path('c.xlsx'));
        $this->assertFileExists($this->path('r.xlsx'));
    }

    public function test_the_report_has_a_row_per_source_row_with_its_outcome_and_issues(): void
    {
        $this->artisan('hr:import-employees', ['workbook' => 'hr.xlsx', '--dry-run' => true, '--report' => $this->path('r.xlsx')])
            ->assertSuccessful();

        $rows = array_values(array_filter(
            $this->cells($this->path('r.xlsx')),
            static fn (array $row): bool => in_array($row[0] ?? null, [4, 5], true),
        ));

        $this->assertSame([4, 'نور أحمد الشامي', 'جديد', '', '582974643'], array_slice($rows[0], 0, 5));
        $this->assertSame([5, 'سامر خالد الحلبي', 'لم يُستورد', '', ''], array_slice($rows[1], 0, 5));
        $this->assertStringStartsWith('خطأ: لا يوجد رقم كوبو', $rows[1][5]);
    }

    public function test_an_unreadable_workbook_fails_before_anything_is_written(): void
    {
        $this->artisan('hr:import-employees', ['workbook' => 'missing.xlsx', '--dry-run' => true, '--report' => $this->path('r.xlsx')])
            ->expectsOutputToContain('File not found')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->path('r.xlsx'));
    }
}
