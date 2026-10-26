<?php

declare(strict_types=1);

namespace Tests\Feature\HR\Import;

use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Import\EmployeeImporter;
use App\Modules\HR\Import\WorkbookReader;
use App\Modules\HR\Models\EmployeeImportRun;
use App\Modules\HR\Models\EmployeeImportSource;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PUT /api/hr/employee-imports/{run}/exclusions — HR leaves rows out of this
 * import, and the dry run is re-checked without them
 * (EMPLOYEE_IMPORT_UPLOAD_DESIGN.md §12).
 *
 * The workbook: rows 4 and 9 are two people the import would create, row 5
 * has no ID_Sarah and would be skipped.
 */
class EmployeeImportExclusionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(DepartmentSeeder::class);
        $this->app->instance(WorkbookReader::class, new FakeImportReader);
        FakeImportReader::reset();
        FakeImportReader::$rows = [
            4 => FakeImportReader::person(582974643, 'نور أحمد الشامي'),
            5 => ['ID_Sarah' => null, 'الإسم الثلاثي' => 'سامر خالد الحلبي'],
            9 => FakeImportReader::person(582974644, 'ليلى حسن ناصر'),
        ];
        $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);
    }

    /** @return array<string, mixed> the run as the upload returned it */
    private function upload(): array
    {
        return $this->post('/api/hr/employee-imports', [
            'workbook' => UploadedFile::fake()->create('Program_HR_10_2026.xlsx', 10),
        ])->assertCreated()->json('data');
    }

    private function exclude(int $id, mixed $rows): TestResponse
    {
        return $this->putJson("/api/hr/employee-imports/{$id}/exclusions", ['rows' => $rows]);
    }

    /** @param  array<string, mixed>  $run */
    private function outcomeOf(array $run, int $row): string
    {
        return collect($run['report']['rows'])->firstWhere('row', $row)['outcome'];
    }

    public function test_excluding_a_row_rechecks_the_report(): void
    {
        $id = $this->upload()['id'];

        $run = $this->exclude($id, [9])->assertOk()->json('data');

        $this->assertSame([9], $run['excluded_rows']);
        $this->assertSame(1, $run['summary']['excluded']);
        $this->assertSame(1, $run['summary']['created']);
        $this->assertSame('excluded', $this->outcomeOf($run, 9));
        $this->assertSame([4, 9], $run['importable_rows'], 'what may be excluded does not shrink');
    }

    public function test_an_empty_list_restores_the_first_report_exactly(): void
    {
        $first = $this->upload();
        $this->exclude($first['id'], [9])->assertOk();

        $run = $this->exclude($first['id'], [])->assertOk()->json('data');

        $this->assertSame([], $run['excluded_rows']);
        $this->assertSame($first['report'], $run['report']);
        $this->assertSame($first['summary'], $run['summary']);
    }

    public function test_an_excluded_row_can_be_brought_back(): void
    {
        $id = $this->upload()['id'];
        $this->exclude($id, [9])->assertOk();

        $run = $this->exclude($id, [4])->assertOk()->json('data');

        $this->assertSame([4], $run['excluded_rows']);
        $this->assertSame('created', $this->outcomeOf($run, 9));
    }

    public function test_a_row_that_became_importable_after_the_upload_can_be_excluded(): void
    {
        // Row 4's person is suspended at upload (R1: left untouched, so the
        // row is not importable). Reinstated in the ERP before the re-check,
        // the real run would update them — so they must be excludable then.
        app(EmployeeImporter::class)->run([4 => FakeImportReader::person(582974643, 'نور أحمد الشامي')]);
        $person = EmployeeImportSource::employeeFor('kobo:582974643');
        $person->update(['status' => EmployeeStatus::Suspended]);
        $first = $this->upload();
        $this->assertSame([9], $first['importable_rows']);

        $person->update(['status' => EmployeeStatus::Active]);
        $run = $this->exclude($first['id'], [4])->assertOk()->json('data');

        $this->assertSame([4], $run['excluded_rows']);
        $this->assertSame([4, 9], $run['importable_rows']);
        $this->assertSame('excluded', $this->outcomeOf($run, 4));
    }

    public function test_duplicates_in_the_list_are_ignored(): void
    {
        $id = $this->upload()['id'];

        $this->exclude($id, [9, 9])->assertOk()->assertJsonPath('data.excluded_rows', [9]);
    }

    public function test_a_row_not_in_the_file_is_refused(): void
    {
        $first = $this->upload();

        $this->exclude($first['id'], [9, 99])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'السطر 99 غير موجود في الملف.');

        $this->assertSame([], EmployeeImportRun::findOrFail($first['id'])->excluded_rows, 'nothing saved');
    }

    public function test_a_row_that_would_not_be_imported_is_refused(): void
    {
        $id = $this->upload()['id'];

        $this->exclude($id, [5])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'السطر 5 لا يمكن استبعاده: لن يُستورد أصلاً.');
    }

    public function test_validation(): void
    {
        $id = $this->upload()['id'];

        $this->putJson("/api/hr/employee-imports/{$id}/exclusions", [])->assertUnprocessable()->assertJsonValidationErrors('rows');
        $this->exclude($id, '4')->assertUnprocessable()->assertJsonValidationErrors('rows');
        $this->exclude($id, ['a'])->assertUnprocessable()->assertJsonValidationErrors('rows.0');
    }

    public function test_the_list_is_capped_at_5000_rows(): void
    {
        // A sheet has a few hundred rows; an unbounded list costs seconds of
        // validation and the memory to hold it before anything is refused.
        $id = $this->upload()['id'];

        $this->exclude($id, range(1, 5001))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rows')
            ->assertJsonPath('errors.rows.0', 'لا يمكن استبعاد أكثر من 5000 سطر.');
    }

    public function test_an_executed_or_expired_run_is_refused(): void
    {
        $executed = $this->upload()['id'];
        $this->postJson("/api/hr/employee-imports/{$executed}/execute")->assertOk();
        $this->exclude($executed, [9])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'تم تنفيذ هذا الاستيراد من قبل.');

        $expiring = $this->upload()['id'];
        $this->travel(61)->minutes();
        $this->exclude($expiring, [9])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'انتهت صلاحية هذا الفحص، ارفع الملف مرة أخرى.');
    }

    public function test_a_held_lock_refuses_with_409_and_saves_nothing(): void
    {
        $id = $this->upload()['id'];
        $lock = Cache::lock('hr-employee-import', 60);
        $lock->get();

        $this->exclude($id, [9])->assertStatus(409);

        $lock->release();
        $this->assertSame([], EmployeeImportRun::findOrFail($id)->excluded_rows);
    }

    public function test_another_users_run_is_404(): void
    {
        $id = $this->upload()['id'];

        $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);
        $this->exclude($id, [9])->assertNotFound();
    }

    public function test_the_hour_does_not_restart(): void
    {
        $id = $this->upload()['id'];
        $before = EmployeeImportRun::findOrFail($id)->expires_at;
        $this->travel(10)->minutes();

        $this->exclude($id, [9])->assertOk();

        $this->assertTrue($before->equalTo(EmployeeImportRun::findOrFail($id)->expires_at));
    }

    public function test_each_change_is_audited_with_the_row_numbers(): void
    {
        $id = $this->upload()['id'];

        $this->exclude($id, [9])->assertOk();

        $row = DB::table('audit_logs')->where('event', 'employees_import_excluded')->sole();
        $this->assertStringContainsString('"excluded_rows":[9]', (string) $row->new_values);
        $this->assertStringNotContainsString('ليلى', (string) $row->new_values);
    }
}
