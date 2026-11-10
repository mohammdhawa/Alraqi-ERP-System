<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** HR cancels an approved exchange, with a reason (work shifts piece 2, spec §6.4). */
class CancelShiftSwapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['reason' => 'سبب الإلغاء'];
    }
}
