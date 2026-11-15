<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Requests;

use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Scheduling\Models\Shift;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new version of a shift's rules (per-shift rules spec §5.1): every field,
 * from today or later, one per date, required minutes within the shift's
 * length. The field rules are shared with a new shift's first version.
 */
class ShiftRuleRequest extends FormRequest
{
    public const REQUIRED_TOO_LONG = 'الساعات المطلوبة لا تتجاوز طول الوردية.';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['effective_from' => ['required', 'date_format:Y-m-d']] + self::fieldRules('required');
    }

    /**
     * The rule fields and their limits (spec §2.1).
     *
     * @return array<string, list<string>>
     */
    public static function fieldRules(string $presence): array
    {
        return [
            'late_grace_minutes'        => [$presence, 'integer', 'min:0', 'max:120'],
            'early_leave_grace_minutes' => [$presence, 'integer', 'min:0', 'max:120'],
            'check_in_opens_minutes'    => [$presence, 'integer', 'min:0', 'max:240'],
            'check_out_closes_minutes'  => [$presence, 'integer', 'min:0', 'max:480'],
            'overtime_enabled'          => [$presence, 'boolean'],
            'overtime_min_minutes'      => [$presence, 'integer', 'min:0', 'max:240'],
            'required_minutes'          => [$presence, 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public static function fieldAttributes(): array
    {
        return [
            'late_grace_minutes'        => 'مهلة التأخر',
            'early_leave_grace_minutes' => 'مهلة الانصراف المبكر',
            'check_in_opens_minutes'    => 'فتح التسجيل قبل البداية',
            'check_out_closes_minutes'  => 'قبول الانصراف بعد النهاية',
            'overtime_enabled'          => 'احتساب العمل الإضافي',
            'overtime_min_minutes'      => 'الحد الأدنى للعمل الإضافي',
            'required_minutes'          => 'الساعات المطلوبة',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['effective_from' => 'تاريخ البدء'] + self::fieldAttributes();
    }

    /**
     * The refusals that are the shift's business, not the form's — a 422 with
     * the message itself, as every scheduling refusal (spec §5.1).
     */
    public function refusal(Shift $shift): ?string
    {
        $from = (string) $this->validated('effective_from');

        return match (true) {
            $from < app(AttendanceCalendar::class)->today()                  => 'لا يمكن أن تبدأ القواعد قبل اليوم.',
            $shift->rules()->where('effective_from', $from)->exists()        => 'توجد قواعد لهذه الوردية من التاريخ نفسه.',
            (int) $this->validated('required_minutes') > $shift->duration_minutes => self::REQUIRED_TOO_LONG,
            default                                                          => null,
        };
    }
}
