<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * The last صادر number given in an إدارة's register (documents design §8.4).
 * Written only by ExportNumbers, under a row lock.
 *
 * @property int $id
 * @property int $unit_id
 * @property int $current_value
 */
class DocumentCounter extends Model
{
    use HasAuditLog;

    protected $fillable = ['unit_id', 'current_value'];

    protected function casts(): array
    {
        return ['current_value' => 'integer'];
    }
}
