<?php

declare(strict_types=1);

namespace App\Modules\Documents\Enums;

/**
 * How a document came to be: uploaded as a PDF, made from a template, or
 * moved across from legacy (documents design §6.1).
 */
enum DocumentOrigin: int
{
    case Uploaded = 1;
    case Template = 2;
    case Legacy   = 3;
}
