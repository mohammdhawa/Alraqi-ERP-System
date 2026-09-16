<?php

declare(strict_types=1);

namespace App\Modules\HR\Support;

use App\Modules\HR\Enums\ActivityType;
use App\Modules\HR\Enums\EducationLevel;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\EmploymentType;
use App\Modules\HR\Enums\EngineeringRank;
use App\Modules\HR\Enums\Gender;
use App\Modules\HR\Enums\Governorate;
use App\Modules\HR\Enums\MaritalStatus;
use App\Modules\HR\Enums\SalaryMode;

/**
 * EmployeeClassificationGuard
 *
 * The SINGLE owner of the employee invariants the schema cannot express. Every
 * rule below holds for EVERY writer — the API, the seeder, tinker, the Batch 4
 * importer, and any future module — because both entry points call this one
 * class:
 *
 *   - EmployeeRequest translates each violation into a 422 field error,
 *     preserving the Arabic field-level UX.
 *   - Employee::saving throws EmployeeClassificationException on the first one.
 *
 * This is the DepartmentHierarchyGuard shape, copied deliberately. The standard
 * names that class the reference implementation for C4/C5, and the project has
 * already paid once for the alternative: hierarchy rules that lived only in a
 * FormRequest let the seeder create four roots.
 *
 * THE RULES
 *
 *   axis exclusivity   الرتبة الهندسية belongs to engineering work and
 *                      سنوات الخبرة الصناعية to industrial work. Storing either
 *                      on the wrong kind of post is meaningless data that a pay
 *                      calculator would nonetheless read.
 *
 *   pay-track pairing  إتفاق requires an agreed amount; نظام رواتب forbids one.
 *                      A scale employee carrying a stored salary is the exact
 *                      cached-derived-value the dropped `salary` column was.
 *
 *   grade ranges       السوية التنظيمية and الخبرة are 1–5 scales.
 *
 *   account shape      رقم الشام كاش is exactly 16 digits.
 *
 *   family coherence   عدد الزوجات is only meaningful for a married employee.
 *
 *   date order         a person cannot leave before they arrived.
 *
 *   defined values     every enum-backed column must map to a defined case.
 *
 * ⚠️ WHAT THIS GUARD DELIBERATELY DOES NOT ENFORCE, and why it matters:
 * phase-1.5-foundation-truth-up.md §5.3 says the three experience axes are
 * "mutually exclusive by activity_type", which reads as forbidding
 * `experience_level` on engineering and industrial posts. The later HR-folder
 * scan contradicts exactly that: its pay matrix carries two experience rate
 * tables, one keyed to إداري/مالي/أمني and one to هندسي/صناعي, so an
 * engineering row legitimately carries an experience level AND a rank. See the
 * ActivityType docblock. Enforcing the stricter reading would reject genuine
 * rows at import time, so the guard enforces only the half both sources agree
 * on.
 *
 * Operates on a plain attribute array — no HTTP, no Eloquent, no container — so
 * it is trivially unit-testable and callable from anywhere.
 */
final class EmployeeClassificationGuard
{
    /**
     * The السوية التنظيمية and الخبرة scales both run 1–5.
     */
    private const GRADE_MIN = 1;

    private const GRADE_MAX = 5;

    /**
     * Every invariant broken by an employee row carrying $attributes.
     *
     * $attributes holds the row's RAW, pending values — what it would actually
     * store — keyed by column name. A key that is absent is simply not checked,
     * which is what makes the same method usable for a full create and a
     * partial update: the caller is responsible for merging the submitted
     * values over the stored ones first (EmployeeRequest::effectiveAttributes()
     * and the model's getAttributes() both do).
     *
     * @param  array<string, mixed>  $attributes
     * @return list<ClassificationViolation>
     */
    public function violations(array $attributes): array
    {
        $violations = [];

        foreach ($this->definedValueViolations($attributes) as $violation) {
            $violations[] = $violation;
        }

        // Everything below reads an enum case, so a value the enum does not
        // know would crash it. Report the undefined value and stop.
        if ($violations !== []) {
            return $violations;
        }

        foreach ([
            $this->activityAxisViolations($attributes),
            $this->gradeRangeViolations($attributes),
            $this->payTrackViolations($attributes),
            $this->shamCashViolations($attributes),
            $this->familyViolations($attributes),
            $this->dateOrderViolations($attributes),
        ] as $group) {
            foreach ($group as $violation) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * Every enum-backed column must map to a defined case.
     *
     * The Eloquent cast raises its own ValueError before a save when a writer
     * assigns an undefined value through the property, which is a loud and
     * correct refusal — but it is not OUR refusal, it carries no Arabic message
     * and no field name, and it never fires for setRawAttributes, a raw insert,
     * or a row already stored when a case is removed from an enum. This branch
     * covers those.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<ClassificationViolation>
     */
    private function definedValueViolations(array $attributes): array
    {
        $enums = [
            'gender'                => [Gender::class, 'الجنس المحدد غير معرّف.'],
            'marital_status'        => [MaritalStatus::class, 'الحالة الاجتماعية المحددة غير معرّفة.'],
            'education_level'       => [EducationLevel::class, 'المرحلة الدراسية المحددة غير معرّفة.'],
            'activity_type'         => [ActivityType::class, 'نوع النشاط المحدد غير معرّف.'],
            'engineering_rank'      => [EngineeringRank::class, 'الرتبة الهندسية المحددة غير معرّفة.'],
            'status'                => [EmployeeStatus::class, 'حالة الموظف المحددة غير معرّفة.'],
            'employment_type'       => [EmploymentType::class, 'نوع التوظيف المحدد غير معرّف.'],
            'salary_mode'           => [SalaryMode::class, 'نظام الراتب المحدد غير معرّف.'],
            'origin_governorate'    => [Governorate::class, 'المحافظة المحددة غير معرّفة.'],
            'residence_governorate' => [Governorate::class, 'محافظة الإقامة المحددة غير معرّفة.'],
        ];

        $violations = [];

        foreach ($enums as $field => [$enum, $message]) {
            $value = $this->intOrNull($attributes, $field);

            if ($value !== null && ! $enum::isDefined($value)) {
                $violations[] = new ClassificationViolation($field, $message);
            }
        }

        return $violations;
    }

    /**
     * الرتبة الهندسية belongs to engineering work; سنوات الخبرة الصناعية to
     * industrial work.
     *
     * Both are read by the pay calculation, so a rank left on a row whose
     * activity type was later changed to إداري is not merely untidy — it is a
     * criterion that would still be priced. Refusing the write is the only
     * point at which that is cheap to stop.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<ClassificationViolation>
     */
    private function activityAxisViolations(array $attributes): array
    {
        $activityValue = $this->intOrNull($attributes, 'activity_type');
        $activity      = $activityValue === null ? null : ActivityType::from($activityValue);

        $rank      = $this->intOrNull($attributes, 'engineering_rank');
        $industrial = $this->intOrNull($attributes, 'industrial_experience_years');

        $violations = [];

        if ($rank !== null && ($activity === null || ! $activity->usesEngineeringRank())) {
            $violations[] = new ClassificationViolation(
                'engineering_rank',
                'الرتبة الهندسية تُحدَّد للموظفين ذوي النشاط الهندسي فقط.',
            );
        }

        if ($industrial !== null && ($activity === null || ! $activity->usesIndustrialExperience())) {
            $violations[] = new ClassificationViolation(
                'industrial_experience_years',
                'سنوات الخبرة الصناعية تُحدَّد للموظفين ذوي النشاط الصناعي فقط.',
            );
        }

        return $violations;
    }

    /**
     * السوية التنظيمية and الخبرة are 1–5 scales.
     *
     * The column is an unsignedTinyInteger, so the database would accept 200
     * quite happily and the pay calculation would then look for a rate that
     * does not exist.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<ClassificationViolation>
     */
    private function gradeRangeViolations(array $attributes): array
    {
        $scales = [
            'organizational_grade' => 'السوية التنظيمية يجب أن تكون بين 1 و 5.',
            'experience_level'     => 'مستوى الخبرة يجب أن يكون بين 1 و 5.',
        ];

        $violations = [];

        foreach ($scales as $field => $message) {
            $value = $this->intOrNull($attributes, $field);

            if ($value !== null && ($value < self::GRADE_MIN || $value > self::GRADE_MAX)) {
                $violations[] = new ClassificationViolation($field, $message);
            }
        }

        return $violations;
    }

    /**
     * إتفاق requires a stored amount; نظام رواتب forbids one.
     *
     * The forbidding half is the one that matters and the one that looks
     * pedantic. A scale employee carrying a stray `agreed_salary` is a cached
     * derived value with no invalidation strategy — precisely the thing the
     * `salary` column was dropped for — and the first calculator to read
     * "agreed_salary ?? calculate()" would pay it.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<ClassificationViolation>
     */
    private function payTrackViolations(array $attributes): array
    {
        $modeValue = $this->intOrNull($attributes, 'salary_mode');

        if ($modeValue === null) {
            return [];
        }

        $mode   = SalaryMode::from($modeValue);
        $agreed = $attributes['agreed_salary'] ?? null;

        if ($mode->requiresAgreedSalary() && ($agreed === null || $agreed === '')) {
            return [new ClassificationViolation(
                'agreed_salary',
                'الراتب المتفق عليه مطلوب عندما يكون نظام الراتب «إتفاق».',
            )];
        }

        if (! $mode->requiresAgreedSalary() && $agreed !== null && $agreed !== '') {
            return [new ClassificationViolation(
                'agreed_salary',
                'لا يجوز تحديد راتب متفق عليه ضمن «نظام الرواتب»؛ الراتب يُحتسب من المعايير.',
            )];
        }

        if ($agreed !== null && $agreed !== '' && (float) $agreed < 0) {
            return [new ClassificationViolation(
                'agreed_salary',
                'الراتب المتفق عليه لا يمكن أن يكون سالباً.',
            )];
        }

        return [];
    }

    /**
     * رقم الشام كاش is exactly 16 digits.
     *
     * The intake form constrains it, and §7.4 names "values that are not 16
     * digits" as a known data problem in the source the importer must report.
     * Checking it here is what turns that from a silent bad payment instrument
     * into a reportable row.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<ClassificationViolation>
     */
    private function shamCashViolations(array $attributes): array
    {
        $account = $attributes['sham_cash_account'] ?? null;

        if ($account === null || $account === '') {
            return [];
        }

        if (preg_match('/^\d{16}$/', (string) $account) !== 1) {
            return [new ClassificationViolation(
                'sham_cash_account',
                'رقم الشام كاش يجب أن يتكون من 16 رقماً.',
            )];
        }

        return [];
    }

    /**
     * عدد الزوجات is only meaningful for a married employee.
     *
     * Checked rather than assumed because the count feeds family allowances: a
     * stale 1 left behind by a change from متزوج to مطلق is money.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<ClassificationViolation>
     */
    private function familyViolations(array $attributes): array
    {
        $wives = $this->intOrNull($attributes, 'wives_count');

        if ($wives === null || $wives === 0) {
            return [];
        }

        $maritalValue = $this->intOrNull($attributes, 'marital_status');

        if ($maritalValue === null || MaritalStatus::from($maritalValue) !== MaritalStatus::Married) {
            return [new ClassificationViolation(
                'wives_count',
                'عدد الزوجات يُحدَّد للموظف المتزوج فقط.',
            )];
        }

        return [];
    }

    /**
     * A person cannot leave before they arrived.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<ClassificationViolation>
     */
    private function dateOrderViolations(array $attributes): array
    {
        $hire        = $this->dateOrNull($attributes, 'hire_date');
        $termination = $this->dateOrNull($attributes, 'termination_date');

        if ($hire !== null && $termination !== null && $termination < $hire) {
            return [new ClassificationViolation(
                'termination_date',
                'تاريخ انتهاء العمل لا يمكن أن يسبق تاريخ التعيين.',
            )];
        }

        return [];
    }

    /**
     * Read an attribute as an int, treating absent, null and empty string alike
     * as "not set".
     *
     * Enum-cast attributes arrive here as enum instances when the caller passed
     * a model's attribute bag after casting, and as ints or numeric strings from
     * a request. All three are normalised.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function intOrNull(array $attributes, string $key): ?int
    {
        $value = $attributes[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \BackedEnum) {
            return (int) $value->value;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Read an attribute as a Y-m-d string for comparison, or null.
     *
     * String comparison is correct for ISO dates and avoids dragging a date
     * library into a class that is deliberately framework-free.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function dateOrNull(array $attributes, string $key): ?string
    {
        $value = $attributes[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return substr((string) $value, 0, 10);
    }
}
