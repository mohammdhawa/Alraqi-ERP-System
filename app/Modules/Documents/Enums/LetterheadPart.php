<?php

declare(strict_types=1);

namespace App\Modules\Documents\Enums;

/**
 * The two images of the letterhead (documents design §8.2).
 */
enum LetterheadPart: string
{
    case Header = 'header';
    case Footer = 'footer';

    public function pathColumn(): string
    {
        return $this->value.'_path';
    }

    public function uploadedAtColumn(): string
    {
        return $this->value.'_uploaded_at';
    }
}
