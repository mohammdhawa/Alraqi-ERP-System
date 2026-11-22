<?php

declare(strict_types=1);

namespace App\Modules\Documents\Requests;

use App\Modules\Documents\Rules\CompleteImageRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/documents/stamps/{department} — a unit's stamp: PNG or JPG up to
 * 2 MB. The content is read, not only the name, so a renamed file is refused.
 */
class UnitStampRequest extends FormRequest
{
    public const MAX_KB = 2048;

    private const MESSAGE = 'الختم يجب أن يكون صورة PNG أو JPG لا يتجاوز حجمها 2 ميغابايت.';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stamp' => [
                'required', 'file', 'mimes:png,jpg,jpeg', 'max:'.self::MAX_KB,
                new CompleteImageRule([IMAGETYPE_PNG, IMAGETYPE_JPEG], self::MESSAGE),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'stamp.required' => 'صورة الختم مطلوبة.',
            'stamp.file'     => self::MESSAGE,
            'stamp.mimes'    => self::MESSAGE,
            'stamp.max'      => self::MESSAGE,
        ];
    }
}
