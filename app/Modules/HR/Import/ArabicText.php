<?php

declare(strict_types=1);

namespace App\Modules\HR\Import;

/**
 * How the importer compares Arabic text typed by hand in a workbook.
 *
 * The same unit or title arrives spelled several ways across HR's files —
 * «الإدارة المالية» and «الاداره الماليه», «قسم  النقدية» with a double space,
 * «( رحبة الصيانة )» with padded brackets. A key is the text with those
 * differences folded away; the ORIGINAL text is what gets stored and shown.
 *
 * Folded: surrounding and repeated whitespace, tatweel, the hamza-carrying
 * alefs (أ إ آ → ا), ta-marbuta (ة → ه), alef-maqsura (ى → ي), and spaces just
 * inside brackets. Not folded: anything that changes which word it is.
 */
final class ArabicText
{
    public static function key(?string $text): string
    {
        $text = str_replace("\u{0640}", '', (string) $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $text = str_replace(['( ', ' )'], ['(', ')'], $text);

        return strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي']);
    }

    /**
     * The text as it should be STORED: trimmed and with runs of whitespace
     * collapsed, otherwise exactly as HR typed it. Null for blank.
     */
    public static function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $text === '' ? null : $text;
    }
}
