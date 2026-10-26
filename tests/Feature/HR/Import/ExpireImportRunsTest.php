<?php

declare(strict_types=1);

namespace Tests\Feature\HR\Import;

use App\Modules\HR\Enums\ImportRunStatus;
use App\Modules\HR\Import\WorkbookReader;
use App\Modules\HR\Models\EmployeeImportRun;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * hr:expire-import-runs — an upload's files and report go an hour after it;
 * who, when, which files and the counts stay (EMPLOYEE_IMPORT_UPLOAD_DESIGN.md
 * §4.6).
 */
class ExpireImportRunsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(DepartmentSeeder::class);
        $this->app->instance(WorkbookReader::class, new FakeImportReader);
        FakeImportReader::reset();
        $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);
    }

    private function upload(): EmployeeImportRun
    {
        $id = $this->post('/api/hr/employee-imports', [
            'workbook' => UploadedFile::fake()->create('Program_HR_10_2026.xlsx', 10),
        ])->assertCreated()->json('data.id');

        return EmployeeImportRun::findOrFail($id);
    }

    public function test_an_expired_checked_run_loses_its_files_and_report(): void
    {
        $run = $this->upload();
        $this->travel(61)->minutes();

        $this->artisan('hr:expire-import-runs')->expectsOutputToContain('1')->assertSuccessful();

        $run->refresh();
        $this->assertSame(ImportRunStatus::Expired, $run->status);
        $this->assertNull($run->report);
        $this->assertNull($run->workbook_path);
        $this->assertSame(1, $run->summary['created']);
        $this->assertSame('Program_HR_10_2026.xlsx', $run->workbook_name);
        $this->assertNotNull($run->workbook_sha256);
        $this->assertSame([], Storage::disk('local')->allFiles('hr-import'));
    }

    public function test_an_expired_executed_run_keeps_its_status_and_summary(): void
    {
        $run = $this->upload();
        $this->postJson("/api/hr/employee-imports/{$run->id}/execute")->assertOk();
        $this->travel(61)->minutes();

        $this->artisan('hr:expire-import-runs')->assertSuccessful();

        $run->refresh();
        $this->assertSame(ImportRunStatus::Executed, $run->status);
        $this->assertNull($run->report);
        $this->assertSame(1, $run->summary['created']);
    }

    public function test_a_live_run_is_untouched(): void
    {
        $run = $this->upload();
        $this->travel(59)->minutes();

        $this->artisan('hr:expire-import-runs')->assertSuccessful();

        $run->refresh();
        $this->assertSame(ImportRunStatus::Checked, $run->status);
        $this->assertNotNull($run->report);
        $this->assertCount(1, Storage::disk('local')->allFiles('hr-import'));
    }

    public function test_the_sweep_waits_for_a_running_import(): void
    {
        // Outside the lock, a sweep firing during a re-check or a real run
        // could expire the run under it, delete its files mid-read, or have
        // its report written back afterwards (final reviews, Parts 1 and 2).
        $run = $this->upload();
        $this->travel(61)->minutes();
        $lock = Cache::lock('hr-employee-import', 60);
        $lock->get();

        $this->artisan('hr:expire-import-runs')->expectsOutputToContain('an import is running')->assertSuccessful();

        $lock->release();
        $this->assertSame(ImportRunStatus::Checked, $run->refresh()->status, 'left for the next sweep');
        $this->assertNotNull($run->report);
    }

    public function test_the_command_is_scheduled_every_five_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $e): bool => str_contains((string) $e->command, 'hr:expire-import-runs'));

        // Every five minutes: an hourly sweep let files outlive their hour by up
        // to another (U5 says at most an hour; final review, Part 1).
        $this->assertSame('*/5 * * * *', $event?->expression);
    }
}
