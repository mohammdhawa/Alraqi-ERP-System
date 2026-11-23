<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A document template: typed fields and a page that prints them
 * (documents design §6).
 *
 * @property int $id
 * @property int $category_id
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property bool $for_employees
 * @property string $reference_prefix
 * @property array<int, array<string, mixed>> $fields
 * @property string|null $body
 * @property string|null $layout_key
 * @property bool $show_letterhead
 * @property int $version
 * @property int|null $legacy_id
 */
class DocumentTemplate extends Model
{
    use HasAuditLog;
    use SoftDeletes;

    protected $fillable = [
        'category_id',
        'name',
        'description',
        'is_active',
        'for_employees',
        'reference_prefix',
        'fields',
        'body',
        'layout_key',
        'show_letterhead',
        'version',
        'legacy_id',
    ];

    protected function casts(): array
    {
        return [
            'fields'          => 'array',
            'is_active'       => 'boolean',
            'for_employees'   => 'boolean',
            'show_letterhead' => 'boolean',
            'version'         => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplateCategory::class, 'category_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'template_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Whether any document was ever made from this template. A deleted
     * document counts: its number and printed copy still name the template,
     * so the template must not go.
     */
    public function isInUse(): bool
    {
        return Document::withTrashed()->where('template_id', $this->id)->exists();
    }
}
