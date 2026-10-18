<?php

declare(strict_types=1);

namespace App\Modules\Leave\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/leave/types — legacy's LeaveTypeController::index filters:
 * `for` (`requests` / `excuses`, the form the picker is on), `deducts_balance`,
 * and `active`.
 *
 * Every authenticated caller reads the ACTIVE types — the employee's leave
 * form needs them. Retired ones (`active=0`) are for whoever manages the
 * catalogue: legacy showed them to admins only; here, `leave.types.update`.
 */
class LeaveTypesIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ! $this->wantsRetired() || $this->user()?->hasPermission('leave.types.update') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'for'             => ['sometimes', 'string', 'in:requests,excuses'],
            'deducts_balance' => ['sometimes', 'boolean'],
            'active'          => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'for.in'                  => 'قيمة النموذج غير صالحة.',
            'deducts_balance.boolean' => 'قيمة الخصم من الرصيد غير صالحة.',
            'active.boolean'          => 'قيمة التفعيل غير صالحة.',
        ];
    }

    public function wantsRetired(): bool
    {
        return $this->has('active') && ! $this->boolean('active');
    }
}
