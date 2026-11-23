<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Modules\Documents\Enums\Confirmation;
use App\Modules\Documents\Enums\MarkKind;
use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing printed on a document, in order (documents design §6.2). The
 * stamp and signature columns name the exact version printed, so a later
 * replacement never changes an old document. Rows are never changed or
 * deleted.
 *
 * @property int $id
 * @property int $document_id
 * @property int $position
 * @property MarkKind $kind
 * @property int|null $approval_step_id
 * @property int $employee_id
 * @property string $employee_name
 * @property int|null $unit_id
 * @property string|null $unit_name
 * @property int|null $stamp_id
 * @property int $signature_id
 * @property bool $decisive
 * @property string|null $note
 * @property Confirmation $confirmed_by
 * @property Carbon $marked_at
 */
class DocumentMark extends Model
{
    use HasAuditLog;

    protected $fillable = [
        'document_id',
        'position',
        'kind',
        'approval_step_id',
        'employee_id',
        'employee_name',
        'unit_id',
        'unit_name',
        'stamp_id',
        'signature_id',
        'decisive',
        'note',
        'confirmed_by',
        'marked_at',
    ];

    protected function casts(): array
    {
        return [
            'kind'         => MarkKind::class,
            'confirmed_by' => Confirmation::class,
            'decisive'     => 'boolean',
            'marked_at'    => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function stamp(): BelongsTo
    {
        return $this->belongsTo(UnitStamp::class, 'stamp_id');
    }

    /** Withdrawn signatures still print on the documents they signed. */
    public function signature(): BelongsTo
    {
        return $this->belongsTo(EmployeeSignature::class, 'signature_id')->withTrashed();
    }
}
