<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Departments\Models\Department;
use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One version of a unit's official stamp (documents design §10.2). The stamp
 * belongs to the unit, not to its manager (the owner's rule 4). `path` names
 * an encrypted file (SealedImages). Written only by UnitStamps.
 *
 * @property int $id
 * @property int $department_id
 * @property string $path
 * @property string $mime_type
 * @property int|null $uploaded_by
 * @property Carbon|null $replaced_at
 */
class UnitStamp extends Model
{
    use HasAuditLog;

    protected $fillable = ['department_id', 'path', 'mime_type', 'uploaded_by', 'replaced_at'];

    protected function casts(): array
    {
        return [
            'replaced_at' => 'datetime',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
