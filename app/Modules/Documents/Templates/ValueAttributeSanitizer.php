<?php

declare(strict_types=1);

namespace App\Modules\Documents\Templates;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * The attributes with a closed set of values: `dir` (rtl or ltr), `colspan`
 * and `rowspan` (1 to 20) and a chip's `data-placeholder` (a dotted key of
 * lowercase letters, digits and underscores).
 */
final class ValueAttributeSanitizer implements AttributeSanitizerInterface
{
    public function getSupportedElements(): ?array
    {
        return null;
    }

    public function getSupportedAttributes(): ?array
    {
        return ['dir', 'colspan', 'rowspan', 'data-placeholder'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $value = trim($value);

        return match ($attribute) {
            'dir'              => in_array(strtolower($value), ['rtl', 'ltr'], true) ? strtolower($value) : null,
            'colspan', 'rowspan' => preg_match('/^\d{1,2}$/', $value) === 1 && (int) $value >= 1 && (int) $value <= 20
                ? (string) (int) $value : null,
            'data-placeholder' => preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $value) === 1 ? $value : null,
            default            => null,
        };
    }
}
