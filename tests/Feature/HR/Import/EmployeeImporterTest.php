<?php

declare(strict_types=1);

namespace Tests\Feature\HR\Import;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Import\EmployeeImporter;
use App\Modules\HR\Import\ImportReport;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeImportSource;
use App\Modules\HR\Models\JobTitle;
use Database\Seeders\DepartmentSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\Facades\DB;
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

    /**
     * One row of HR's one file. The person columns merged in from صرح follow
     * the full name unless a test gives them.
     *
     * @param  array<string, mixed>  $override
     */
    private function row(int $kobo = 582974643, array $override = []): array
    {
        $name = explode(' ', (string) ($override['الإسم الثلاثي'] ?? 'نور أحمد الشامي'), 3);

        return array_merge([
            'الاسم الأول'                  => $name[0] ?? null,
            'اسم الاب'                     => $name[1] ?? null,
            'الكنية'                       => $name[2] ?? null,
            'اسم الام'                     => null,
            'الرقم الوطني في الهوية'       => null,
            'عدد الأبناء الذكور'           => null,
            'عدد الأبناء الاناث'           => null,
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
     */
    private function import(array $rows, bool $dryRun = false, array $excludedRows = []): ImportReport
    {
        return app(EmployeeImporter::class)->run($rows, $dryRun, sourceFile: 'Program_HR_10_2026.xlsx', excludedRows: $excludedRows);
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

    // ── Blank person cells never erase (project owner, 2026-10-24) ────────

    /** @return array<string, mixed> the person cells of one row, filled */
    private function person(array $override = []): array
    {
        return array_merge([
            'اسم الام'               => 'فاطمة',
            'الرقم الوطني في الهوية' => '01234567890',
            'عدد الأبناء الذكور'     => 2,
            'عدد الأبناء الاناث'     => 1,
        ], $override);
    }

    public function test_blank_person_cells_keep_what_an_earlier_run_stored(): void
    {
        $this->import([$this->row(override: $this->person())]);

        $report = $this->import([$this->row()]);

        $employee = Employee::sole();
        $this->assertSame(1, $report->count('updated'));
        $this->assertSame('01234567890', $employee->national_id);
        $this->assertSame('فاطمة', $employee->mother_name);
        $this->assertSame(2, $employee->sons_count);
        $this->assertSame(1, $employee->daughters_count);
    }

    public function test_a_filled_person_cell_still_updates(): void
    {
        $this->import([$this->row(override: $this->person())]);

        $this->import([$this->row(override: $this->person(['اسم الام' => 'خديجة', 'عدد الأبناء الذكور' => 3]))]);

        $this->assertSame('خديجة', Employee::sole()->mother_name);
        $this->assertSame(3, Employee::sole()->sons_count);
    }

    public function test_a_new_person_with_blank_cells_gets_nulls_and_zero_counts(): void
    {
        $report = $this->import([$this->row()]);

        $employee = Employee::sole();
        $this->assertSame(1, $report->count('created'));
        $this->assertNull($employee->national_id);
        $this->assertNull($employee->mother_name);
        $this->assertSame(0, $employee->sons_count);
    }

    public function test_a_corrected_unit_on_a_re_run_is_a_correction_not_a_transfer(): void
    {
        $this->import([$this->row()]);

        $this->import([$this->row(override: ['المكتب' => 'الإنشائي'])]);

        $employee = Employee::sole();
        $this->assertSame('ENG-PLAN-STRU', $employee->department->code);
        $this->assertCount(1, $employee->assignments()->get(), 'no transfer posting invented');
    }

    // ── Not on duty (R1, 2026-10-22) ─────────────────────────────────────

    public function test_a_terminated_employee_in_the_workbook_is_left_untouched(): void
    {
        $this->assertLeftUntouched(EmployeeStatus::Terminated);
    }

    public function test_a_suspended_employee_is_left_untouched_too(): void
    {
        $this->assertLeftUntouched(EmployeeStatus::Suspended);
    }

    private function assertLeftUntouched(EmployeeStatus $status): void
    {
        $this->import([$this->row()]);
        Employee::sole()->update(['status' => $status]);

        $report = $this->import([$this->row(override: ['المسمى الوظيفي' => 'مهندس إنشائي'])]);

        $employee = Employee::sole();
        $this->assertSame($status, $employee->status);
        $this->assertSame('مهندس معماري', $employee->jobTitle->name);
        $this->assertSame(1, $report->count('skipped'));
        $this->assertContains(
            'الموظف ليس على رأس عمله في النظام — لم يُحدَّث من الملف. إن عاد إلى العمل فأعِد حالته من شاشة الموظف.',
            array_column($report->rows()[0]['issues'], 'message'),
        );
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

    // ── What an update changes (design §5.1) ─────────────────────────────

    public function test_a_dry_run_lists_what_an_update_would_change(): void
    {
        $this->import([$this->row(override: ['معرف / رقم  التواصل' => '0933111222'])]);
        Employee::sole()->update(['phone' => '0944999888']);

        $report = $this->import([$this->row(override: ['معرف / رقم  التواصل' => '0933111222'])], dryRun: true);

        $this->assertSame('0944999888', Employee::sole()->phone, 'a dry run changes nothing');
        $this->assertCount(1, $report->changes());
        $change = $report->changes()[0];
        $this->assertSame('نور أحمد الشامي', $change['name']);
        $this->assertSame(
            [['field' => 'phone', 'label' => 'الهاتف', 'old' => '0944999888', 'new' => '0933111222']],
            $change['fields'],
        );
    }

    public function test_an_unchanged_or_created_row_lists_no_changes(): void
    {
        $created = $this->import([$this->row()]);
        $unchanged = $this->import([$this->row()]);

        $this->assertSame([], $created->changes());
        $this->assertSame([], $unchanged->changes());
    }

    public function test_the_opening_postings_text_is_in_the_changes(): void
    {
        $this->import([$this->row()]);

        $report = $this->import([$this->row(override: ['مكان العمل بداية التعيين' => 'موقع المطار'])]);

        $this->assertSame(
            [['field' => 'legacy_workplace', 'label' => 'مكان العمل بداية التعيين', 'old' => 'الموقع العام', 'new' => 'موقع المطار']],
            $report->changes()[0]['fields'],
        );
    }

    // ── Not in the file (design §5.2) ────────────────────────────────────

    public function test_people_on_the_roll_missing_from_the_file_are_listed(): void
    {
        $this->import([$this->row(1), $this->row(2, ['الإسم الثلاثي' => 'سامر خالد الحلبي', 'رقم الشام كاش' => null])]);
        $byHand = Employee::factory()->create();
        $terminated = Employee::factory()->create(['status' => EmployeeStatus::Terminated]);
        $deleted = Employee::factory()->create();
        $deleted->delete();
        $samer = EmployeeImportSource::employeeFor('kobo:2');

        $report = $this->import([$this->row(1)]);

        $listed = collect($report->notInFile())->keyBy('employee_id');
        $this->assertEqualsCanonicalizing([$samer->id, $byHand->id], $listed->keys()->all());
        $this->assertTrue($listed[$samer->id]['imported']);
        $this->assertFalse($listed[$byHand->id]['imported']);
        $this->assertSame('سامر خالد الحلبي', $listed[$samer->id]['name']);
        $this->assertSame($samer->employee_number, $listed[$samer->id]['employee_number']);
        $this->assertSame(EmployeeStatus::Active, $samer->fresh()->status, 'listed, never changed (U3)');
        $this->assertNotContains($terminated->id, $listed->keys()->all());
    }

    public function test_a_failed_row_does_not_list_its_employee_as_missing(): void
    {
        $this->import([$this->row(1)]);

        $report = $this->import([$this->row(1, ['حالة الراتب ( نظام / إتفاق )' => 'اتفاق', 'الراتب المتفق عليه' => -5])]);

        $this->assertSame(1, $report->count('failed'));
        $this->assertSame([], $report->notInFile());
    }

    // ── Final review (2026-10-22) ────────────────────────────────────────

    public function test_an_unexpected_failure_reaches_the_report_without_its_detail(): void
    {
        // A SQL failure's message carries SQL, the schema and bound values; it
        // now travels to the API (C12). The row says the server failed, the
        // log has the detail.
        Employee::creating(fn () => throw new \RuntimeException('SQLSTATE[22001]: Data too long (SQL: insert into `employees` …)'));

        $report = $this->import([$this->row()]);

        $this->assertSame(1, $report->count('failed'));
        $messages = array_column($report->rows()[0]['issues'], 'message');
        $this->assertContains('تعذّر استيراد السطر بسبب خطأ في الخادم.', $messages);
        $this->assertStringNotContainsString('SQL', implode(' ', $messages));
    }

    public function test_a_domain_refusal_still_reaches_the_report_in_full(): void
    {
        $report = $this->import([$this->row(1, ['حالة الراتب ( نظام / إتفاق )' => 'اتفاق', 'الراتب المتفق عليه' => -5])]);

        $this->assertStringStartsWith('رُفض السطر: ', $report->rows()[0]['issues'][array_key_last($report->rows()[0]['issues'])]['message']);
    }

    public function test_a_dry_run_hashes_one_password_however_many_accounts_it_would_make(): void
    {
        // bcrypt per account made a dry run of 185 new people take ~30 s of
        // hashing for accounts it then rolls back.
        $hashes = new class($this->app) extends HashManager
        {
            public int $made = 0;

            public function make($value, array $options = []): string
            {
                $this->made++;

                return parent::make($value, $options);
            }
        };
        Hash::swap($hashes);

        $report = $this->import([
            $this->row(1),
            $this->row(2, ['الإسم الثلاثي' => 'سامر خالد الحلبي', 'رقم الشام كاش' => null]),
            $this->row(3, ['الإسم الثلاثي' => 'ليلى حسن ناصر', 'رقم الشام كاش' => null]),
        ], dryRun: true);

        $this->assertSame(3, $report->count('created'));
        $this->assertSame(1, $hashes->made);
        $this->assertSame(0, User::count());
    }

    // ── The number is the Kobo id (KOBO_EMPLOYEE_NUMBER_DESIGN.md §4) ────

    public function test_a_new_person_is_numbered_by_their_kobo_id(): void
    {
        $report = $this->import([$this->row(582974643)]);

        $this->assertSame('582974643', Employee::sole()->employee_number);
        $this->assertSame('582974643', $report->credentials()[0]['employee_number']);
    }

    /** Someone HR added by hand: the row's own person, by default. */
    private function handAdded(string $number, string $first = 'نور', string $middle = 'أحمد', string $last = 'الشامي'): Employee
    {
        return Employee::factory()->create([
            'employee_number' => $number, 'first_name' => $first, 'middle_name' => $middle, 'last_name' => $last,
        ]);
    }

    /** @return array<int, string> the row's messages, without the refusal prefix */
    private function rowMessages(ImportReport $report, int $index = 0): array
    {
        return array_map(fn ($m) => str_replace('رُفض السطر: ', '', $m), array_column($report->rows()[$index]['issues'], 'message'));
    }

    public function test_a_hand_added_person_with_that_number_is_adopted(): void
    {
        // Same person, spelled with a bare alef: names are compared folded.
        $person = $this->handAdded('582974643', middle: 'احمد');

        $dry = $this->import([$this->row(582974643)], dryRun: true);
        $this->assertSame('updated', $dry->rows()[0]['outcome']);
        $this->assertSame(1, Employee::count());

        $report = $this->import([$this->row(582974643)]);

        $this->assertSame('updated', $report->rows()[0]['outcome']);
        $this->assertSame(1, Employee::count());
        $this->assertSame($person->id, EmployeeImportSource::employeeFor('kobo:582974643')?->id);
    }

    public function test_a_holder_with_another_name_is_refused_not_merged(): void
    {
        // A typo by hand: Sami got Nour's Kobo id. Nour's row must not take
        // over Sami's record, history and account.
        $sami = $this->handAdded('582974643', 'سامي', 'خالد', 'الحلبي');

        foreach ([true, false] as $dryRun) {
            $report = $this->import([$this->row(582974643)], dryRun: $dryRun);

            $this->assertSame('failed', $report->rows()[0]['outcome']);
            $this->assertContains(
                'الرقم الوظيفي 582974643 مسجّل للموظف «سامي خالد الحلبي»، والاسم في الملف «نور أحمد الشامي» — لم يُستورد هذا السطر. صحّح الرقم أو الاسم ثم أعد الاستيراد.',
                $this->rowMessages($report),
            );
        }
        $this->assertSame(1, Employee::count());
        $this->assertSame('سامي', $sami->fresh()->first_name);
        $this->assertNull(EmployeeImportSource::employeeFor('kobo:582974643'));
    }

    public function test_a_deleted_holder_is_refused_not_restored(): void
    {
        // A record deleted before deleting freed the number (2026-10-24).
        $gone = $this->handAdded('582974643');
        DB::table('employees')->where('id', $gone->id)->update(['deleted_at' => now()]);

        $report = $this->import([$this->row(582974643)]);

        $this->assertSame('failed', $report->rows()[0]['outcome']);
        $this->assertContains(
            "الرقم الوظيفي 582974643 مسجّل لموظف محذوف (#{$gone->id}) — لم يُستورد هذا السطر.",
            $this->rowMessages($report),
        );
        $this->assertTrue(Employee::withTrashed()->find($gone->id)->trashed());
    }

    public function test_a_number_freed_by_a_deletion_goes_to_a_new_person(): void
    {
        $wrong = $this->handAdded('582974643', 'سامي', 'خالد', 'الحلبي');
        $wrong->delete();

        $report = $this->import([$this->row(582974643)]);

        $this->assertSame('created', $report->rows()[0]['outcome']);
        $this->assertSame('582974643', EmployeeImportSource::employeeFor('kobo:582974643')->employee_number);
        $this->assertTrue(Employee::withTrashed()->find($wrong->id)->trashed());
    }

    public function test_an_imported_person_deleted_and_back_in_the_file_gets_their_number_back(): void
    {
        $this->import([$this->row(582974643)]);
        Employee::sole()->delete();

        $report = $this->import([$this->row(582974643)]);

        $this->assertSame('restored', $report->rows()[0]['outcome']);
        $this->assertSame('582974643', Employee::sole()->employee_number);
    }

    public function test_an_imported_person_whose_number_was_taken_meanwhile_is_not_restored(): void
    {
        $this->import([$this->row(582974643)]);
        $gone = Employee::sole();
        $gone->delete();
        $this->handAdded('582974643', 'سامي', 'خالد', 'الحلبي');

        $report = $this->import([$this->row(582974643)]);

        $this->assertSame('failed', $report->rows()[0]['outcome']);
        $this->assertContains('الرقم الوظيفي 582974643 صار لموظف آخر — لا يُستعاد هذا الموظف.', $this->rowMessages($report));
        $this->assertTrue(Employee::withTrashed()->find($gone->id)->trashed());
    }

    public function test_a_holder_known_under_another_key_is_refused_not_merged(): void
    {
        $this->import([$this->row(111)]);
        EmployeeImportSource::employeeFor('kobo:111')->renumberTo('582974643');

        $report = $this->import([$this->row(582974643, ['الإسم الثلاثي' => 'سامر خالد الحلبي'])]);

        $this->assertSame('failed', $report->rows()[0]['outcome']);
        $this->assertContains(
            'الرقم الوظيفي 582974643 مسجّل لموظف مستورد من سطر آخر — لم يُستورد هذا السطر.',
            array_map(fn ($m) => str_replace('رُفض السطر: ', '', $m), array_column($report->rows()[0]['issues'], 'message')),
        );
        $this->assertSame(1, Employee::count());
    }

    // ── Excluded rows (design §12) ───────────────────────────────────────

    private const EXCLUDED = 'استُبعد هذا السطر من الاستيراد في هذه المرة.';

    public function test_an_excluded_new_person_is_not_created(): void
    {
        $report = $this->import([
            4 => $this->row(1),
            5 => $this->row(2, ['الإسم الثلاثي' => 'سامر خالد الحلبي', 'رقم الشام كاش' => null]),
        ], excludedRows: [5]);

        $this->assertSame(1, Employee::count());
        $this->assertSame(1, User::count());
        $this->assertCount(1, $report->credentials());
        $row = $report->rows()[1];
        $this->assertSame('excluded', $row['outcome']);
        $this->assertContains(self::EXCLUDED, array_column($row['issues'], 'message'));
    }

    public function test_an_excluded_existing_person_is_left_as_the_erp_has_them(): void
    {
        $this->import([4 => $this->row(override: ['معرف / رقم  التواصل' => '0933111222'])]);
        $employee = Employee::sole();

        $report = $this->import([4 => $this->row(override: ['معرف / رقم  التواصل' => '0944999888'])], excludedRows: [4]);

        $this->assertSame('0933111222', $employee->fresh()->phone);
        $this->assertSame([], $report->changes());
        $this->assertSame('excluded', $report->rows()[0]['outcome']);
        $this->assertSame($employee->employee_number, $report->rows()[0]['employee_number']);
    }

    public function test_an_excluded_person_is_not_listed_as_missing(): void
    {
        $this->import([4 => $this->row()]);

        $report = $this->import([4 => $this->row()], excludedRows: [4]);

        $this->assertSame([], $report->notInFile());
    }

    public function test_an_excluded_row_no_longer_holds_a_national_id_against_a_later_row(): void
    {
        $rows = [
            4 => $this->row(1, ['الرقم الوطني في الهوية' => '01234567890']),
            5 => $this->row(2, ['الإسم الثلاثي' => 'سامر خالد الحلبي', 'رقم الشام كاش' => null, 'الرقم الوطني في الهوية' => '01234567890']),
        ];
        $held = fn (ImportReport $report): array => array_filter(
            $report->rows()[1]['issues'],
            fn (array $issue): bool => $issue['field'] === 'national_id',
        );

        $this->assertNotEmpty($held($this->import($rows, dryRun: true)), 'the first row holds it');

        $report = $this->import($rows, excludedRows: [4]);

        $this->assertSame([], $held($report));
        $this->assertSame('01234567890', EmployeeImportSource::employeeFor('kobo:2')->national_id);
    }

    public function test_the_summary_counts_excluded_rows(): void
    {
        $report = $this->import([4 => $this->row()], dryRun: true, excludedRows: [4]);

        $this->assertSame(1, $report->summary()['excluded']);
        $this->assertSame(0, $report->summary()['created']);
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
