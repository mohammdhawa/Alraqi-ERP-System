<?php

declare(strict_types=1);

namespace App\Modules\HR\Import;

use App\Modules\Departments\Models\Department;
use App\Modules\HR\Models\JobTitle;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The words the import's "changes" list speaks (EMPLOYEE_IMPORT_UPLOAD_DESIGN.md
 * §5.1): an Arabic label for every field the importer writes, and each value
 * as a person reads it — an enum's label, the unit's path, the title's name.
 *
 * WHY IT EXISTS: the workbook is the source (U2), so an upload overwrites an
 * edit made in the ERP. The dry run shows each such overwrite, old → new,
 * before it happens; a list of column names and enum numbers would show it to
 * nobody. The labels follow the dashboard's employee form, so HR sees the same
 * word in both places.
 */
final class EmployeeImportFields
{
    /** @var array<string, string> field => Arabic label */
    public const LABELS = [
        'first_name'                  => 'الاسم الأول',
        'middle_name'                 => 'اسم الأب',
        'last_name'                   => 'الكنية',
        'nickname'                    => 'اللقب',
        'mother_name'                 => 'اسم الأم',
        'gender'                      => 'الجنس',
        'birth_date'                  => 'تاريخ الميلاد',
        'national_id'                 => 'رقم الهوية',
        'marital_status'              => 'الحالة الاجتماعية',
        'wives_count'                 => 'عدد الزوجات',
        'sons_count'                  => 'عدد الأبناء',
        'daughters_count'             => 'عدد البنات',
        'phone'                       => 'الهاتف',
        'residence_governorate'       => 'محافظة الإقامة',
        'address'                     => 'العنوان',
        'education_level'             => 'المستوى التعليمي',
        'qualification'               => 'المؤهل العلمي',
        'specialization'              => 'الاختصاص',
        'graduation_date'             => 'تاريخ التخرج',
        'activity_type'               => 'نوع النشاط',
        'organizational_grade'        => 'الدرجة التنظيمية',
        'experience_level'            => 'مستوى الخبرة',
        'engineering_rank'            => 'الرتبة الهندسية',
        'industrial_experience_years' => 'سنوات الخبرة الصناعية',
        'has_hazard_pay'              => 'تعويض خطورة',
        'has_scarcity_pay'            => 'تعويض ندرة',
        'has_dual_role_pay'           => 'تعويض ازدواج وظيفي',
        'salary_mode'                 => 'نظام الراتب',
        'agreed_salary'               => 'الراتب المتفق عليه',
        'sham_cash_account'           => 'حساب الشام كاش',
        'payroll_location'            => 'مكان الراتب',
        'employment_type'             => 'نوع التوظيف',
        'status'                      => 'الحالة',
        'hire_date'                   => 'تاريخ التعيين',
        'workplace'                   => 'مكان العمل',
        'department_id'               => 'الوحدة',
        'job_title_id'                => 'المسمى الوظيفي',
        'legacy_workplace'            => 'مكان العمل بداية التعيين',
        'legacy_manager_name'         => 'المدير المباشر بداية التعيين',
        'legacy_job_title'            => 'المسمى الوظيفي بداية التعيين',
    ];

    /**
     * What writing $attributes onto $model would change, field by field.
     * Judged on a clone, so $model itself is left as it was.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<int, array{field: string, label: string, old: ?string, new: ?string}>
     */
    public static function changes(Model $model, array $attributes): array
    {
        $after = (clone $model)->fill($attributes);

        $changes = [];
        foreach (array_keys($after->getDirty()) as $field) {
            $changes[] = [
                'field' => $field,
                'label' => self::LABELS[$field] ?? $field,
                'old'   => self::display($model, $field, $model->getAttribute($field)),
                'new'   => self::display($after, $field, $after->getAttribute($field)),
            ];
        }

        return $changes;
    }

    public static function display(Model $model, string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match (true) {
            $field === 'department_id' => self::unitPath((int) $value),
            $field === 'job_title_id'  => JobTitle::withTrashed()->find($value)?->name,
            $value instanceof BackedEnum => method_exists($value, 'label') ? $value->label() : (string) $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_bool($value) => $value ? 'نعم' : 'لا',
            default => (string) $value,
        };
    }

    /**
     * The unit's names from the division down, as the workbook names them —
     * the root (الإدارة العامة) is every path's first step and says nothing,
     * so it is left out unless the person sits on the root itself.
     */
    private static function unitPath(int $departmentId): ?string
    {
        $unit = Department::withTrashed()->find($departmentId)?->loadAncestors();
        if ($unit === null) {
            return null;
        }

        $names = [];
        for ($step = $unit; $step?->parent !== null; $step = $step->parent) {
            array_unshift($names, $step->name);
        }

        return $names === [] ? $unit->name : implode(' › ', $names);
    }
}
