<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Modules\HR\Models\Employee;
use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A comment on a document (documents design §6.3).
 *
 * @property int $id
 * @property int $document_id
 * @property int $employee_id
 * @property string $body
 */
class DocumentComment extends Model
{
    use HasAuditLog;
    use SoftDeletes;

    protected $fillable = ['document_id', 'employee_id', 'body'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
