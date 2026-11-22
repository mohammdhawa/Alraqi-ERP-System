<?php

declare(strict_types=1);

namespace App\Modules\Documents\Rules;

use App\Modules\Documents\Support\CompleteImage;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * The upload is a whole image of one of the given types, read by content:
 * a renamed file and a cut-off one are both refused (CompleteImage).
 */
final class CompleteImageRule implements ValidationRule
{
    /**
     * @param  array<int, int>  $types  IMAGETYPE_* constants
     */
    public function __construct(
        private readonly array $types,
        private readonly string $message,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || CompleteImage::typeOf((string) $value->getRealPath(), $this->types) === null) {
            $fail($this->message);
        }
    }
}
