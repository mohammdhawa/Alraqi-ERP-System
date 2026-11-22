<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The company's letterhead: one row (documents design §8.2). Written only by
 * the Letterhead service.
 *
 * @property int $id
 * @property string|null $header_path
 * @property Carbon|null $header_uploaded_at
 * @property string|null $footer_path
 * @property Carbon|null $footer_uploaded_at
 * @property int|null $updated_by
 */
class DocumentLetterhead extends Model
{
    use HasAuditLog;

    protected $fillable = ['header_path', 'header_uploaded_at', 'footer_path', 'footer_uploaded_at', 'updated_by'];

    protected function casts(): array
    {
        return [
            'header_uploaded_at' => 'datetime',
            'footer_uploaded_at' => 'datetime',
        ];
    }
}
