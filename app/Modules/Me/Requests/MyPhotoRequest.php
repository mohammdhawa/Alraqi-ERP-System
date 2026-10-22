<?php

declare(strict_types=1);

namespace App\Modules\Me\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload the caller's own photo. Self-scoped, so authorize() is true: the
 * route's auth:sanctum is the whole gate. JPG or PNG up to 5 MB — the owner's
 * answer of 2026-10-19.
 */
class MyPhotoRequest extends FormRequest
{
    public const MAX_KB = 5120;

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
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:'.self::MAX_KB],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photo.required' => 'الصورة مطلوبة.',
            'photo.file'     => 'الصورة يجب أن تكون ملفاً.',
            'photo.mimes'    => 'الصورة يجب أن تكون JPG أو PNG.',
            'photo.max'      => 'حجم الصورة يجب ألا يتجاوز 5 ميغابايت.',
        ];
    }
}
