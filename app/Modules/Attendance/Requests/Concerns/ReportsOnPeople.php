<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests\Concerns;

use App\Shared\Support\CsvList;
use Carbon\Carbon;
use Illuminate\Validation\Validator;

/**
 * What every attendance report request shares (legacy's
 * AttendanceReportRequest / MonthlyAttendanceReportRequest /
 * EmployeeAttendanceBreakdownRequest):
 *
 * - the person filters — employees, units (each with its subtree), a name or
 *   staff number — read by AttendancePopulation; the id lists take legacy's
 *   CSV or an array;
 * - for a range report, legacy's `month` (+ `year`) shorthand for a whole
 *   month, and its 92-day cap.
 */
trait ReportsOnPeople
{
    private const MAX_RANGE_DAYS = 92;

    protected function normalisePeople(): void
    {
        foreach (['employee_ids', 'department_ids'] as $key) {
            if ($this->has($key)) {
                $this->merge([$key => CsvList::parse($this->input($key))]);
            }
        }
    }

    /** `month` (+ `year`, this year by default) becomes from/to, when no range was given. */
    protected function expandMonth(): void
    {
        if (! $this->filled('month') || $this->filled('from') || $this->filled('to')) {
            return;
        }

        $month = (int) $this->input('month');
        $year = (int) $this->input('year', Carbon::now()->year);

        if ($month >= 1 && $month <= 12 && $year >= 2000 && $year <= 2100) {
            $start = Carbon::create($year, $month, 1);
            $this->merge(['from' => $start->toDateString(), 'to' => $start->copy()->endOfMonth()->toDateString()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function peopleRules(): array
    {
        return [
            'employee_id'      => ['sometimes', 'integer', 'min:1'],
            'employee_ids'     => ['sometimes', 'array'],
            'employee_ids.*'   => ['integer', 'min:1'],
            'department_id'    => ['sometimes', 'integer', 'min:1'],
            'department_ids'   => ['sometimes', 'array'],
            'department_ids.*' => ['integer', 'min:1'],
            'search'           => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function rangeRules(): array
    {
        return [
            'from'  => ['required', 'date'],
            'to'    => ['required', 'date', 'after_or_equal:from'],
            'month' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'year'  => ['sometimes', 'integer', 'min:2000', 'max:2100'],
        ];
    }

    protected function capRange(Validator $validator): void
    {
        $from = $this->input('from');
        $to = $this->input('to');

        if (is_string($from) && is_string($to) && ! $validator->errors()->has('to') && ! $validator->errors()->has('from')
            && Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1 > self::MAX_RANGE_DAYS) {
            $validator->errors()->add('to', 'النطاق الزمني المحدد طويل جداً (الحد الأقصى ٩٢ يوماً).');
        }
    }

    /**
     * @return array<string, string>
     */
    protected function sharedMessages(): array
    {
        return [
            'date.date'                 => 'التاريخ المحدد غير صحيح.',
            'from.required'             => 'تاريخ البداية مطلوب.',
            'from.date'                 => 'تاريخ البداية غير صحيح.',
            'to.required'               => 'تاريخ النهاية مطلوب.',
            'to.date'                   => 'تاريخ النهاية غير صحيح.',
            'to.after_or_equal'         => 'تاريخ النهاية يجب أن يكون بعد تاريخ البداية أو مساوياً له.',
            'month.integer'             => 'الشهر يجب أن يكون رقماً بين 1 و 12.',
            'month.min'                 => 'الشهر يجب أن يكون رقماً بين 1 و 12.',
            'month.max'                 => 'الشهر يجب أن يكون رقماً بين 1 و 12.',
            'year.integer'              => 'السنة يجب أن تكون رقماً صحيحاً.',
            'year.min'                  => 'السنة غير صحيحة.',
            'year.max'                  => 'السنة غير صحيحة.',
            'employee_id.integer'       => 'معرّف الموظف غير صالح.',
            'employee_ids.*.integer'    => 'قائمة الموظفين تحتوي معرّفاً غير صالح.',
            'employee_ids.*.min'        => 'قائمة الموظفين تحتوي معرّفاً غير صالح.',
            'department_id.integer'     => 'معرّف الوحدة التنظيمية غير صالح.',
            'department_ids.*.integer'  => 'قائمة الوحدات تحتوي معرّفاً غير صالح.',
            'department_ids.*.min'      => 'قائمة الوحدات تحتوي معرّفاً غير صالح.',
            'search.max'                => 'نص البحث طويل جداً.',
            'sort.in'                   => 'حقل الترتيب المحدد غير مدعوم.',
        ];
    }
}
