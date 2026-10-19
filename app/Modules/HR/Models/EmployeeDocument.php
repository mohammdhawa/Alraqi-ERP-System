<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Modules\Auth\Models\User;
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
 * ⚠️ NO ENDPOINTS EXIST FOR THIS YET, BY DESIGN. The table and model are here
 * because the Batch 3/4 importer attaches the intake form's images as it
 * creates each employee, and a table that arrives after the import is a second
 * migration against real rows. Upload, storage disk, size/type limits and
 * signed download URLs belong to the Documents module — Stage 2 of the merge
 * plan, ported from the legacy system, which already solves all of them in
 * production. Building a second file pipeline here and unifying it later is the
 * duplication the stage ordering exists to prevent.
 *
 * ⚙️ ONE EXCEPTION, 2026-10-19: the employee's own personal photo
 * (`/api/me/photo`, work order §4.8) — no photo is imported, so the app needs
 * an upload from day one. `App\Modules\Me\Services\ProfilePhotos` handles that
 * one type only; every other type still waits for the Documents module.
 *
 * ⚠️ WHOEVER WRITES THOSE ENDPOINTS OWES ONE GATE. `ShamCash` is a photograph
 * of a payment account, which is the account number. It must read through
 * hr.employees.viewSalary exactly as the `sham_cash_account` column does, or a
 * document listing walks straight around that column's gate (C11). The
 * `scopeReadableWithout()` scope below is that rule, written once and ready.
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
        $query->whereNot('type', EmployeeDocumentType::ShamCash->value);
    }
}
