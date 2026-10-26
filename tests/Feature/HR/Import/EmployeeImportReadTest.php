<?php

declare(strict_types=1);

namespace Tests\Feature\HR\Import;

use App\Modules\HR\Import\WorkbookReader;
use App\Modules\HR\Models\EmployeeImportRun;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * GET /api/hr/employee-imports/{run} and …/report — a run read back after a
 * reload, and its report as the xlsx the command writes
 * (EMPLOYEE_IMPORT_UPLOAD_DESIGN.md §4.2).
 */
class EmployeeImportReadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(DepartmentSeeder::class);
        $this->app->instance(WorkbookReader::class, new FakeImportReader);
        FakeImportReader::reset();
    }

    private function upload(): int
    {
        return $this->post('/api/hr/employee-imports', [
            'workbook' => UploadedFile::fake()->create('Program_HR_10_2026.xlsx', 10),
        ])->assertCreated()->json('data.id');
    }

    public function test_the_uploader_reads_their_run(): void
    {
        $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);
        $id = $this->upload();

        $this->getJson("/api/hr/employee-imports/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.summary.created', 1)
            ->assertJsonPath('data.report.rows.0.name', 'نور أحمد الشامي');
    }

    public function test_the_run_says_how_long_it_has_left_by_the_servers_clock(): void
    {
        // The screen counted down from expires_at against the browser's clock,
        // which can be off; seconds_left is the server's (final review, Part 1).
        $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);
        $id = $this->upload();

        $this->travel(10)->minutes();
        $this->getJson("/api/hr/employee-imports/{$id}")->assertJsonPath('data.seconds_left', 3000);

        $this->travel(51)->minutes();
        $this->getJson("/api/hr/employee-imports/{$id}")->assertJsonPath('data.seconds_left', 0);
    }

    public function test_another_users_run_is_404(): void
    {
        $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);
        $id = $this->upload();

        $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);
        $this->getJson("/api/hr/employee-imports/{$id}")->assertNotFound();
        $this->get("/api/hr/employee-imports/{$id}/report")->assertNotFound();
    }

    public function test_the_report_downloads_as_xlsx_with_the_sheets(): void
    {
        $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);
        $id = $this->upload();

        $response = $this->get("/api/hr/employee-imports/{$id}/report")->assertOk();

        $this->assertStringContainsString("تقرير-استيراد-الموظفين-{$id}.xlsx", rawurldecode((string) $response->headers->get('content-disposition')));
        $path = tempnam(sys_get_temp_dir(), 'rep').'.xlsx';
        file_put_contents($path, $response->streamedContent() ?: file_get_contents($response->baseResponse->getFile()->getPathname()));

        $reader = new Reader;
        $reader->open($path);
        $sheets = [];
        $cells = '';
        foreach ($reader->getSheetIterator() as $sheet) {
            $sheets[] = $sheet->getName();
            foreach ($sheet->getRowIterator() as $row) {
                $cells .= json_encode($row->toArray(), JSON_UNESCAPED_UNICODE);
            }
        }
        $reader->close();
        @unlink($path);

        $this->assertSame(['الملخص', 'السطور', 'التغييرات', 'غير موجودين في الملف'], $sheets);
        $this->assertStringContainsString('نور أحمد الشامي', $cells);
        $this->assertStringContainsString('تجربة — لم يُحفظ شيء', $cells, 'a checked run reports as a dry run');
    }

    public function test_the_report_file_is_built_in_the_private_folder(): void
    {
        // It holds names, national IDs and pay values: not the shared system
        // temp folder (final review, Part 1). Sent, then deleted after send.
        $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);
        $id = $this->upload();

        $this->get("/api/hr/employee-imports/{$id}/report")->assertOk();

        $this->assertCount(1, Storage::disk('local')->allFiles('hr-import/tmp'));
    }

    public function test_a_purged_report_cannot_be_downloaded(): void
    {
        $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);
        $id = $this->upload();
        EmployeeImportRun::whereKey($id)->update(['report' => null]);

        $this->get("/api/hr/employee-imports/{$id}/report")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'انتهت صلاحية هذا الفحص، ارفع الملف مرة أخرى.');
    }
}
