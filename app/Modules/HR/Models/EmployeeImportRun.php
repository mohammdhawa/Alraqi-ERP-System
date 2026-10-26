<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ImportRunStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One upload on the import screen: the dry run of two stored files, and at
 * most one real import of exactly those files (EMPLOYEE_IMPORT_UPLOAD_DESIGN.md
 * §4.1, U5). It belongs to its uploader alone (§4.2).
 *
 * Not audited by HasAuditLog: `report` holds names, national IDs and pay
 * values, and a field-level trail would keep a copy of them for good. The
 * service logs each check and each real run instead, with counts only (§4.5).
 */
class EmployeeImportRun extends Model
{
    /** How long the files wait for the real run (U5). */
    public const LIFETIME_MINUTES = 60;

    protected $fillable = [
        'created_by',
        'status',
        'workbook_name',
        'workbook_path',
        'workbook_sha256',
        'summary',
        'report',
        'importable_rows',
        'excluded_rows',
        'checked_at',
        'executed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'created_by'  => 'integer',
            'status'      => ImportRunStatus::class,
            'summary'     => 'array',
            'report'      => 'array',
            // Design §12: sheet row numbers.
            'importable_rows' => 'array',
            'excluded_rows'   => 'array',
            'checked_at'  => 'datetime',
            'executed_at' => 'datetime',
            'expires_at'  => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Past its hour, or already marked so. An executed run is "expired" too
     * once its hour passes — its report goes then — but it is refused for
     * having run, not for its age.
     */
    public function isExpired(?CarbonInterface $now = null): bool
    {
        return $this->status === ImportRunStatus::Expired
            || ($now ?? now())->greaterThanOrEqualTo($this->expires_at);
    }
}
