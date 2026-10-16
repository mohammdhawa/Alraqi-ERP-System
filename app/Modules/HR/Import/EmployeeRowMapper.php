<?php

declare(strict_types=1);

namespace App\Modules\HR\Import;

use App\Modules\HR\Enums\ActivityType;
use App\Modules\HR\Enums\EducationLevel;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\EmploymentType;
use App\Modules\HR\Enums\EngineeringRank;
use App\Modules\HR\Enums\Gender;
use App\Modules\HR\Enums\Governorate;
use App\Modules\HR\Enums\MaritalStatus;
use App\Modules\HR\Enums\SalaryMode;
use DateTimeInterface;

/**
 * Maps one row of `Program_HR_10_2026.xlsx` → `قاعدة البيانات`, with its record
 * from the صرح export, to `employees` attributes and report issues.
 *
 * The column-by-column rules are docs/WORKBOOK_COLUMN_MAP.md; the decisions
 * behind them are work order §8 (2026-10-14). In short:
 *
 * - The key is `kobo:<ID_Sarah>` — the صرح record's `_id`, which never changes.
 * - From the صرح record, ONLY: the three name parts, mother's name, birth date,
 *   national ID and sons/daughters. Never religion, sect, criminal record or
 *   the ID-card scans — those columns are not read at all (SARH_COLUMNS).
 * - The importer REPORTS rather than absorbs: a value that cannot be read is
 *   left null and an issue says what was there. Nothing is guessed, except the
 *   one correction decided by the project owner — a 10-digit national ID gets
 *   back the leading 0 Excel dropped.
 * - The classification guard's cross-field rules are applied here first, so a
 *   rank on a non-engineer drops the rank with a warning instead of refusing the
 *   whole row.
 */
final class EmployeeRowMapper
{
    /** The only صرح columns read. Anything else in that file is never touched. */
    public const SARH_COLUMNS = [
        '_id',
        'الاسم الأول',
        'اسم الاب',
        'الكنية',
        'اسم الام',
        'تاريخ التولد',
        'الرقم الوطني في الهوية',
        'عدد الأبناء الذكور',
        'عدد الأبناء الاناث',
    ];

    /**
     * The only workbook columns read — every header map() looks up, and
     * nothing else: not the pay values (قيمة …), not the warnings, not the
     * legacy number. The reader refuses a sheet missing any of them, so a
     * column HR renames fails the run instead of importing as blank.
     */
    public const WORKBOOK_COLUMNS = [
        'ID_Sarah',
        'الإسم الثلاثي',
        'اللقب',
        'الجنس',
        'المواليد',
        'مواليد_دقيقة',
        'الحالة الاجتماعية',
        'عدد الزوجات',
        'معرف / رقم  التواصل',   // two spaces, as in the workbook
        'المحافظة',
        'المرحلة الدراسية',
        'المؤهل العلمي',
        'الاختصاص الدقيق',
        'تاريخ التخرج الدقيق',
        'نوع النشاط',
        'السوية التنظيمية',
        'الخبرة',
        'الرتبة الهندسية',
        'سنوات الخبرة الصناعية',
        'الخطورة',
        'الندرة',
        'إزدواجية العمل',
        'حالة الراتب ( نظام / إتفاق )',
        'الراتب المتفق عليه',
        'رقم الشام كاش',
        'مكان الراتب',
        'الحالة',
        'تاريخ التعيين',
        'مكان العمل',
        'مكان الدوام',
        'أسماء الإدارات',
        'القسم',
        'المكتب',
        'المسمى الوظيفي',
        'المسمى الوظيفي بداية التعيين',
        'مكان العمل بداية التعيين',
        'المدير المباشر بداية التعيين',
    ];

    /** @var array<int, array{severity: string, field: ?string, message: string}> */
    private array $issues = [];

    /**
     * @param  array<string, mixed>  $row  header => value, from the workbook
     * @param  array<string, mixed>|null  $sarh  header => value, from the صرح export
     */
    public function map(array $row, ?array $sarh): MappedRow
    {
        $this->issues = [];
        $row = $this->readable(array_intersect_key($row, array_flip(self::WORKBOOK_COLUMNS)), 'ملف الموارد');
        $sarh = $sarh === null ? null : $this->readable(array_intersect_key($sarh, array_flip(self::SARH_COLUMNS)), 'ملف صرح');
        $at = static fn (array $from, string $header): mixed => $from[$header] ?? null;

        $displayName = ArabicText::clean($at($row, 'الإسم الثلاثي')) ?? '';

        $attributes = [
            ...$this->names($displayName, $sarh),
            'nickname'         => ArabicText::clean($at($row, 'اللقب')),
            'mother_name'      => $sarh === null ? null : ArabicText::clean($at($sarh, 'اسم الام')),
            'gender'           => $this->label(Gender::class, $at($row, 'الجنس'), 'gender', 'الجنس'),
            'birth_date'       => $this->birthDate($row, $sarh),
            'national_id'      => $this->nationalId($sarh),
            'marital_status'   => $marital = $this->maritalStatus($at($row, 'الحالة الاجتماعية')),
            'wives_count'      => $this->wives($at($row, 'عدد الزوجات'), $marital),
            'sons_count'       => $sarh === null ? null : $this->integer($at($sarh, 'عدد الأبناء الذكور')),
            'daughters_count'  => $sarh === null ? null : $this->integer($at($sarh, 'عدد الأبناء الاناث')),
            'phone'            => $this->phone($at($row, 'معرف / رقم  التواصل')),
            ...$this->residence($at($row, 'المحافظة')),
            'education_level'  => $this->education($at($row, 'المرحلة الدراسية')),
            'qualification'    => ArabicText::clean($at($row, 'المؤهل العلمي')),
            'specialization'   => ArabicText::clean($at($row, 'الاختصاص الدقيق')),
            'graduation_date'  => $this->date($at($row, 'تاريخ التخرج الدقيق')),
            ...$this->classification($row),
            'has_hazard_pay'   => ArabicText::key((string) $at($row, 'الخطورة')) === ArabicText::key('خطورة'),
            'has_scarcity_pay' => ArabicText::clean($at($row, 'الندرة')) !== null,
            'has_dual_role_pay' => ArabicText::clean($at($row, 'إزدواجية العمل')) !== null,
            ...$this->pay($row),
            'sham_cash_account' => $this->shamCash($at($row, 'رقم الشام كاش')),
            'payroll_location' => ArabicText::clean($at($row, 'مكان الراتب')),
            'employment_type'  => $this->label(EmploymentType::class, $at($row, 'الحالة'), 'employment_type', 'الحالة'),
            'status'           => EmployeeStatus::Active->value,
            'hire_date'        => $this->date($at($row, 'تاريخ التعيين')),
            'workplace'        => $this->blankDash($at($row, 'مكان العمل')) ?? $this->blankDash($at($row, 'مكان الدوام')),
        ];

        // NOT NULL DEFAULT 0 in the schema: an unknown count is OMITTED, so a new
        // row gets 0 and a re-run leaves an existing value alone. Sending null
        // would fail the insert.
        foreach (['wives_count', 'sons_count', 'daughters_count'] as $count) {
            if ($attributes[$count] === null) {
                unset($attributes[$count]);
            }
        }

        return new MappedRow(
            sourceKey: $this->key($at($row, 'ID_Sarah')),
            displayName: $displayName,
            attributes: $attributes,
            unit: [
                ArabicText::clean($at($row, 'أسماء الإدارات')),
                ArabicText::clean($at($row, 'القسم')),
                ArabicText::clean($at($row, 'المكتب')),
            ],
            currentTitle: ArabicText::clean($at($row, 'المسمى الوظيفي')),
            // The starting title is kept as text, not as a second posting: the
            // workbook never says when it changed (option B, 2026-10-15).
            openingPosting: [
                'legacy_workplace'    => ArabicText::clean($at($row, 'مكان العمل بداية التعيين')),
                'legacy_manager_name' => ArabicText::clean($at($row, 'المدير المباشر بداية التعيين')),
                'legacy_job_title'    => ArabicText::clean($at($row, 'المسمى الوظيفي بداية التعيين')),
            ],
            issues: $this->issues,
        );
    }

    /**
     * The صرح records keyed by the digits of their `_id` — the value a
     * workbook row's ID_Sarah is joined on — each cut to SARH_COLUMNS.
     *
     * @param  iterable<int, array<string, mixed>>  $records
     * @return array<string, array<string, mixed>>
     */
    public static function sarhById(iterable $records): array
    {
        $byId = [];
        foreach ($records as $record) {
            $id = self::koboId($record['_id'] ?? null);
            if ($id !== null) {
                $byId[$id] = array_intersect_key($record, array_flip(self::SARH_COLUMNS));
            }
        }

        return $byId;
    }

    /** The digits of an ID_Sarah or `_id` cell, or null when it has none. */
    public static function koboId(mixed $value): ?string
    {
        return self::digits($value);
    }

    /**
     * A cell holding an Excel error becomes blank here — after the report
     * says which, so it is never mistaken for a value HR left empty.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function readable(array $values, string $file): array
    {
        foreach ($values as $header => $value) {
            if ($value instanceof CellError) {
                $what = $value->code === '' ? 'معادلة نتيجتها خطأ' : "خطأ Excel ({$value->code})";
                $this->issue('warning', null, "خانة «{$header}» في {$file} فيها {$what} — قُرئت فارغة.");
                $values[$header] = null;
            }
        }

        return $values;
    }

    private function key(mixed $kobo): ?string
    {
        $digits = self::koboId($kobo);

        if ($digits === null) {
            $this->issue('error', null, 'لا يوجد رقم كوبو (ID_Sarah) — لا يُستورد الموظف حتى يُضاف.');

            return null;
        }

        return 'kobo:'.$digits;
    }

    /**
     * @param  array<string, mixed>|null  $sarh
     * @return array{first_name: ?string, middle_name: ?string, last_name: ?string}
     */
    private function names(string $fullName, ?array $sarh): array
    {
        if ($sarh !== null && ArabicText::clean($sarh['الاسم الأول'] ?? null) !== null) {
            return [
                'first_name'  => ArabicText::clean($sarh['الاسم الأول']),
                'middle_name' => ArabicText::clean($sarh['اسم الاب'] ?? null),
                'last_name'   => ArabicText::clean($sarh['الكنية'] ?? null),
            ];
        }

        $words = $fullName === '' ? [] : explode(' ', $fullName);

        if (count($words) !== 3) {
            $this->issue('warning', 'last_name', 'الاسم «'.$fullName.'» من '.count($words).' كلمات — قُسّم: الأولى اسماً، والثانية اسم أب، والباقي كنية. يرجى التحقق.');
        }

        return [
            'first_name'  => $words[0] ?? null,
            'middle_name' => count($words) > 2 ? $words[1] : null,
            'last_name'   => count($words) > 2 ? implode(' ', array_slice($words, 2)) : ($words[1] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $sarh
     */
    private function birthDate(array $row, ?array $sarh): ?string
    {
        $date = $this->date($sarh['تاريخ التولد'] ?? null) ?? $this->date($row['مواليد_دقيقة'] ?? null);
        $year = $this->integer($row['المواليد'] ?? null);

        if ($date !== null && $year !== null && (int) substr($date, 0, 4) !== $year) {
            $this->issue('warning', 'birth_date', "تاريخ الميلاد {$date} لا يطابق سنة المواليد {$year} في ملف الموارد.");
        }

        return $date;
    }

    /**
     * @param  array<string, mixed>|null  $sarh
     */
    private function nationalId(?array $sarh): ?string
    {
        $digits = $sarh === null ? null : self::digits($sarh['الرقم الوطني في الهوية'] ?? null);

        if ($digits === null) {
            return null;
        }
        if (strlen($digits) === 10) {
            return '0'.$digits;   // Excel dropped the leading 0 (project owner, 2026-10-14)
        }
        if (strlen($digits) !== 11) {
            $this->issue('warning', 'national_id', "الرقم الوطني «{$digits}» من ".strlen($digits).' أرقام وليس 11 — لم يُسجَّل.');

            return null;
        }

        return $digits;
    }

    private function maritalStatus(mixed $value): ?int
    {
        $text = ArabicText::clean($value);
        if ($text === null) {
            return null;
        }

        // The column carries feminine forms (أرملة، مطلقة); the labels are masculine.
        $key = (string) preg_replace('/ه$/u', '', ArabicText::key($text));
        foreach (MaritalStatus::cases() as $case) {
            if (ArabicText::key($case->label()) === $key) {
                return $case->value;
            }
        }

        $this->issue('warning', 'marital_status', "الحالة الاجتماعية «{$text}» غير معروفة — لم تُسجَّل.");

        return null;
    }

    /**
     * The guard refuses a wives count above 0 on anyone not married; dropping
     * the count keeps the person instead of refusing the whole row.
     */
    private function wives(mixed $value, ?int $marital): ?int
    {
        $wives = $this->integer($value);

        if ($wives !== null && $wives > 0 && $marital !== MaritalStatus::Married->value) {
            $this->issue('warning', 'wives_count', "عدد الزوجات {$wives} لموظف غير متزوج — لم يُسجَّل.");

            return null;
        }

        return $wives;
    }

    private function phone(mixed $value): ?string
    {
        $text = ArabicText::clean($value);
        if ($text === null) {
            return null;
        }
        if (preg_match('/^\+?\d{6,15}$/', str_replace(' ', '', $text)) === 1) {
            return str_replace(' ', '', $text);
        }

        $this->issue('warning', 'phone', "«{$text}» في عمود التواصل ليس رقم هاتف — لم يُسجَّل.");

        return null;
    }

    /**
     * @return array{residence_governorate: ?int, address: ?string}
     */
    private function residence(mixed $value): array
    {
        $text = ArabicText::clean($value);
        if ($text === null) {
            return ['residence_governorate' => null, 'address' => null];
        }

        // Longest label first: «ريف دمشق» must win over «دمشق».
        $cases = Governorate::cases();
        usort($cases, static fn (Governorate $a, Governorate $b): int => mb_strlen($b->label()) <=> mb_strlen($a->label()));

        $key = ArabicText::key($text);
        foreach ($cases as $case) {
            if (str_starts_with($key, ArabicText::key($case->label()))) {
                return ['residence_governorate' => $case->value, 'address' => $text];
            }
        }

        $this->issue('warning', 'residence_governorate', "لم تُعرف المحافظة من «{$text}» — سُجّل العنوان كما هو.");

        return ['residence_governorate' => null, 'address' => $text];
    }

    private function education(mixed $value): ?int
    {
        if ($value instanceof DateTimeInterface) {
            $this->issue('warning', 'education_level', 'المرحلة الدراسية مسجّلة تاريخاً ('.$value->format('Y-m-d').') — لم تُسجَّل.');

            return null;
        }

        return $this->label(EducationLevel::class, $value, 'education_level', 'المرحلة الدراسية');
    }

    /**
     * Activity, grade, experience, rank, industrial years — with the guard's
     * cross-field rules applied first.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, ?int>
     */
    private function classification(array $row): array
    {
        $activity = $this->label(ActivityType::class, $row['نوع النشاط'] ?? null, 'activity_type', 'نوع النشاط');
        $isEngineer = $activity === ActivityType::Engineering->value;

        $grade = $this->integer($row['السوية التنظيمية'] ?? null);
        if ($grade !== null && ($grade < 1 || $grade > 5)) {
            $this->issue('warning', 'organizational_grade', "السوية التنظيمية {$grade} خارج المجال 1–5 — لم تُسجَّل.");
            $grade = null;
        }

        $rankText = ArabicText::clean($row['الرتبة الهندسية'] ?? null);
        $rank = $rankText === null ? null : $this->label(EngineeringRank::class, $rankText, 'engineering_rank', 'الرتبة الهندسية');
        if ($rank !== null && ! $isEngineer) {
            $this->issue('warning', 'engineering_rank', "رتبة هندسية «{$rankText}» لموظف نشاطه غير هندسي — لم تُسجَّل.");
            $rank = null;
        }

        $industrial = $this->integer($row['سنوات الخبرة الصناعية'] ?? null);
        if ($industrial !== null && $activity !== ActivityType::Industrial->value) {
            $this->issue('warning', 'industrial_experience_years', 'سنوات خبرة صناعية لموظف نشاطه غير صناعي — لم تُسجَّل.');
            $industrial = null;
        }

        return [
            'activity_type'               => $activity,
            'organizational_grade'        => $grade,
            'experience_level'            => $this->experience($row['الخبرة'] ?? null),
            'engineering_rank'            => $rank,
            'industrial_experience_years' => $industrial,
        ];
    }

    /**
     * الخبرة holds three kinds of value: «خبرة N», a bare N, and — for
     * engineers — the engineering rank again, which is not an experience level.
     */
    private function experience(mixed $value): ?int
    {
        $text = ArabicText::clean($value);
        if ($text === null || ArabicText::key($text) === ArabicText::key('لا يوجد')) {
            return null;
        }

        if (preg_match('/^(?:خبرة\s*)?(\d)$/u', $text, $m) === 1) {
            $level = (int) $m[1];
            if ($level >= 1 && $level <= 5) {
                return $level;
            }
            if ($level === 0) {
                return null;
            }
        }

        foreach (EngineeringRank::cases() as $rank) {
            if (ArabicText::key($rank->label()) === ArabicText::key($text)) {
                return null;   // the rank repeated; الرتبة الهندسية carries it
            }
        }

        $this->issue('warning', 'experience_level', "الخبرة «{$text}» غير مفهومة — لم تُسجَّل.");

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function pay(array $row): array
    {
        $modeText = ArabicText::key((string) ($row['حالة الراتب ( نظام / إتفاق )'] ?? ''));
        $amount = $row['الراتب المتفق عليه'] ?? null;
        $amount = is_numeric($amount) ? number_format((float) $amount, 2, '.', '') : null;

        if ($modeText === ArabicText::key('نظام رواتب')) {
            return ['salary_mode' => SalaryMode::Scale->value, 'agreed_salary' => null];
        }
        if ($modeText === ArabicText::key('اتفاق')) {
            if ($amount === null) {
                $this->issue('warning', 'agreed_salary', 'نظام الراتب «اتفاق» بلا راتب متفق عليه — لم يُسجَّل نظام الراتب.');

                return ['agreed_salary' => null];
            }

            return ['salary_mode' => SalaryMode::Agreed->value, 'agreed_salary' => $amount];
        }

        if ($modeText !== '') {
            $this->issue('warning', 'salary_mode', 'نظام الراتب «'.$row['حالة الراتب ( نظام / إتفاق )'].'» غير معروف — لم يُسجَّل.');
        }

        return ['agreed_salary' => null];
    }

    private function shamCash(mixed $value): ?string
    {
        $digits = self::digits($value);
        if ($digits === null) {
            return null;
        }
        if (strlen($digits) !== 16) {
            $this->issue('warning', 'sham_cash_account', "رقم الشام كاش «{$digits}» من ".strlen($digits).' رقماً وليس 16 — لم يُسجَّل.');

            return null;
        }

        return $digits;
    }

    /**
     * An enum case by its Arabic label, or null with a warning.
     *
     * @param  class-string  $enum
     */
    private function label(string $enum, mixed $value, string $field, string $column): ?int
    {
        $text = ArabicText::clean($value);
        if ($text === null) {
            return null;
        }

        foreach ($enum::cases() as $case) {
            if (ArabicText::key($case->label()) === ArabicText::key($text)) {
                return $case->value;
            }
        }

        $this->issue('warning', $field, "«{$text}» في عمود {$column} غير معروف — لم يُسجَّل.");

        return null;
    }

    private function date(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : null;
    }

    private function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function blankDash(mixed $value): ?string
    {
        $text = ArabicText::clean($value);

        return $text === '-' ? null : $text;
    }

    /**
     * The digits of an id-like cell. Excel hands these over as ints, floats
     * (582974643.0) or strings; a float must not become "582974643.0".
     */
    private static function digits(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;   // blank, or a CellError
        }
        if (is_float($value)) {
            $value = number_format($value, 0, '', '');
        }

        $digits = preg_replace('/\D/', '', (string) $value);

        return $digits === '' || $digits === null ? null : $digits;
    }

    private function issue(string $severity, ?string $field, string $message): void
    {
        $this->issues[] = ['severity' => $severity, 'field' => $field, 'message' => $message];
    }
}
