<?php

declare(strict_types=1);

namespace App\Modules\Documents\Templates;

use App\Modules\Documents\Enums\FieldType;

/**
 * A template's fields: `{key, label, type, required, options?, columns?}`.
 * `fromArray()` trusts a schema that `problems()` already passed.
 */
final readonly class TemplateFields
{
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,49}$/';

    public const MAX_FIELDS = 50;

    /** @param array<int, array<string, mixed>> $fields */
    private function __construct(private array $fields) {}

    /** @param array<int, array<string, mixed>> $fields */
    public static function fromArray(array $fields): self
    {
        return new self(array_values($fields));
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->fields;
    }

    /** @return array<string> */
    public function keys(): array
    {
        return array_map(fn (array $f) => (string) $f['key'], $this->fields);
    }

    /** @return array<string, mixed>|null */
    public function get(string $key): ?array
    {
        foreach ($this->fields as $field) {
            if (($field['key'] ?? null) === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The Arabic problems of a raw, untrusted schema; empty when it is valid.
     *
     * @return array<string>
     */
    public static function problems(array $fields): array
    {
        $problems = [];

        if (count($fields) > self::MAX_FIELDS) {
            $problems[] = 'لا تزيد حقول القالب على 50.';
        }

        $seen = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                $problems[] = self::badKey();

                continue;
            }

            $key = $field['key'] ?? null;

            if (! self::validKey($key)) {
                $problems[] = self::badKey();
            } elseif (isset($seen[$key])) {
                $problems[] = "مفتاح الحقل «{$key}» مكرر.";
            } else {
                $seen[$key] = true;
            }

            if (! self::validLabel($field['label'] ?? null)) {
                $problems[] = self::labelRequired();
            }

            $label = is_string($field['label'] ?? null) ? $field['label'] : '';
            $type  = is_string($field['type'] ?? null) ? FieldType::tryFrom($field['type']) : null;

            if ($type === null) {
                $problems[] = 'نوع الحقل غير مدعوم.';

                continue;
            }

            if ($type === FieldType::Select && ! self::validOptions($field['options'] ?? null)) {
                $problems[] = "حقل القائمة «{$label}» يحتاج إلى خيار واحد على الأقل.";
            }

            if ($type === FieldType::Table) {
                $columns = $field['columns'] ?? null;

                if (! self::validColumns($columns)) {
                    $problems[] = "حقل الجدول «{$label}» يحتاج إلى عمود واحد على الأقل.";
                } else {
                    $columnKeys = [];

                    foreach ($columns as $column) {
                        $ck = $column['key'];

                        if (! self::validKey($ck)) {
                            $problems[] = self::badKey();
                        } elseif (isset($columnKeys[$ck])) {
                            $problems[] = "مفتاح الحقل «{$ck}» مكرر.";
                        } else {
                            $columnKeys[$ck] = true;
                        }

                        if (! self::validLabel($column['label'] ?? null)) {
                            $problems[] = self::labelRequired();
                        }
                    }
                }
            }
        }

        return array_values(array_unique($problems));
    }

    private static function badKey(): string
    {
        return 'مفتاح الحقل يبدأ بحرف إنجليزي صغير ويتكوّن من أحرف إنجليزية صغيرة وأرقام و _ فقط.';
    }

    private static function labelRequired(): string
    {
        return 'تسمية الحقل مطلوبة.';
    }

    private static function validKey(mixed $key): bool
    {
        return is_string($key) && preg_match(self::KEY_PATTERN, $key) === 1;
    }

    private static function validLabel(mixed $label): bool
    {
        return is_string($label) && trim($label) !== '' && mb_strlen($label) <= 100;
    }

    private static function validOptions(mixed $options): bool
    {
        if (! is_array($options) || $options === [] || count($options) > 50) {
            return false;
        }

        foreach ($options as $option) {
            if (! is_string($option) || $option === '' || mb_strlen($option) > 100) {
                return false;
            }
        }

        return true;
    }

    private static function validColumns(mixed $columns): bool
    {
        if (! is_array($columns) || $columns === [] || count($columns) > 10) {
            return false;
        }

        foreach ($columns as $column) {
            if (! is_array($column) || ! array_key_exists('key', $column)) {
                return false;
            }
        }

        return true;
    }
}
