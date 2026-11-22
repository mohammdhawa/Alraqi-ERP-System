<?php

declare(strict_types=1);

namespace App\Modules\Documents\Requests;

use App\Modules\Documents\Rules\CompleteImageRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/documents/letterhead/{part} — a PNG or JPG up to 2 MB, read by
 * content, not only by name.
 */
class LetterheadImageRequest extends FormRequest
{
    public const MAX_KB = 2048;

    private const MESSAGE = 'الصورة يجب أن تكون PNG أو JPG لا يتجاوز حجمها 2 ميغابايت.';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'image' => [
                'required', 'file', 'mimes:png,jpg,jpeg', 'max:'.self::MAX_KB,
                new CompleteImageRule([IMAGETYPE_PNG, IMAGETYPE_JPEG], self::MESSAGE),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'image.required' => 'الصورة مطلوبة.',
            'image.file'     => self::MESSAGE,
            'image.mimes'    => self::MESSAGE,
            'image.max'      => self::MESSAGE,
        ];
    }
}
