<?php

declare(strict_types=1);

namespace Tests\Feature\HR\Import;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ImportRunStatus;
use App\Modules\HR\Import\WorkbookReader;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeImportRun;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * POST /api/hr/employee-imports/{run}/execute — the real import, once, on the
 * files the dry run read; the temporary passwords in this response and
 * nowhere else (EMPLOYEE_IMPORT_UPLOAD_DESIGN.md §4.2, U4, U5).
 */
class EmployeeImportExecuteTest extends TestCase
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

    private function execute(int $id): TestResponse
    {
        return $this->postJson("/api/hr/employee-imports/{$id}/execute");
    }

    /** @return array<int, array<int, mixed>> the credentials workbook's rows */
    private function credentialRows(string $base64): array
    {
        $path = tempnam(sys_get_temp_dir(), 'cred-test').'.xlsx';
        file_put_contents($path, base64_decode($base64, true));
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();
        @unlink($path);

        return $rows;
    }

    public function test_execute_imports_and_returns_the_passwords_once(): void
    {
        $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);
        $id = $this->upload();

        $response = $this->execute($id)
            ->assertOk()
            ->assertJsonPath('data.run.status', 2)
            ->assertJsonPath('data.run.summary.created', 1);

        $employee = Employee::sole();
        $this->assertSame('01234567890', $employee->national_id, 'the merged person columns were read');
        $this->assertStringEndsWith('.xlsx', $response->json('data.credentials.file_name'));
        $this->assertStringStartsWith('كلمات-المرور-المؤقتة-', $response->json('data.credentials.file_name'));

        [$header, $credential] = $this->credentialRows($response->json('data.credentials.content_base64'));
        $this->assertSame(['الرقم الوظيفي', 'الاسم', 'كلمة المرور المؤقتة'], $header);
        $this->assertSame($employee->employee_number, $credential[0]);
        $this->assertSame(10, strlen($credential[2]));
        $this->assertTrue(Hash::check($credential[2], User::where('employee_id', $employee->id)->sole()->password));

        $this->assertSame([], Storage::disk('local')->allFiles('hr-import'), 'the files go at the real run');
        $run = EmployeeImportRun::sole();
        $this->assertNull($run->workbook_path);
        $this->assertNotNull($run->executed_at);
    }

    public function test_execute_refuses_files_changed_since_the_check(): void
    {
        // "Imports exactly the files the dry run read" (U5) is now checked, not
        // assumed: the hashes taken at the check must still match (final
        // review, Part 1).
        $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);
        $id = $this->upload();
        $run = EmployeeImportRun::findOrFail($id);
        Storage::disk('local')->put($run->workbook_path, 'another file');

        $this->execute($id)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'تغيّر الملف المخزّن منذ الفحص؛ ارفع الملف مرة أخرى.');

        $this->assertSame(0, Employee::count());
    }

    public function test_the_passwords_file_is_built_in_the_private_folder(): void
    {
        // Not the shared system temp folder, where other local users may read
        // it while it exists (final review, Part 1).
        $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);
        $id = $this->upload();

        $this->execute($id)->assertOk()->assertJsonPath('data.credentials.file_name', fn ($name) => $name !== null);

        $this->assertTrue(Storage::disk('local')->directoryExists('hr-import/tmp'), 'built there');
        $this->assertSame([], Storage::disk('local')->allFiles('hr-import/tmp'), 'and deleted');
    }

    public function test_execute_twice_is_refused_and_creates_nobody(): void
    {
        $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);
        $id = $this->upload();
        $this->execute($id)->assertOk();

        $this->execute($id)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'تم تنفيذ هذا الاستيراد من قبل.');

        $this->assertSame(1, Employee::count());
    }

    public function test_an_expired_run_is_refused(): void
    {
        $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);
        $id = $this->upload();

        $this->travel(61)->minutes();

        $this->execute($id)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'انتهت صلاحية هذا الفحص، ارفع الملف مرة أخرى.');
        $this->assertSame(0, Employee::count());
    }

    public function test_another_users_run_is_404(): void
    {
        $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);
        $id = $this->upload();

        $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);
        $this->execute($id)->assertNotFound();
        $this->assertSame(0, Employee::count());
    }

    public function test_a_held_lock_refuses_with_409(): void
    {
        $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);
        $id = $this->upload();
        $lock = Cache::lock('hr-employee-import', 60);
        $lock->get();

        $this->execute($id)->assertStatus(409);

        $lock->release();
        $this->assertSame(0, Employee::count());
    }

    public function test_no_password_is_stored_logged_or_left_on_disk(): void
    {
        $logged = [];
        $this->app['events']->listen(MessageLogged::class, function (MessageLogged $m) use (&$logged): void {
            $logged[] = $m->message.json_encode($m->context, JSON_UNESCAPED_UNICODE);
        });
        $leftBefore = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'hr-import-credentials-*') ?: [];

        $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);
        $id = $this->upload();
        $response = $this->execute($id)->assertOk();
        $password = $this->credentialRows($response->json('data.credentials.content_base64'))[1][2];

        $run = DB::table('employee_import_runs')->sole();
        $this->assertStringNotContainsString($password, json_encode($run, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString($password, DB::table('audit_logs')->get()->toJson(JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString($password, implode("\n", $logged));
        $this->assertSame($leftBefore, glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'hr-import-credentials-*') ?: []);
    }

    public function test_an_executed_run_read_back_carries_no_credentials(): void
    {
        $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);
        $id = $this->upload();
        $password = $this->credentialRows($this->execute($id)->json('data.credentials.content_base64'))[1][2];

        $read = $this->getJson("/api/hr/employee-imports/{$id}")->assertOk();

        $this->assertArrayNotHasKey('credentials', $read->json('data'));
        $this->assertStringNotContainsString($password, $read->getContent());
        $this->assertSame(2, $read->json('data.status'));
    }

    public function test_executing_a_run_retires_every_other_checked_run(): void
    {
        // A dry run computed against the database as it was must not be
        // executable once another import has changed it — an old tab would
        // reapply an older workbook over the newer one's fixes.
        $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);
        $older = $this->upload();
        $newer = $this->upload();

        $this->execute($newer)->assertOk();

        $this->execute($older)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'انتهت صلاحية هذا الفحص، ارفع الملف مرة أخرى.');
        $this->assertSame(ImportRunStatus::Expired, EmployeeImportRun::findOrFail($older)->status);
        $this->assertSame([], Storage::disk('local')->allFiles('hr-import'), "the retired run's files go too");
    }

    public function test_check_and_execute_lift_the_time_limit_for_the_long_run(): void
    {
        // 185 new accounts cost ~30 s of bcrypt alone in a real run.
        $before = ini_get('max_execution_time');
        $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);

        try {
            ini_set('max_execution_time', '30');
            $id = $this->upload();
            $this->assertSame('600', ini_get('max_execution_time'));

            ini_set('max_execution_time', '30');
            $this->execute($id)->assertOk();
            $this->assertSame('600', ini_get('max_execution_time'));
        } finally {
            ini_set('max_execution_time', (string) $before);
        }
    }

    // ── Excluded rows (design §12) ───────────────────────────────────────

    private function twoPeople(): void
    {
        FakeImportReader::$rows = [
            4 => FakeImportReader::person(582974643, 'نور أحمد الشامي'),
            9 => FakeImportReader::person(582974644, 'ليلى حسن ناصر'),
        ];
    }

    public function test_execute_leaves_the_excluded_rows_out(): void
    {
        $this->twoPeople();
        $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);
        $id = $this->upload();
        $this->putJson("/api/hr/employee-imports/{$id}/exclusions", ['rows' => [9]])->assertOk();

        $response = $this->execute($id)
            ->assertOk()
            ->assertJsonPath('data.run.summary.excluded', 1)
            ->assertJsonPath('data.run.summary.created', 1);

        $this->assertSame(['نور أحمد الشامي'], Employee::all()->map->full_name->all());
        $this->assertCount(2, $this->credentialRows($response->json('data.credentials.content_base64')), 'a header and one person');
    }

    public function test_execute_refuses_a_list_other_than_the_one_saved(): void
    {
        // A stale screen — a lost apply response, a second tab — confirms
        // counts for a list the server no longer holds (final review, Part 2).
        $this->twoPeople();
        $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);
        $id = $this->upload();
        $this->putJson("/api/hr/employee-imports/{$id}/exclusions", ['rows' => [9]])->assertOk();

        $this->postJson("/api/hr/employee-imports/{$id}/execute", ['excluded_rows' => []])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'تغيّرت قائمة المستبعدين منذ عُرضت هذه الصفحة؛ راجع الفحص ثم استورد.');

        $this->assertSame(0, Employee::count());
        $this->assertSame(1, EmployeeImportRun::findOrFail($id)->status->value, 'still checked');
    }

    public function test_execute_with_the_saved_list_imports(): void
    {
        $this->twoPeople();
        $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);
        $id = $this->upload();
        $this->putJson("/api/hr/employee-imports/{$id}/exclusions", ['rows' => [9]])->assertOk();

        $this->postJson("/api/hr/employee-imports/{$id}/execute", ['excluded_rows' => [9]])->assertOk();

        $this->assertSame(1, Employee::count());
    }

    public function test_execute_validates_the_list_it_is_sent(): void
    {
        $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);
        $id = $this->upload();

        $this->postJson("/api/hr/employee-imports/{$id}/execute", ['excluded_rows' => 'all'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('excluded_rows');
    }

    public function test_excluding_everyone_imports_nobody(): void
    {
        $this->twoPeople();
        $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);
        $id = $this->upload();
        $this->putJson("/api/hr/employee-imports/{$id}/exclusions", ['rows' => [4, 9]])->assertOk();

        $this->execute($id)
            ->assertOk()
            ->assertJsonPath('data.credentials', null)
            ->assertJsonPath('data.run.status', 2);

        $this->assertSame(0, Employee::count());
        $this->assertSame(0, User::whereNotNull('employee_id')->count());
    }

    public function test_the_executed_audit_entry_lists_the_excluded_rows(): void
    {
        $this->twoPeople();
        $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);
        $id = $this->upload();
        $this->putJson("/api/hr/employee-imports/{$id}/exclusions", ['rows' => [9]])->assertOk();

        $this->execute($id)->assertOk();

        $row = DB::table('audit_logs')->where('event', 'employees_import_executed')->sole();
        $this->assertStringContainsString('"excluded_rows":[9]', (string) $row->new_values);
    }

    public function test_the_import_is_audited_with_the_uploader_as_actor(): void
    {
        $user = $this->actingAsUserWithPermissions(EmployeeImportUploadTest::PERMISSIONS);
        $id = $this->upload();
        $this->execute($id)->assertOk();

        $executed = DB::table('audit_logs')->where('event', 'employees_import_executed')->sole();
        $this->assertSame($user->id, (int) $executed->user_id);
        $this->assertStringNotContainsString('الشامي', (string) $executed->new_values);

        $created = DB::table('audit_logs')
            ->where('event', 'created')
            ->where('auditable_type', 'employee')
            ->where('auditable_id', Employee::sole()->id)
            ->sole();
        $this->assertSame($user->id, (int) $created->user_id);
    }
}
