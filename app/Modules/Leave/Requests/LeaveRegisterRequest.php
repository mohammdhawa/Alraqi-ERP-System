<?php

declare(strict_types=1);

namespace App\Modules\Leave\Requests;

use App\Modules\Attendance\Requests\Concerns\ReportsOnPeople;
use App\Modules\Leave\Enums\LeaveStatus;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * GET /api/leave/requests — legacy's LeaveRegisterRequest: the own list's
 * filters, the person filters, and a WINDOW — `from` / `to`, or `month` (+
 * `year`) for that month, or the current month when none is given. At most
 * 366 days; never inverted. Approved rows by default — an excuse is approved,
 * so it is in.
 *
 * `month` / `year` here name the window, not the start-month filter of the
 * own list: they are folded into `from` / `to` before validation.
 */
class LeaveRegisterRequest extends LeaveRequestsFilterRequest
{
    use ReportsOnPeople;

    private const MAX_WINDOW_DAYS = 366;

    public const SORTS = ['start_date', 'end_date', 'requested_days', 'created_at', 'employee_name', 'id'];

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->normalisePeople();

        $noRange = ! $this->filled('from') && ! $this->filled('to');

        if ($noRange && ! $this->filled('month') && $this->filled('year')) {
            // A bare year is that whole year (legacy's resolveWindow()) —
            // never silently this month.
            $year = (int) $this->input('year');

            if ($year >= 2000 && $year <= 2100) {
                $this->merge(['from' => "{$year}-01-01", 'to' => "{$year}-12-31"]);
            }
        } elseif ($noRange && ! $this->filled('month')) {
            $now = Carbon::now();
            $this->merge(['from' => $now->copy()->startOfMonth()->toDateString(), 'to' => $now->copy()->endOfMonth()->toDateString()]);
        } else {
            $this->expandMonth();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $sorts = [...self::SORTS, ...array_map(static fn (string $key): string => '-'.$key, self::SORTS)];

        return array_merge(parent::rules(), $this->peopleRules(), [
            // Written in full: the window is compared as Y-m-d text when rows
            // are clipped to it, so `2026-6-1` would clip wrongly in silence.
            'from'  => ['required', 'date_format:Y-m-d'],
            'to'    => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'month' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'year'  => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'sort'  => ['sometimes', 'string', Rule::in($sorts)],
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $from = $this->input('from');
            $to = $this->input('to');

            if (is_string($from) && is_string($to) && ! $validator->errors()->hasAny(['from', 'to'])
                && Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1 > self::MAX_WINDOW_DAYS) {
                $validator->errors()->add('to', 'نطاق السجل طويل جداً (الحد الأقصى 366 يوماً).');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from.date_format' => 'تاريخ البداية يجب أن يكون بالصيغة YYYY-MM-DD.',
            'to.date_format'   => 'تاريخ النهاية يجب أن يكون بالصيغة YYYY-MM-DD.',
        ] + parent::messages() + $this->sharedMessages();
    }

    /**
     * The filters for the query — the window replaces the own list's
     * month / year, so they are not passed on.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $filters = $this->validated();
        unset($filters['month'], $filters['year']);

        if (! isset($filters['status']) && ! isset($filters['statuses'])) {
            $filters['statuses'] = [LeaveStatus::Approved->value];
        }

        return $filters;
    }
}
