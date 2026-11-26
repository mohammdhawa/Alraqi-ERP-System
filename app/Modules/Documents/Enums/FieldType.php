<?php

declare(strict_types=1);

namespace App\Modules\Documents\Enums;

/**
 * What a template field takes. The values are legacy's keys, so the import
 * maps one to one.
 */
enum FieldType: string
{
    case Text     = 'text';
    case Textarea = 'textarea';
    case Number   = 'number';
    case Date     = 'date';
    case Select   = 'select';
    case Table    = 'table';
}
