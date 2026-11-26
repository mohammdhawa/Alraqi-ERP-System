<?php

declare(strict_types=1);

namespace App\Modules\Documents\Pdf;

use App\Modules\Documents\Enums\FieldType;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Documents\Support\RecipientValues;
use App\Modules\Documents\Support\SystemValues;
use App\Modules\Documents\Templates\TemplateFields;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use RuntimeException;
use SplObjectStorage;

/**
 * The HTML a template prints as (documents design §8.2, §11.2), in the
 * `documents.template` frame:
 * - a page: the stored, sanitised page with every chip replaced by its value
 *   (a table field by a table) and every page break by mPDF's `<pagebreak>`.
 *   The page is walked as a DOM, never edited as a string, so a value is
 *   always a text node and prints as typed;
 * - a layout: the Blade view `documents.layouts.{layout_key}`, which escapes
 *   with `{{ }}` only.
 *
 * The frame and the body carry no CSS. STYLESHEET is written to mPDF on its
 * own, never through the body (ruling R8).
 */
final class TemplateHtml
{
    /** The page's CSS (Global Constraints, "Printing a template"). */
    public const STYLESHEET = 'body { font-family: dejavusans; font-size: 11pt; line-height: 1.6; direction: rtl; }'
        .' table { width: 100%; border-collapse: collapse; }'
        .' th, td { border: 0.3mm solid #555555; padding: 1.5mm 2mm; }'
        .' th { background-color: #F0F0F0; }';

    /** The blocks a table cannot sit in: a table chip in one replaces it, or goes after it. */
    private const PARAGRAPHS = ['p', 'h1', 'h2', 'h3', 'h4'];

    /** Text that counts as empty around a chip: white space and the invisible marks editors leave. */
    private const BLANK = '/^[\s\p{Z}\x{200B}-\x{200F}\x{FEFF}]*$/u';

    /**
     * @param  array<string, mixed>  $fieldValues  keyed by field key, as FieldValues::check() returns them
     */
    public function render(DocumentTemplate $template, array $fieldValues, SystemValues $system, ?RecipientValues $recipient): string
    {
        $fields = TemplateFields::fromArray((array) ($template->fields ?? []));
        $values = $this->printable($fields, $fieldValues);

        $content = is_string($template->layout_key) && $template->layout_key !== ''
            ? $this->layout($template, $fields, $values, $system, $recipient)
            : $this->page((string) $template->body, $fields, $values, $system, $recipient);

        return view('documents.template', ['content' => new HtmlString($content)])->render();
    }

    /**
     * Every field's value as it prints: a string (a date as `Y/m/d`), a table
     * as its rows (each a string per column, in the columns' order), and an
     * empty or missing value as null.
     *
     * @return array<string, string|array<int, array<string, string>>|null>
     */
    private function printable(TemplateFields $fields, array $values): array
    {
        $printable = [];

        foreach ($fields->all() as $field) {
            $key = (string) $field['key'];
            $printable[$key] = $this->printableValue($field, $values[$key] ?? null);
        }

        return $printable;
    }

    /** @return string|array<int, array<string, string>>|null */
    private function printableValue(array $field, mixed $value): string|array|null
    {
        $type = FieldType::tryFrom((string) ($field['type'] ?? ''));

        if ($type === FieldType::Table) {
            if (! is_array($value) || $value === []) {
                return null;
            }

            $rows = [];

            foreach ($value as $row) {
                $cells = [];

                foreach ($field['columns'] ?? [] as $column) {
                    $cell = is_array($row) ? ($row[$column['key']] ?? '') : '';
                    $cells[(string) $column['key']] = is_scalar($cell) ? (string) $cell : '';
                }

                $rows[] = $cells;
            }

            return $rows;
        }

        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        $text = trim((string) $value);

        if ($type === FieldType::Date) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);

            return $date !== false && $date->format('Y-m-d') === $text ? $date->format('Y/m/d') : $text;
        }

        return $text;
    }

    /**
     * The page with its chips replaced. A chip in a page that names nothing
     * known (a recipient chip with no recipient) prints nothing.
     */
    private function page(string $body, TemplateFields $fields, array $values, SystemValues $system, ?RecipientValues $recipient): string
    {
        if (trim($body) === '') {
            return '';
        }

        [$dom, $root] = $this->load($body);
        $xpath = new DOMXPath($dom);
        $texts = $system->toArray() + ($recipient?->toArray() ?? []);

        /** @var SplObjectStorage<DOMElement, DOMElement> $placed a paragraph, and the last table placed after it */
        $placed = new SplObjectStorage;

        foreach (iterator_to_array($xpath->query('.//span[@data-placeholder]', $root)) as $chip) {
            // A chip inside another chip went with it.
            if (! $this->isWithin($chip, $root)) {
                continue;
            }

            $key = $chip->getAttribute('data-placeholder');
            $field = str_starts_with($key, 'field.') ? $fields->get(substr($key, 6)) : null;
            $type = $field === null ? null : FieldType::tryFrom((string) ($field['type'] ?? ''));

            if ($type === FieldType::Table) {
                $rows = $values[$field['key']];
                $this->placeTable($chip, $field, is_array($rows) ? $rows : null, $root, $placed);

                continue;
            }

            $text = $field !== null ? $values[$field['key']] : ($texts[$key] ?? null);
            $this->fill($chip, is_string($text) && $text !== '' ? $text : null, $type === FieldType::Textarea);
        }

        foreach (iterator_to_array($xpath->query('.//div[@class="page-break"]', $root)) as $break) {
            $break->parentNode->replaceChild($dom->createElement('pagebreak'), $break);
        }

        $html = '';

        foreach ($root->childNodes as $node) {
            $html .= $dom->saveHTML($node);
        }

        return $html;
    }

    /** @return array{DOMDocument, DOMElement} the document, and the one element that holds the page */
    private function load(string $body): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            // The XML declaration makes libxml read UTF-8; the one root keeps
            // the page's blocks side by side, which NOIMPLIED would not.
            $dom->loadHTML('<?xml encoding="UTF-8"><div>'.$body.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        foreach ($dom->childNodes as $node) {
            if ($node instanceof DOMElement) {
                return [$dom, $node];
            }
        }

        throw new RuntimeException('The template page could not be read.');
    }

    /**
     * A chip becomes its value, as text (a long text's line breaks as
     * `<br>`). A chip the editor styled keeps its span and the style; an
     * empty value removes the chip.
     */
    private function fill(DOMElement $chip, ?string $text, bool $keepLines): void
    {
        $parent = $chip->parentNode;

        if ($text === null) {
            $parent->removeChild($chip);

            return;
        }

        $dom = $chip->ownerDocument;

        while ($chip->firstChild !== null) {
            $chip->removeChild($chip->firstChild);
        }

        foreach ($keepLines ? preg_split('/\r\n|\r|\n/', $text) : [$text] as $index => $line) {
            if ($index > 0) {
                $chip->appendChild($dom->createElement('br'));
            }

            $chip->appendChild($dom->createTextNode($line));
        }

        $chip->removeAttribute('data-placeholder');

        if (! $chip->hasAttributes()) {
            while ($chip->firstChild !== null) {
                $parent->insertBefore($chip->firstChild, $chip);
            }

            $parent->removeChild($chip);
        }
    }

    /**
     * A table field prints as a table: a header row of the column labels,
     * then the rows. It replaces its paragraph when the chip is all that
     * paragraph holds; otherwise it goes right after the paragraph (after the
     * tables already placed there, in the chips' order). A chip outside any
     * paragraph becomes the table where it stands.
     *
     * @param  array<int, array<string, string>>|null  $rows
     * @param  SplObjectStorage<DOMElement, DOMElement>  $placed
     */
    private function placeTable(DOMElement $chip, array $field, ?array $rows, DOMElement $root, SplObjectStorage $placed): void
    {
        $table = $rows === null ? null : $this->table($chip->ownerDocument, $field, $rows);
        $paragraph = $this->paragraphOf($chip, $root);

        if ($paragraph === null) {
            $table === null ? $chip->parentNode->removeChild($chip) : $chip->parentNode->replaceChild($table, $chip);

            return;
        }

        if (! $placed->contains($paragraph) && $this->holdsOnly($paragraph, $chip)) {
            $table === null ? $paragraph->parentNode->removeChild($paragraph) : $paragraph->parentNode->replaceChild($table, $paragraph);

            return;
        }

        $chip->parentNode->removeChild($chip);

        if ($table !== null) {
            $after = $placed->contains($paragraph) ? $placed[$paragraph] : $paragraph;
            $after->parentNode->insertBefore($table, $after->nextSibling);
            $placed[$paragraph] = $table;
        }
    }

    /** @param  array<int, array<string, string>>  $rows */
    private function table(DOMDocument $dom, array $field, array $rows): DOMElement
    {
        $table = $dom->createElement('table');
        $header = $table->appendChild($dom->createElement('thead'))->appendChild($dom->createElement('tr'));

        foreach ($field['columns'] ?? [] as $column) {
            $header->appendChild($dom->createElement('th'))->appendChild($dom->createTextNode((string) ($column['label'] ?? '')));
        }

        $body = $table->appendChild($dom->createElement('tbody'));

        foreach ($rows as $row) {
            $line = $body->appendChild($dom->createElement('tr'));

            foreach ($row as $cell) {
                $line->appendChild($dom->createElement('td'))->appendChild($dom->createTextNode($cell));
            }
        }

        return $table;
    }

    private function paragraphOf(DOMElement $chip, DOMElement $root): ?DOMElement
    {
        for ($node = $chip->parentNode; $node !== null && ! $node->isSameNode($root); $node = $node->parentNode) {
            if ($node instanceof DOMElement && in_array(strtolower($node->nodeName), self::PARAGRAPHS, true)) {
                return $node;
            }
        }

        return null;
    }

    /** Whether the chip is all the paragraph holds: no other text, no other element but a line break. */
    private function holdsOnly(DOMElement $paragraph, DOMElement $chip): bool
    {
        $xpath = new DOMXPath($paragraph->ownerDocument);

        foreach ($xpath->query('.//text()', $paragraph) as $text) {
            if (! $this->isWithin($text, $chip) && preg_match(self::BLANK, (string) $text->nodeValue) !== 1) {
                return false;
            }
        }

        foreach ($xpath->query('.//*', $paragraph) as $element) {
            $around = $element->isSameNode($chip) || $this->isWithin($element, $chip) || $this->isWithin($chip, $element);

            if (! $around && strtolower($element->nodeName) !== 'br') {
                return false;
            }
        }

        return true;
    }

    private function isWithin(DOMNode $node, DOMNode $ancestor): bool
    {
        for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
            if ($parent->isSameNode($ancestor)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A hand-made layout. Its key must be one of `documents.layouts`; the
     * view gets the template, the field schema, the printable values and the
     * system and recipient values keyed by their chip keys.
     */
    private function layout(DocumentTemplate $template, TemplateFields $fields, array $values, SystemValues $system, ?RecipientValues $recipient): string
    {
        $key = (string) $template->layout_key;

        if (! array_key_exists($key, (array) config('documents.layouts', []))) {
            throw new InvalidArgumentException("Unknown template layout [{$key}].");
        }

        return view("documents.layouts.{$key}", [
            'template'  => $template,
            'fields'    => $fields->all(),
            'values'    => $values,
            'system'    => $system->toArray(),
            'recipient' => $recipient?->toArray(),
        ])->render();
    }
}
