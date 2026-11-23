<?php

declare(strict_types=1);

namespace App\Modules\Documents\Templates;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * A template's `style` attribute keeps two properties and nothing else:
 * `text-align` (right, left, center, justify) and `font-size` from 8pt to
 * 36pt. Anything that could make mPDF fetch or read something (`url(...)`,
 * `@import`, backslash escapes, comments) is dropped with the rest, because
 * a declaration is kept only when its whole value matches a strict pattern.
 */
final class StyleAttributeSanitizer implements AttributeSanitizerInterface
{
    private const ALIGNS = ['right', 'left', 'center', 'justify'];

    public function getSupportedElements(): ?array
    {
        return null;
    }

    public function getSupportedAttributes(): ?array
    {
        return ['style'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $kept = [];

        foreach (explode(';', $value) as $declaration) {
            $parts = explode(':', $declaration, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $property = strtolower(trim($parts[0]));
            $given    = strtolower(trim($parts[1]));

            if ($property === 'text-align' && in_array($given, self::ALIGNS, true)) {
                $kept['text-align'] = $given;
            } elseif ($property === 'font-size' && preg_match('/^(\d{1,2}(?:\.\d{1,2})?)\s*pt$/', $given, $m) === 1
                && (float) $m[1] >= 8 && (float) $m[1] <= 36) {
                $kept['font-size'] = ((string) (float) $m[1]).'pt';
            }
        }

        if ($kept === []) {
            return null;
        }

        return implode('; ', array_map(fn (string $p, string $v) => "{$p}: {$v}", array_keys($kept), $kept));
    }
}
