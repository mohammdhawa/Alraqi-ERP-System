<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Requests;

use App\Modules\Scheduling\Models\Shift;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating (POST) or changing (PATCH) a shift. The code is unique across
 * deleted shifts too. Whether a used shift's time may change is the model's
 * rule (it binds tinker as well) and comes back as a 422 with its message.
 */
class ShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $shift = $this->route('shift');
        $creating = ! $shift instanceof Shift;
        $required = $creating ? 'required' : 'sometimes';

        return [
            'code'             => [$creating ? 'required' : 'prohibited', 'string', 'max:30', 'regex:/^[A-Z0-9_]+$/', Rule::unique('shifts', 'code')],
            'name'             => [$required, 'string', 'max:100'],
            'starts_at'        => [$required, 'string', 'date_format:H:i'],
            'duration_minutes' => [$required, 'integer', 'min:1', 'max:1440'],
            'is_active'        => ['sometimes', 'boolean'],
        ] + ($creating
            // A new shift's first rules version (per-shift rules spec §5.1); each one omitted is the default.
            ? ShiftRuleRequest::fieldRules('sometimes')
            // A used shift's rules change only as a dated version (POST …/rules).
            : array_map(static fn (): array => ['prohibited'], ShiftRuleRequest::fieldRules('sometimes')));
    }

    /** The given rule fields, for the first version. @return array<string, mixed> */
    public function ruleFields(): array
    {
        return array_intersect_key($this->validated(), ShiftRuleRequest::fieldRules('sometimes'));
    }

    /** A first version's required minutes beyond the new shift's length — a 422 with its message (spec §5.1). */
    public function refusal(): ?string
    {
        $validated = $this->validated();

        return isset($validated['required_minutes']) && (int) $validated['required_minutes'] > (int) $validated['duration_minutes']
            ? ShiftRuleRequest::REQUIRED_TOO_LONG
            : null;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'code'             => 'رمز الوردية',
            'name'             => 'اسم الوردية',
            'starts_at'        => 'وقت البدء',
            'duration_minutes' => 'مدة الوردية',
            'is_active'        => 'التفعيل',
        ] + ShiftRuleRequest::fieldAttributes();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.regex'     => 'رمز الوردية أحرف إنجليزية كبيرة وأرقام فقط.',
            'code.unique'    => 'رمز الوردية مستخدم.',
            'code.prohibited' => 'لا يمكن تغيير رمز الوردية.',
        ];
    }
}
