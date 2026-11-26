<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * The last sequence given to a template reference prefix in a year (documents
 * design §8.4). Written only by ReferenceNumbers, under a row lock.
 *
 * @property int $id
 * @property string $prefix
 * @property int $year
 * @property int $current_value
 */
class DocumentReferenceSequence extends Model
{
    use HasAuditLog;

    protected $fillable = ['prefix', 'year', 'current_value'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'current_value' => 'integer'];
    }
}
