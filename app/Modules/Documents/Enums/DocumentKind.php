<?php

declare(strict_types=1);

namespace App\Modules\Documents\Enums;

/**
 * Between managers, or to one employee (documents design §6.1).
 */
enum DocumentKind: int
{
    case Internal   = 1;
    case ToEmployee = 2;
}
