<?php

declare(strict_types=1);

namespace App\Modules\Departments\Models;

use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Exceptions\DepartmentHasChildrenException;
use App\Modules\Departments\Exceptions\DepartmentHierarchyException;
use App\Modules\Departments\Exceptions\DepartmentIsRootException;
use App\Modules\Departments\Support\DepartmentHierarchyGuard;
use App\Modules\HR\Models\Employee;
use App\Shared\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Department Model
 *
 * An organizational unit at ANY tier of the company tree:
 *
 *   الإدارة العامة (GeneralAdministration, level 1) -> إدارة (Division, level 2)
 *     -> قسم (Section, level 3) -> [future tiers…]
 *
 * NAMING (deliberate): "Department" is the superordinate term for a unit at any
 * tier — it is NOT the Arabic "الإدارة العامة" (level 1) specifically. A row
 * here may be the general administration, a division, a section, or any tier
 * added later. Never write code that assumes Department == a fixed tier; the
 * tier is always `level` + DepartmentLevel.
 *
 * Departments are their own module (not folded into HR) so the aggregate owns
 * its routes, schema, and lifecycle independently.
 *
 * WHY one self-referencing table instead of a table per tier:
 * - A tier-per-table schema forces a new table, model, and set of FKs for every
 *   future tier, and every "walk the org chart" query becomes a UNION.
 * - Here, adding a tier is one enum case. The tree shape is data, not schema.
 *
 * WHY `level` and not `parent_id IS NULL`:
 * - `level` is the authoritative tier marker. The root is the general
 *   administration because its level says 1, not because it happens to lack a
 *   parent. Business logic that infers tier from parent_id breaks the moment a
 *   tier is inserted above.
 *
 * WHY HasAuditLog:
 * - Departments are low-frequency, high-importance records. Who renamed a
 *   department, re-parented it, or reassigned its manager is exactly the kind of
 *   change the audit trail exists to capture (architecture report §16.6, §16.14).
 *
 * WHY SoftDeletes:
 * - Rows are never hard-deleted in this ERP: employees (and later modules) FK to
 *   departments, and the audit trail must stay resolvable.
 *
 * RELATIONS:
 * - parent()/children(): the hierarchy itself, both on parent_id.
 * - employees(): the staff that belong to this unit.
 * - manager(): the employee in charge of it — one column serving every tier
 *   (division manager / section head).
 *
 * NOTE ON Employee: the Employee model and its table live in the HR module
 * (App\Modules\HR\Models\Employee). The relation is a deliberate cross-module
 * reference — Departments is its own aggregate, HR owns the people.
 */
class Department extends Model
{
    use HasAuditLog;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'description',
        'is_active',
        'parent_id',
        'level',
        'manager_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // The column stays an unsignedTinyInteger in the schema; the cast is
        // what makes it a tier in PHP. $department->level->label() reads the
        // Arabic name without a lookup table.
        return [
            'level'     => DepartmentLevel::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Model-layer guards that cover EVERY write path — controller, service,
     * seeder, tinker, factory, future modules — not just the HTTP front door.
     *
     * WHY here and not only in the form requests: the FK and the form requests
     * each guard one thing (the parent row existing; HTTP input). Neither stops
     * a non-HTTP writer from filing a section under a section or minting a
     * second root — which is exactly how the seeder produced four roots. A boot
     * hook is the one layer every writer crosses (and it matches the HasAuditLog
     * convention: self-contained model behavior, no provider registration).
     *
     * saving — runs the shared DepartmentHierarchyGuard against the pending
     *   attributes and throws on the first broken invariant. The SAME rules the
     *   form requests validate, owned once and enforced everywhere. Raw
     *   attributes are read (not the cast accessor) so an undefined level
     *   surfaces as our domain exception rather than the enum cast's ValueError.
     *
     * deleting — two refusals:
     *   1. The single root (الإدارة العامة) may never be deleted by ANY path,
     *      soft or hard: deleting the trunk detaches the whole company, and the
     *      children guard would only block it incidentally (an emptied or leaf
     *      root would delete cleanly). Keyed off `level`, per the tier rule —
     *      not `parent_id IS NULL`.
     *   2. A unit with live children may not be SOFT-deleted: restrictOnDelete
     *      only fires on a hard DELETE, but a soft delete is an UPDATE, which
     *      would strand the subtree under a trashed parent — present but
     *      invisible to every default query. children() excludes soft-deleted
     *      rows, so an already-emptied subtree does not block its parent.
     */
    protected static function booted(): void
    {
        static::saving(function (self $department): void {
            $attributes = $department->getAttributes();

            $level = isset($attributes['level']) ? (int) $attributes['level'] : null;
            $parentId = isset($attributes['parent_id']) ? (int) $attributes['parent_id'] : null;
            $subjectId = $department->exists ? (int) $department->getKey() : null;

            $violations = (new DepartmentHierarchyGuard())->violations($level, $parentId, $subjectId);

            if ($violations !== []) {
                throw new DepartmentHierarchyException($violations[0]->message);
            }
        });

        static::deleting(function (self $department): void {
            // The root is undeletable on every path, including force-delete.
            if ($department->level === DepartmentLevel::root()) {
                throw new DepartmentIsRootException();
            }

            // Below is the soft-delete-only children guard; a hard delete is
            // caught by the FK's restrictOnDelete.
            if ($department->isForceDeleting()) {
                return;
            }

            if ($department->children()->exists()) {
                throw new DepartmentHasChildrenException();
            }
        });
    }

    /**
     * The unit this one sits directly under. Null only for the single root
     * (الإدارة العامة).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * The units directly beneath this one — one tier down only, not the whole
     * subtree.
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Employees that belong to this department.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * The employee who manages this department.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    /**
     * Only divisions (إدارة, level 2). A division sits directly under the
     * general administration; it is never a root.
     *
     * @param  Builder<self>  $query
     */
    public function scopeDivisions(Builder $query): void
    {
        $query->where('level', DepartmentLevel::Division->value);
    }

    /**
     * Only sections (قسم).
     *
     * @param  Builder<self>  $query
     */
    public function scopeSections(Builder $query): void
    {
        $query->where('level', DepartmentLevel::Section->value);
    }

    /**
     * Only units at the given tier. The generic form of the two scopes above,
     * so a tier added to DepartmentLevel is queryable without a new scope.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAtLevel(Builder $query, DepartmentLevel $level): void
    {
        $query->where('level', $level->value);
    }
}
