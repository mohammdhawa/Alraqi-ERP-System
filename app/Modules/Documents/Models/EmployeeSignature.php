<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Documents\Enums\SignatureSource;
use App\Modules\HR\Models\Employee;
use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One version of an employee's signature (documents design §10.1). `path`
 * names an encrypted file (SealedImages), never served to anyone but its
 * owner. Written only by Signatures.
 *
 * @property int $id
 * @property int $employee_id
 * @property string $path
 * @property SignatureSource $drawn_on
 * @property int|null $deleted_by
 * @property string|null $delete_reason
 */
class EmployeeSignature extends Model
{
    use HasAuditLog;
    use SoftDeletes;

    protected $fillable = ['employee_id', 'path', 'drawn_on', 'deleted_by', 'delete_reason'];

    protected function casts(): array
    {
        return [
            'drawn_on' => SignatureSource::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
