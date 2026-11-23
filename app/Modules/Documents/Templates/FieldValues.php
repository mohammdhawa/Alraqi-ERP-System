<?php

declare(strict_types=1);

namespace App\Modules\Documents\Templates;

use App\Modules\Documents\Enums\FieldType;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Checks what a creator typed against a template's fields and returns the
 * normalised values keyed by field key. Strings are trimmed, numbers kept as
 * the trimmed string, dates `Y-m-d`, table rows reduced to the column keys,
 * an empty optional value is `null`. One message per field, under
 * `field_values.{key}`.
 */
final class FieldValues
{
    private const MAX_ROWS = 100;

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function check(TemplateFields $fields, array $values): array
    {
        $clean  = [];
        $errors = [];

        foreach ($fields->all() as $field) {
            $key   = (string) $field['key'];
            $label = (string) ($field['label'] ?? $key);
            $type  = FieldType::from((string) $field['type']);

            [$value, $error] = $this->one($field, $type, $label, $values[$key] ?? null);

            $clean[$key] = $value;

            if ($error !== null) {
                $errors["field_values.{$key}"] = [$error];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /** @return array{0: mixed, 1: ?string} */
    private function one(array $field, FieldType $type, string $label, mixed $raw): array
    {
        $required = (bool) ($field['required'] ?? false);

        if ($type === FieldType::Table) {
            return $this->table($field, $label, $required, $raw);
        }

        if ($raw !== null && ! is_scalar($raw)) {
            return [null, "القيمة المختارة في «{$label}» غير صالحة."];
        }

        $text = trim((string) ($raw ?? ''));

        if ($text === '') {
            return [null, $required ? "حقل «{$label}» مطلوب." : null];
        }

        switch ($type) {
            case FieldType::Text:
            case FieldType::Textarea:
                $max = $type === FieldType::Text ? 500 : 5000;

                return mb_strlen($text) > $max
                    ? [null, "حقل «{$label}» لا يزيد على {$max} حرف."]
                    : [$text, null];

            case FieldType::Number:
                return is_numeric($text) && is_finite((float) $text)
                    ? [$text, null]
                    : [null, "حقل «{$label}» يجب أن يكون رقماً."];

            case FieldType::Date:
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);

                return $date !== false && $date->format('Y-m-d') === $text
                    ? [$text, null]
                    : [null, "حقل «{$label}» يجب أن يكون تاريخاً بصيغة سنة-شهر-يوم."];

            default: // Select
                return in_array($text, $field['options'] ?? [], true)
                    ? [$text, null]
                    : [null, "القيمة المختارة في «{$label}» غير صالحة."];
        }
    }

    /** @return array{0: mixed, 1: ?string} */
    private function table(array $field, string $label, bool $required, mixed $raw): array
    {
        if ($raw === null || $raw === []) {
            return [null, $required ? "حقل «{$label}» مطلوب." : null];
        }

        if (! is_array($raw) || ! array_is_list($raw)) {
            return [null, "حقل «{$label}» يجب أن يكون جدولاً."];
        }

        if (count($raw) > self::MAX_ROWS) {
            return [null, "جدول «{$label}» لا يزيد على 100 صف."];
        }

        $columnKeys = array_map(fn (array $c) => (string) $c['key'], $field['columns'] ?? []);
        $rows       = [];

        foreach ($raw as $row) {
            if (! is_array($row) || array_is_list($row) && $row !== []) {
                return [null, "حقل «{$label}» يجب أن يكون جدولاً."];
            }

            $clean = [];

            foreach ($columnKeys as $ck) {
                $cell = $row[$ck] ?? '';

                if (! is_scalar($cell) && $cell !== null) {
                    return [null, "حقل «{$label}» يجب أن يكون جدولاً."];
                }

                $cell = trim((string) ($cell ?? ''));

                if (mb_strlen($cell) > 500) {
                    return [null, "حقل «{$label}» لا يزيد على 500 حرف."];
                }

                $clean[$ck] = $cell;
            }

            $rows[] = $clean;
        }

        return [$rows, null];
    }
}
