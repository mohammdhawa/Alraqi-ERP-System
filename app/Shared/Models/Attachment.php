<?php

declare(strict_types=1);

namespace App\Shared\Models;

use App\Modules\Auth\Models\User;
use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One file on a record (LEAVE_BUILD.md §5). Written and streamed by
 * AttachmentStore; who may read it is the SUBJECT's policy — a leave file is
 * readable exactly by who may read the leave.
 *
 * A subject declares `morphMany(Attachment::class, 'attachable')->orderBy('id')`.
 *
 * In Shared because documents need exactly the same thing in November.
 */
class Attachment extends Model
{
    use HasAuditLog;
    use SoftDeletes;

    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'uploaded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
