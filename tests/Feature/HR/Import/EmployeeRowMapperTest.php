<?php

declare(strict_types=1);

namespace Tests\Feature\HR\Import;

use App\Modules\HR\Enums\ActivityType;
use App\Modules\HR\Enums\EducationLevel;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\EmploymentType;
use App\Modules\HR\Enums\EngineeringRank;
use App\Modules\HR\Enums\Gender;
use App\Modules\HR\Enums\Governorate;
use App\Modules\HR\Enums\MaritalStatus;
use App\Modules\HR\Enums\SalaryMode;
use App\Modules\HR\Import\CellError;
use App\Modules\HR\Import\EmployeeRowMapper;
use App\Modules\HR\Import\MappedRow;
use DateTimeImmutable;
use Tests\TestCase;

/**
 * One workbook row, plus its صرح record, to employee attributes and report
 * issues (docs/WORKBOOK_COLUMN_MAP.md). Pure: no database.
 *
 * The rows below use the October workbook's real headers and real value
 * shapes, with made-up people.
 */
class EmployeeRowMapperTest extends TestCase
{
    /** @param  array<string, mixed>  $override */
    private function row(array $override = []): array
    {
        return array_merge([
            'التسلسل'                      => 1,
            'أسماء الإدارات'               => 'الإدارة الهندسية',
            'القسم'                        => 'قسم التخطيط والدراسات',
            'المكتب'                       => 'المعماري',
            'المسمى الوظيفي'               => 'مهندس معماري',
            'السوية التنظيمية'             => 4,
            'نوع النشاط'                   => 'هندسي',
            'مكان الراتب'                  => 'مكتب رئيسي - هندسي',
            'الحالة'                       => 'متعاقد',
            'الرتبة الهندسية'              => 'مشارك2',
            'سنوات الخبرة الصناعية'        => null,
            'الإسم الثلاثي'                => 'نور أحمد الشامي',
            'اللقب'                        => ' أبو أحمد ',
            'المرحلة الدراسية'             => 'كلية',
            'المؤهل العلمي'                => 'هندسة معمارية',
            'المواليد'                     => 1990,
            'المحافظة'                     => 'حلب - الفرقان',
            'مكان العمل'                   => 'حلب',
            'الحالة الاجتماعية'            => 'متزوج',
            'عدد الزوجات'                  => 1,
            'عدد الأبناء'                  => 2,
            'رقم الشام كاش'                => '9200274887373820',
            'تاريخ التعيين'                => new DateTimeImmutable('2022-01-01'),
            'مكان العمل بداية التعيين'     => 'الموقع العام',
            'المدير المباشر بداية التعيين' => 'أبو يعرب',
            'المسمى الوظيفي بداية التعيين' => 'مهندس متدرب',
            'معرف / رقم  التواصل'          => 963989997751,
            'الخبرة'                       => 'مشارك2',
            'الخطورة'                      => null,
            'الندرة'                       => null,
            'إزدواجية العمل'               => null,
            'الراتب المتفق عليه'           => null,
            'حالة الراتب ( نظام / إتفاق )' => 'نظام رواتب',
            'تاريخ التخرج الدقيق'          => new DateTimeImmutable('2013-07-01'),
            'الاختصاص الدقيق'              => 'الهندسة المعمارية',
            'الجنس'                        => 'ذكر',
            'ID_Sarah'                     => 582974643,
            'مكان الدوام'                  => null,
            'مواليد_دقيقة'                 => new DateTimeImmutable('1990-03-15'),
        ], $override);
    }

    /** @param  array<string, mixed>  $override */
    private function sarh(array $override = []): array
    {
        return array_merge([
            '_id'                     => 582974643,
            'الاسم الأول'             => 'نور',
            'اسم الاب'                => 'أحمد',
            'الكنية'                  => 'الشامي',
            'اسم الام'                => 'فاطمة',
            'تاريخ التولد'            => new DateTimeImmutable('1990-03-15'),
            'الرقم الوطني في الهوية'  => 2010012345,
            'عدد الأبناء الذكور'      => 1,
            'عدد الأبناء الاناث'      => 1,
            // Present in the file and NEVER read (the allow-list):
            'الديانة'                 => 'x',
            'المذهب'                  => 'x',
            'هل لديك أي سوابق جنائية؟' => 'x',
            'صورة الهوية وجه امامي_URL' => 'https://kf.kobotoolbox.org/x',
        ], $override);
    }

    private function map(array $row, ?array $sarh = null): MappedRow
    {
        return (new EmployeeRowMapper)->map($row, $sarh);
    }

    /** @return array<int, string> */
    private function messages(MappedRow $mapped, string $severity): array
    {
        return array_column(array_filter($mapped->issues, fn ($i) => $i['severity'] === $severity), 'message');
    }

    // ── The key ──────────────────────────────────────────────────────────

    public function test_the_key_is_the_kobo_id(): void
    {
        $this->assertSame('kobo:582974643', $this->map($this->row())->sourceKey);
        $this->assertSame('kobo:582974643', $this->map($this->row(['ID_Sarah' => 582974643.0]))->sourceKey);
    }

    public function test_a_row_without_a_kobo_id_has_no_key_and_an_error(): void
    {
        $mapped = $this->map($this->row(['ID_Sarah' => null]));

        $this->assertNull($mapped->sourceKey);
        $this->assertNotEmpty($this->messages($mapped, 'error'));
    }

    // ── Identity ─────────────────────────────────────────────────────────

    public function test_names_come_from_the_sarh_record_when_there_is_one(): void
    {
        $a = $this->map($this->row(['الإسم الثلاثي' => 'خلدون قرنفل علي قرنفل']), $this->sarh([
            'الاسم الأول' => 'خلدون', 'اسم الاب' => 'قرنفل', 'الكنية' => 'علي قرنفل',
        ]))->attributes;

        $this->assertSame(['خلدون', 'قرنفل', 'علي قرنفل'], [$a['first_name'], $a['middle_name'], $a['last_name']]);
    }

    public function test_without_sarh_a_long_name_keeps_the_rest_as_the_last_name_and_is_reported(): void
    {
        $mapped = $this->map($this->row(['الإسم الثلاثي' => 'خلدون قرنفل علي قرنفل']));

        $this->assertSame('علي قرنفل', $mapped->attributes['last_name']);
        $this->assertNotEmpty($this->messages($mapped, 'warning'));
    }

    public function test_the_nickname_and_payroll_location_are_trimmed(): void
    {
        $a = $this->map($this->row())->attributes;

        $this->assertSame('أبو أحمد', $a['nickname']);
        $this->assertSame('مكتب رئيسي - هندسي', $a['payroll_location']);
    }

    public function test_a_ten_digit_national_id_gets_its_leading_zero_back(): void
    {
        $this->assertSame('02010012345', $this->map($this->row(), $this->sarh())->attributes['national_id']);
        $this->assertSame('02010012345', $this->map($this->row(), $this->sarh(['الرقم الوطني في الهوية' => '02010012345']))->attributes['national_id']);
    }

    public function test_a_national_id_too_short_to_be_one_is_not_stored(): void
    {
        $mapped = $this->map($this->row(), $this->sarh(['الرقم الوطني في الهوية' => 123456]));

        $this->assertNull($mapped->attributes['national_id']);
        $this->assertNotEmpty($this->messages($mapped, 'warning'));
    }

    public function test_the_sarh_record_supplies_mother_birth_date_and_children(): void
    {
        $a = $this->map($this->row(['مواليد_دقيقة' => null]), $this->sarh())->attributes;

        $this->assertSame('فاطمة', $a['mother_name']);
        $this->assertSame('1990-03-15', $a['birth_date']);
        $this->assertSame([1, 1], [$a['sons_count'], $a['daughters_count']]);
    }

    public function test_the_sensitive_sarh_columns_are_never_read(): void
    {
        $a = $this->map($this->row(), $this->sarh())->attributes;

        foreach (['x', 'https://kf.kobotoolbox.org/x'] as $value) {
            $this->assertNotContains($value, $a);
        }
    }

    public function test_without_sarh_the_workbook_birth_date_is_used_and_children_stay_unknown(): void
    {
        $a = $this->map($this->row())->attributes;

        $this->assertSame('1990-03-15', $a['birth_date']);
        // Omitted, not null: the counts are NOT NULL DEFAULT 0, so an unknown
        // count leaves a new row at 0 and an existing row unchanged.
        $this->assertArrayNotHasKey('sons_count', $a);
        $this->assertArrayNotHasKey('daughters_count', $a);
        $this->assertNull($a['national_id']);
    }

    public function test_a_birth_year_that_disagrees_with_the_date_is_reported(): void
    {
        $mapped = $this->map($this->row(['المواليد' => 1988]), $this->sarh());

        $this->assertSame('1990-03-15', $mapped->attributes['birth_date']);
        $this->assertNotEmpty($this->messages($mapped, 'warning'));
    }

    public function test_gender_and_marital_status_parse_including_feminine_forms(): void
    {
        $this->assertSame(Gender::Female->value, $this->map($this->row(['الجنس' => 'انثى']))->attributes['gender']);
        $this->assertSame(MaritalStatus::Widowed->value, $this->map($this->row(['الحالة الاجتماعية' => 'أرملة']))->attributes['marital_status']);
        $this->assertSame(MaritalStatus::Divorced->value, $this->map($this->row(['الحالة الاجتماعية' => 'مطلقة']))->attributes['marital_status']);
    }

    public function test_a_phone_number_in_the_marital_column_is_reported_not_stored(): void
    {
        $mapped = $this->map($this->row(['الحالة الاجتماعية' => 963992234521]));

        $this->assertNull($mapped->attributes['marital_status']);
        $this->assertNotEmpty($this->messages($mapped, 'warning'));
    }

    public function test_wives_on_someone_not_married_are_dropped_and_reported(): void
    {
        // The guard refuses the pair outright; dropping the count keeps the person.
        $mapped = $this->map($this->row(['الحالة الاجتماعية' => 'عازب', 'عدد الزوجات' => 1]));

        $this->assertArrayNotHasKey('wives_count', $mapped->attributes);
        $this->assertNotEmpty($this->messages($mapped, 'warning'));
        $this->assertSame(0, $this->map($this->row(['الحالة الاجتماعية' => 'عازب', 'عدد الزوجات' => 0]))->attributes['wives_count']);
    }

    public function test_the_governorate_is_read_from_the_start_of_an_address(): void
    {
        $a = $this->map($this->row(['المحافظة' => 'حلب - منطقة الباب - شارع الكورنيش']))->attributes;
        $this->assertSame(Governorate::Aleppo->value, $a['residence_governorate']);
        $this->assertSame('حلب - منطقة الباب - شارع الكورنيش', $a['address']);

        $this->assertSame(Governorate::Hama->value, $this->map($this->row(['المحافظة' => 'حماه']))->attributes['residence_governorate']);
        $this->assertSame(Governorate::Idlib->value, $this->map($this->row(['المحافظة' => 'ادلب']))->attributes['residence_governorate']);
    }

    public function test_an_address_with_no_governorate_is_kept_and_reported(): void
    {
        $mapped = $this->map($this->row(['المحافظة' => 'عندان']));

        $this->assertNull($mapped->attributes['residence_governorate']);
        $this->assertSame('عندان', $mapped->attributes['address']);
        $this->assertNotEmpty($this->messages($mapped, 'warning'));
    }

    public function test_the_phone_is_digits_and_a_username_is_reported(): void
    {
        $this->assertSame('963989997751', $this->map($this->row())->attributes['phone']);

        $mapped = $this->map($this->row(['معرف / رقم  التواصل' => 'alhmdllorbalalmeen']));
        $this->assertNull($mapped->attributes['phone']);
        $this->assertNotEmpty($this->messages($mapped, 'warning'));
    }

    // ── Classification ───────────────────────────────────────────────────

    public function test_an_engineer_gets_activity_grade_and_rank(): void
    {
        $a = $this->map($this->row())->attributes;

        $this->assertSame(ActivityType::Engineering->value, $a['activity_type']);
        $this->assertSame(4, $a['organizational_grade']);
        $this->assertSame(EngineeringRank::Associate2->value, $a['engineering_rank']);
        // The rank repeated in الخبرة is not an experience level.
        $this->assertNull($a['experience_level']);
    }

    public function test_a_rank_on_a_non_engineer_is_dropped_and_reported(): void
    {
        $mapped = $this->map($this->row(['نوع النشاط' => 'إداري', 'الخبرة' => 'خبرة 3']));

        $this->assertNull($mapped->attributes['engineering_rank']);
        $this->assertSame(3, $mapped->attributes['experience_level']);
        $this->assertNotEmpty($this->messages($mapped, 'warning'));
    }

    public function test_unparseable_experience_is_reported(): void
    {
        $mapped = $this->map($this->row(['نوع النشاط' => 'إداري', 'الرتبة الهندسية' => null, 'الخبرة' => '#N/A']));

        $this->assertNull($mapped->attributes['experience_level']);
        $this->assertNotEmpty($this->messages($mapped, 'warning'));
    }

    public function test_hazard_pay_is_a_flag(): void
    {
        $this->assertTrue($this->map($this->row(['الخطورة' => 'خطورة']))->attributes['has_hazard_pay']);
        $this->assertFalse($this->map($this->row())->attributes['has_hazard_pay']);
    }

    public function test_salary_modes(): void
    {
        $scale = $this->map($this->row())->attributes;
        $this->assertSame(SalaryMode::Scale->value, $scale['salary_mode']);
        $this->assertNull($scale['agreed_salary']);

        $agreed = $this->map($this->row(['حالة الراتب ( نظام / إتفاق )' => 'اتفاق', 'الراتب المتفق عليه' => 435]))->attributes;
        $this->assertSame(SalaryMode::Agreed->value, $agreed['salary_mode']);
        $this->assertSame('435.00', $agreed['agreed_salary']);
    }

    public function test_an_agreed_salary_without_an_amount_is_reported_and_the_mode_left_unset(): void
    {
        $mapped = $this->map($this->row(['حالة الراتب ( نظام / إتفاق )' => 'اتفاق', 'الراتب المتفق عليه' => null]));

        $this->assertArrayNotHasKey('salary_mode', $mapped->attributes);
        $this->assertNotEmpty($this->messages($mapped, 'warning'));
    }

    public function test_a_sham_cash_account_must_be_sixteen_digits(): void
    {
        $this->assertSame('9200274887373820', $this->map($this->row())->attributes['sham_cash_account']);

        $mapped = $this->map($this->row(['رقم الشام كاش' => '920027488737382']));
        $this->assertNull($mapped->attributes['sham_cash_account']);
        $this->assertNotEmpty($this->messages($mapped, 'warning'));
    }

    public function test_education_parses_and_a_date_in_it_is_reported(): void
    {
        $this->assertSame(EducationLevel::College->value, $this->map($this->row())->attributes['education_level']);

        $mapped = $this->map($this->row(['المرحلة الدراسية' => new DateTimeImmutable('2025-06-01')]));
        $this->assertNull($mapped->attributes['education_level']);
        $this->assertNotEmpty($this->messages($mapped, 'warning'));
    }

    // ── Employment ───────────────────────────────────────────────────────

    public function test_employment_type_status_and_hire_date(): void
    {
        $a = $this->map($this->row(['الحالة' => 'يحتاج تثبيت']))->attributes;

        $this->assertSame(EmploymentType::AwaitingTenure->value, $a['employment_type']);
        $this->assertSame(EmployeeStatus::Active->value, $a['status']);
        $this->assertSame('2022-01-01', $a['hire_date']);
    }

    public function test_the_workplace_falls_back_to_the_place_of_work_column(): void
    {
        $this->assertSame('حلب', $this->map($this->row())->attributes['workplace']);
        $this->assertSame('كرفانة ادلب', $this->map($this->row(['مكان العمل' => null, 'مكان الدوام' => 'كرفانة ادلب']))->attributes['workplace']);
        $this->assertNull($this->map($this->row(['مكان العمل' => null, 'مكان الدوام' => '-']))->attributes['workplace']);
    }

    public function test_the_unit_the_titles_and_the_opening_posting_are_handed_on(): void
    {
        $mapped = $this->map($this->row());

        $this->assertSame(['الإدارة الهندسية', 'قسم التخطيط والدراسات', 'المعماري'], $mapped->unit);
        $this->assertSame('مهندس معماري', $mapped->currentTitle);
        $this->assertSame(
            ['legacy_workplace' => 'الموقع العام', 'legacy_manager_name' => 'أبو يعرب', 'legacy_job_title' => 'مهندس متدرب'],
            $mapped->openingPosting,
        );
        $this->assertSame('نور أحمد الشامي', $mapped->displayName);
    }

    // ── What is read, and what Excel could not compute ───────────────────

    public function test_a_column_outside_the_allow_list_is_never_read(): void
    {
        // A pay value the importer has no business with, under a header it
        // would otherwise happily read if it were spelt the same.
        $mapped = $this->map($this->row(['قيمة أساس الراتب' => 999]));

        $this->assertNotContains(999, $mapped->attributes);
        $this->assertContains('السوية التنظيمية', EmployeeRowMapper::WORKBOOK_COLUMNS);
    }

    public function test_an_excel_error_is_reported_and_read_as_blank(): void
    {
        $mapped = $this->map($this->row(['نوع النشاط' => new CellError('#N/A')]));

        $this->assertNull($mapped->attributes['activity_type']);
        $this->assertStringContainsString('#N/A', $this->messages($mapped, 'warning')[0]);
    }

    public function test_a_formula_error_in_the_sarh_file_is_reported_too(): void
    {
        $mapped = $this->map($this->row(), $this->sarh(['اسم الام' => new CellError('')]));

        $this->assertNull($mapped->attributes['mother_name']);
        $this->assertStringContainsString('ملف صرح', $this->messages($mapped, 'warning')[0]);
    }

    public function test_an_unreadable_kobo_id_leaves_the_row_without_a_key(): void
    {
        $mapped = $this->map($this->row(['ID_Sarah' => new CellError('#REF!')]));

        $this->assertNull($mapped->sourceKey);
        $this->assertTrue($mapped->hasErrors());
    }

    public function test_sarh_records_are_keyed_by_the_digits_of_their_id_and_cut_to_the_allow_list(): void
    {
        $byId = EmployeeRowMapper::sarhById([
            2 => $this->sarh(['_id' => 582974643.0]),
            3 => $this->sarh(['_id' => null]),
        ]);

        $this->assertSame(['582974643'], array_map('strval', array_keys($byId)));
        $this->assertArrayNotHasKey('الديانة', $byId['582974643']);
        $this->assertSame('نور', $byId['582974643']['الاسم الأول']);
    }
}
