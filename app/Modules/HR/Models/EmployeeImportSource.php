<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Modules\HR\Exceptions\ImportSourceConflictException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EmployeeImportSource — the importer's staging table (decision 11).
 *
 * One row per person as a source file identifies them, pointing at the
 * employee they became. The importer asks employeeFor() before creating
 * anyone, and calls remember() after, so a re-run against HR's corrections
 * updates people instead of duplicating them. See the migration for why the
 * key is a string the importer derives rather than a source column.
 *
 * NOT AUDITED. The employees it points at are; this table is itself the record
 * of what each import run matched, and an audit row per source row per run
 * would bury the log without telling anyone anything new.
 */
class EmployeeImportSource extends Model
{
    protected $fillable = [
        'source_key',
        'employee_id',
        'source_file',
        'source_sheet',
        'source_row',
        'source_name',
    ];

    protected function casts(): array
    {
        return [
            // Cast so remember()'s strict comparison holds on MySQL, whose
            // driver can hand integers back as strings.
            'employee_id' => 'integer',
            'source_row'  => 'integer',
        ];
    }

    /**
     * The employee a source key was mapped to on an earlier run, or null.
     *
     * INCLUDES SOFT-DELETED EMPLOYEES. A trashed person still occupies their
     * mapping, and the importer must restore them rather than insert a second
     * person beside them — the trap `users.employee_id` carries too
     * (EMPLOYEE_APP_REQUIREMENTS.md §7.1 C).
     */
    public static function employeeFor(string $sourceKey): ?Employee
    {
        $source = static::query()->where('source_key', $sourceKey)->first();

        return $source?->employee()->withTrashed()->first();
    }

    /**
     * Record that $sourceKey became $employee, and where it was read this run.
     *
     * A re-run of the same key for the same employee only moves the location —
     * HR sorts and edits the sheet between runs. A key already mapped to a
     * DIFFERENT employee is refused, never re-pointed: see the exception.
     *
     * @param  array{source_file?: string, source_sheet?: string, source_row?: int, source_name?: string}  $location
     *
     * @throws ImportSourceConflictException
     */
    public static function remember(string $sourceKey, Employee $employee, array $location = []): self
    {
        $source = static::query()->firstOrNew(['source_key' => $sourceKey]);

        if ($source->exists && $source->employee_id !== $employee->getKey()) {
            throw new ImportSourceConflictException;
        }

        $source->fill([...$location, 'employee_id' => $employee->getKey()])->save();

        return $source;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
