<?php

declare(strict_types=1);

namespace App\Modules\Documents\Requests;

use App\Modules\Documents\Enums\SignatureSource;
use App\Modules\Documents\Rules\CompleteImageRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/me/signature — the pad's drawing: a PNG up to 512 KB (documents
 * plan, Global Constraints). The content is read, not only the name, so a
 * renamed file and a cut-off one are refused.
 */
class MySignatureRequest extends FormRequest
{
    public const MAX_KB = 512;

    private const MESSAGE = 'التوقيع يجب أن يكون صورة PNG لا يتجاوز حجمها 512 كيلوبايت.';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'signature' => [
                'required', 'file', 'mimes:png', 'max:'.self::MAX_KB,
                new CompleteImageRule([IMAGETYPE_PNG], self::MESSAGE),
            ],
            'drawn_on' => ['required', 'integer', Rule::enum(SignatureSource::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'signature.required' => 'التوقيع مطلوب.',
            'signature.file'     => self::MESSAGE,
            'signature.mimes'    => self::MESSAGE,
            'signature.max'      => self::MESSAGE,
            'drawn_on.required'  => 'مكان رسم التوقيع مطلوب.',
            'drawn_on.integer'   => 'مكان رسم التوقيع غير صالح.',
            'drawn_on.enum'      => 'مكان رسم التوقيع غير صالح.',
        ];
    }

    public function source(): SignatureSource
    {
        return SignatureSource::from((int) $this->validated('drawn_on'));
    }
}
