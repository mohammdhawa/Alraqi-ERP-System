<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\HR\Enums\EmployeeDocumentType;
use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * EmployeeDocument Model — وثائق الموظف
 *
 * A file held on a person: the four images the new-hire intake form collects,
 * plus whatever else HR files against them.
 *
 * The rows come from the Batch 3/4 importer (the intake form's images), from
 * `/api/me/photo` (`App\Modules\Me\Services\ProfilePhotos`, the personal photo
 * only), and from the Documents module, which files a document sent to the
 * employee once they sign or refuse it (`RecordFiling`, `document_id`). The
 * record reads them through the filed list and its file
 * (`GET /api/hr/employees/{employee}/filed-documents`, `FiledDocumentController`).
 *
 * ⚠️ THE SALARY GATE. `ShamCash`, `Contract`, `AppointmentDecision` and
 * `PromotionDecision` read only through hr.employees.viewSalary, exactly as the
 * `sham_cash_account` column does (C11) — `EmployeeDocumentType::readsThroughSalary()`
 * names the four, and `scopeReadableWithout()` below leaves them out. Any new
 * way of reading these rows applies that gate, or it walks around it.
 */
class EmployeeDocument extends Model
{
    use HasAuditLog;
    use SoftDeletes;

    protected $fillable = [
        'employee_id',
        'type',
        'title',
        'file_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'uploaded_by',
        'issued_at',
        'expires_at',
        'document_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type'       => EmployeeDocumentType::class,
            'issued_at'  => 'date',
            'expires_at' => 'date',
            'size_bytes' => 'integer',
        ];
    }

    /**
     * The person this document belongs to.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** The sent document this row files, if any; a deleted one still shows. */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class)->withTrashed();
    }

    /**
     * The account that filed it. Null for rows created by the importer, which
     * has no authenticated user — an honest null rather than crediting the
     * person who happened to run the script.
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Documents a caller WITHOUT hr.employees.viewSalary may see.
     *
     * Written here rather than in a future controller so the rule has one
     * definition from the moment the first endpoint is built (P1). A payment
     * instrument is gated by its content, not by where it is rendered.
     *
     * @param  Builder<self>  $query
     */
    public function scopeReadableWithout(Builder $query): void
    {
        $query->whereNotIn('type', array_map(
            static fn (EmployeeDocumentType $type): int => $type->value,
            array_filter(EmployeeDocumentType::cases(), static fn (EmployeeDocumentType $type): bool => $type->readsThroughSalary()),
        ));
    }
}
