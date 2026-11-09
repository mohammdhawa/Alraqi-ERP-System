<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Requests;

use App\Modules\Scheduling\Enums\WorkScheduleKind;
use App\Modules\Scheduling\Models\Shift;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Modules\Scheduling\Support\ScheduleGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating (POST) or changing (PATCH) a schedule, its days in one body:
 * `days: [{position, shift_id|null}]`. ScheduleGuard's violations come back
 * as field errors on `days` (C5) — the same guard the model runs. The kind
 * and length are fixed once created: a different shape is a new schedule.
 */
class WorkScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = ! $this->route('schedule') instanceof WorkSchedule;

        return [
            'code'              => [$creating ? 'required' : 'prohibited', 'string', 'max:30', 'regex:/^[A-Z0-9_]+$/', Rule::unique('work_schedules', 'code')],
            'name'              => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'kind'              => [$creating ? 'required' : 'prohibited', 'integer', Rule::enum(WorkScheduleKind::class)],
            'cycle_days'        => [$creating ? 'required' : 'prohibited', 'integer', 'min:1', 'max:'.ScheduleGuard::MAX_CYCLE_DAYS],
            'is_active'         => ['sometimes', 'boolean'],
            'days'              => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:'.ScheduleGuard::MAX_CYCLE_DAYS],
            'days.*.position'   => ['required', 'integer', 'min:0', 'distinct'],
            'days.*.shift_id'   => ['present', 'nullable', 'integer', Rule::exists('shifts', 'id')->whereNull('deleted_at')],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->has('days')) {
                return;
            }

            $schedule = $this->route('schedule');
            $kind = $schedule instanceof WorkSchedule ? $schedule->kind : WorkScheduleKind::from((int) $this->input('kind'));
            $cycleDays = $schedule instanceof WorkSchedule ? $schedule->cycle_days : (int) $this->input('cycle_days');
            $days = $this->days();

            $shifts = Shift::query()->whereKey(array_filter($days))->get()
                ->mapWithKeys(fn (Shift $shift): array => [$shift->id => $shift->guardFacts()])->all();

            foreach (ScheduleGuard::violations($kind, $cycleDays, $days, $shifts) as $violation) {
                $validator->errors()->add('days', $violation);
            }
        }];
    }

    /** @return array<int, int|null> position => shift id */
    public function days(): array
    {
        $days = [];
        foreach ((array) $this->input('days', []) as $day) {
            $days[(int) $day['position']] = $day['shift_id'] === null ? null : (int) $day['shift_id'];
        }

        return $days;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'code'       => 'رمز الجدول',
            'name'       => 'اسم الجدول',
            'kind'       => 'نوع الجدول',
            'cycle_days' => 'طول الدورة',
            'days'       => 'أيام الجدول',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.regex'       => 'رمز الجدول أحرف إنجليزية كبيرة وأرقام فقط.',
            'code.unique'      => 'رمز الجدول مستخدم.',
            'code.prohibited'  => 'لا يمكن تغيير رمز الجدول.',
            'kind.prohibited'  => 'لا يمكن تغيير نوع الجدول؛ أنشئ جدولاً جديداً.',
            'cycle_days.prohibited' => 'لا يمكن تغيير طول الدورة؛ أنشئ جدولاً جديداً.',
        ];
    }
}
