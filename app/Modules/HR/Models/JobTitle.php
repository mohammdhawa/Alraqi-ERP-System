<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Modules\HR\Enums\ActivityType;
use App\Modules\HR\Exceptions\JobTitleInUseException;
use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * JobTitle Model — المسمى الوظيفي
 *
 * One entry in the company's governed catalogue of job titles, replacing the
 * free string `employees.job_title` that preceded it.
 *
 * ONE SHARED CATALOGUE (ratified 2026-09-13). `name` is unique company-wide and
 * there is no `department_id`: محاسب is a single row with a single job
 * description, whichever divisions employ accountants. "Which divisions use
 * this title?" is answered from real postings
 * (`SELECT DISTINCT department_id FROM employees WHERE job_title_id = ?`) and
 * therefore cannot drift away from them (P1).
 *
 * THE DEFAULTS ARE SUGGESTIONS, NOT CONSTRAINTS. `default_organizational_grade`
 * and `default_activity_type` pre-fill the employee form when a title is
 * chosen; the employee's own columns remain the fact. This is not laziness
 * about referential integrity — it is the domain: a section head and a division
 * head can hold one title at different grades, so the title genuinely cannot
 * own the grade.
 *
 * WHY HasAuditLog: the catalogue is reference data that pay reads through. Who
 * renamed a title, marked it described, or deactivated it is exactly what an
 * audit is asked afterwards.
 *
 * WHY SoftDeletes (C23): `employees.job_title_id` and
 * `employee_assignments.job_title_id` both point here, and a posting history
 * that cannot resolve the title someone held is not a history.
 */
class JobTitle extends Model
{
    use HasAuditLog;
    use SoftDeletes;

    /**
     * Defaults mirroring the column defaults in the migration.
     *
     * A database default is applied by the INSERT, so a model created without
     * these keys holds NULL for them in memory — and the Resource built from
     * that instance would answer `"is_described": null` on a create while the
     * stored row says `false`. The client would then be told "unknown" about
     * something the database is certain of, and only a re-fetch would correct
     * it. Declaring them here makes the create response and the row agree.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_described' => false,
        'is_active'    => true,
    ];

    protected $fillable = [
        'name',
        'default_organizational_grade',
        'default_activity_type',
        'is_described',
        'description',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_activity_type'        => ActivityType::class,
            'default_organizational_grade' => 'integer',
            'is_described'                 => 'boolean',
            'is_active'                    => 'boolean',
        ];
    }

    /**
     * Refuse to delete a title anybody holds, or has ever held.
     *
     * WHY A HOOK AND NOT `restrictOnDelete`: both FKs pointing here are
     * nullOnDelete on purpose — dissolving a title must not delete its holders.
     * But a SOFT delete is an UPDATE, so no FK behaviour fires at all: the rows
     * would keep pointing at a row every default query hides, and an employee's
     * title would quietly render blank while a historical posting lost the job
     * it was computed for. The constraint cannot express this; the hook can, and
     * it binds the seeder and tinker too (C4).
     *
     * The history check is the important half. A title nobody currently holds
     * may still be the title someone held in 2024, and that row is what a
     * payslip from 2024 is explained by.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $jobTitle): void {
            if ($jobTitle->employees()->exists() || $jobTitle->assignments()->exists()) {
                throw new JobTitleInUseException;
            }
        });
    }

    /**
     * Employees currently holding this title.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Every posting ever made under this title, current or historical.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeAssignment::class);
    }

    /**
     * Only titles that may still be assigned.
     *
     * `is_active = false` retires a title without deleting it — the right move
     * for a title staff still hold but that the company no longer hires into,
     * which a soft delete would refuse anyway.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Titles قسم التحليل والتوصيف has not written a description for yet —
     * that department's actual worklist.
     *
     * @param  Builder<self>  $query
     */
    public function scopeUndescribed(Builder $query): void
    {
        $query->where('is_described', false);
    }
}
