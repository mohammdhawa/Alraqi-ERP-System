<?php

declare(strict_types=1);

namespace App\Modules\Me\Requests;

use App\Modules\Auth\Enums\DevicePlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Register or unregister a push token. Self-scoped, so authorize() is true:
 * the route's auth:sanctum is the whole gate.
 *
 * `platform` is required to register and ignored on unregister.
 */
class DeviceTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'token'    => ['required', 'string', 'max:512'],
            'platform' => $this->isMethod('delete')
                ? ['sometimes']
                : ['required', Rule::enum(DevicePlatform::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required'    => 'رمز الإشعارات مطلوب.',
            'platform.required' => 'نوع الجهاز مطلوب.',
            'platform.enum'     => 'نوع الجهاز غير معروف.',
        ];
    }
}
