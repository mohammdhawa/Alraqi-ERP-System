<?php

declare(strict_types=1);

namespace App\Modules\Documents\Templates;

use DOMDocument;
use DOMXPath;
use RuntimeException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * A template's page: the HTML the administrator writes in an editor, later
 * printed by mPDF. mPDF reads local files and URLs from `<img src>`, CSS
 * `url(...)` and its own tags, so the sanitiser keeps only a whitelist and
 * removes everything else (text is kept; `script` and `style` go with their
 * content). Nothing is escaped back in.
 */
final class TemplateBody
{
    public const MAX_BYTES = 204800;

    private const TEXT_BLOCKS = ['p', 'h1', 'h2', 'h3', 'h4', 'li', 'table', 'th', 'td', 'span', 'div'];

    public function sanitize(string $html): string
    {
        return $this->sanitizer()->sanitize($this->withoutHeadElements($html));
    }

    /**
     * The library treats `style`, `title` and `head` as head elements and
     * ignores them in a page body: it would keep their content as text, so a
     * stylesheet's `url(...)` would stay in the page. They are cut out, with
     * their content, before sanitising (repeatedly, so that a cut cannot
     * join the pieces of a new tag).
     *
     * A failed cut (a page that is not UTF-8, which the save refuses first,
     * or a regex past its limit) throws: it never empties the page, which a
     * save would store over the administrator's work (ruling R18).
     *
     * @throws RuntimeException
     */
    private function withoutHeadElements(string $html): string
    {
        do {
            $before = $html;
            $html   = preg_replace('~<(style|title|head)\b[^>]*>(?:.*?</\1\s*>|.*$)~isu', '', $html)
                ?? throw new RuntimeException('The template page could not be cleaned: '.preg_last_error_msg());
        } while ($html !== $before);

        return $html;
    }

    /**
     * The distinct `data-placeholder` values, in document order.
     *
     * @return array<string>
     */
    public function placeholders(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $found = [];

        foreach ((new DOMXPath($dom))->query('//span[@data-placeholder]') as $span) {
            $found[] = $span->getAttribute('data-placeholder');
        }

        return array_values(array_unique($found));
    }

    private function sanitizer(): HtmlSanitizer
    {
        $styled = ['style', 'dir'];

        $config = (new HtmlSanitizerConfig)
            ->defaultAction(HtmlSanitizerAction::Block)
            ->withMaxInputLength(self::MAX_BYTES)
            ->dropElement('script')
            ->dropElement('style')
            ->dropElement('head')
            ->dropElement('title')
            ->dropElement('template')
            ->dropElement('noscript');

        foreach (['strong', 'b', 'em', 'i', 'u', 'br', 'hr', 'ul', 'ol', 'thead', 'tbody', 'tr'] as $plain) {
            $config = $config->allowElement($plain);
        }

        foreach (self::TEXT_BLOCKS as $element) {
            $attributes = $styled;

            if ($element === 'span') {
                $attributes[] = 'data-placeholder';
            }

            if ($element === 'div') {
                $attributes[] = 'class';
            }

            if ($element === 'th' || $element === 'td') {
                $attributes = [...$attributes, 'colspan', 'rowspan'];
            }

            $config = $config->allowElement($element, $attributes);
        }

        return new HtmlSanitizer($config
            ->withAttributeSanitizer(new StyleAttributeSanitizer)
            ->withAttributeSanitizer(new PageBreakClassSanitizer)
            ->withAttributeSanitizer(new ValueAttributeSanitizer));
    }
}
