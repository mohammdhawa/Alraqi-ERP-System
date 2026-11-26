<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A group of document templates (documents design §6).
 *
 * @property int $id
 * @property string $name
 * @property int $sort
 */
class DocumentTemplateCategory extends Model
{
    use HasAuditLog;
    use SoftDeletes;

    protected $fillable = ['name', 'sort'];

    protected function casts(): array
    {
        return ['sort' => 'integer'];
    }

    public function templates(): HasMany
    {
        return $this->hasMany(DocumentTemplate::class, 'category_id');
    }
}
