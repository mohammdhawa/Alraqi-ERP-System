<?php

declare(strict_types=1);

namespace App\Modules\Documents\Templates;

/**
 * The chip keys a page may use: the template's own fields, the system's
 * values, and, for a template sent to employees, the recipient's.
 */
final class Placeholders
{
    public const SYSTEM = [
        'system.export_number',
        'system.import_number',
        'system.reference',
        'system.date',
        'system.creator_name',
        'system.creator_unit',
    ];

    public const RECIPIENT = [
        'recipient.name',
        'recipient.job_title',
        'recipient.unit',
        'recipient.employee_number',
    ];

    /** @return array<string> */
    public static function allowed(TemplateFields $fields, bool $forEmployees): array
    {
        return [
            ...array_map(fn (string $key) => "field.{$key}", $fields->keys()),
            ...self::SYSTEM,
            ...($forEmployees ? self::RECIPIENT : []),
        ];
    }

    /**
     * @param  array<string>  $found
     * @return array<string>
     */
    public static function unknown(array $found, TemplateFields $fields, bool $forEmployees): array
    {
        return array_values(array_diff($found, self::allowed($fields, $forEmployees)));
    }
}
