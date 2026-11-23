<?php

declare(strict_types=1);

namespace App\Modules\Documents\Enums;

/**
 * How the signer confirmed who they are (documents design §10.3).
 */
enum Confirmation: int
{
    case Biometric = 1;
    case Password  = 2;
}
