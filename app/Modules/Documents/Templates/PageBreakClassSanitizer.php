<?php

declare(strict_types=1);

namespace App\Modules\Documents\Templates;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * `class` is allowed on a `div` only as the page-break marker, exactly
 * `page-break`. Any other class is dropped.
 */
final class PageBreakClassSanitizer implements AttributeSanitizerInterface
{
    public const PAGE_BREAK = 'page-break';

    public function getSupportedElements(): ?array
    {
        return ['div'];
    }

    public function getSupportedAttributes(): ?array
    {
        return ['class'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        return $value === self::PAGE_BREAK ? $value : null;
    }
}
