<?php

declare(strict_types=1);

namespace App\Modules\Auth\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * AuditTargetStub
 *
 * The ONE shape an audit row's target takes on the wire:
 *
 *   "auditable_type":  "employee",
 *   "auditable_label": "موظف",
 *   "auditable_id":    42,
 *   "auditable":       { "id": 42, "label": "أحمد خالد الحمصي (EMP-00042)" }
 *
 * Built in the same spirit as HR's EmployeeStub — one place decides what an
 * embedded reference looks like, so two endpoints cannot disagree about it (P1).
 *
 * ──────────────────────────────────────────────────────────────────────────
 * THE KEYS ARE THE MORPH ALIASES, NOT A SECOND NAMING
 * ──────────────────────────────────────────────────────────────────────────
 * `employee_assignment` and `employee_warning` are spelled in full, because
 * those are the strings the enforced morph map stores in `auditable_type` and
 * publishes as `reference.type` on notifications. The audit work order proposed
 * the shorter `assignment` / `warning`; inventing them here would mean the same
 * type had two public names depending on which endpoint you asked, and the
 * stored value would match neither. Renaming a morph alias is a data migration,
 * not an edit — so the stored name wins and the contract is amended to it.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * IT NEVER QUERIES
 * ──────────────────────────────────────────────────────────────────────────
 * from() takes the RESOLVED morphTo relation, eager-loaded by AuditTrailService.
 * `null` therefore means "there is no such record to name" — an action-level
 * event with no target, or a target that has been soft-deleted, which to a
 * client that cannot fetch it either way is the same statement. The KEY IS
 * NEVER OMITTED (C31): an absent key means "you may not read this" (C11), and a
 * missing row must not borrow that meaning.
 */
final class AuditTargetStub
{
    /**
     * Morph alias => the Arabic name of the KIND of record. This is
     * `auditable_label` — the type's name, not the row's.
     *
     * An alias absent from this map serialises as `auditable_label: null` and
     * the client renders the raw key. Same rule as the event vocabulary: a type
     * nobody has labelled yet must look unfamiliar rather than look like
     * something else.
     *
     * ⚠️ IT MUST COVER EVERY ALIAS IN THE MORPH MAP, and AuditTargetStubTest
     * fails the build when it does not — the null fallback is there for data
     * already in the table, not as a licence to ship an unlabelled type. The
     * FILTER allow-list is typeKeys(), which reads the morph map directly, so a
     * missing label costs a label and never costs the drill-down.
     *
     * @var array<string, string>
     */
    public const TYPE_LABELS = [
        'employee'            => 'موظف',
        'employee_assignment' => 'سجل تعيين',
        'employee_document'   => 'وثيقة موظف',
        'employee_warning'    => 'إنذار',
        'job_title'           => 'مسمى وظيفي',
        'department'          => 'وحدة تنظيمية',
        'user'                => 'مستخدم',
        'role'                => 'دور',
        'permission'          => 'صلاحية',
        'attendance_record'   => 'سجل حضور',
    ];

    /**
     * Every type key a client may filter on — read from the ENFORCED MORPH MAP,
     * never from TYPE_LABELS.
     *
     * WHY NOT `array_keys(TYPE_LABELS)`, WHICH IS THE SAME LIST TODAY: the two
     * lists answer different questions, and only one of them is the truth about
     * the column. The morph map decides what `auditable_type` actually holds —
     * MorphMapTest forces every audited model into it, so rows start carrying a
     * new alias the moment such a model ships. TYPE_LABELS is a display
     * courtesy maintained by hand. Validating the filter against the labels
     * therefore made a legitimate STORED value a 422: the newest rows in the
     * table were the ones the drill-down could not reach, and the caller was
     * told their type did not exist. An unlabelled type merely looks unfamiliar;
     * an unfilterable one is invisible.
     *
     * AuditTargetStubTest asserts every mapped alias is labelled, so the two
     * cannot drift apart silently in the other direction either.
     *
     * @return array<int, string>
     */
    public static function typeKeys(): array
    {
        return array_keys(Relation::morphMap());
    }

    /**
     * The Arabic name of a type, or null for an alias this map does not know.
     */
    public static function typeLabel(?string $alias): ?string
    {
        return $alias === null ? null : (self::TYPE_LABELS[$alias] ?? null);
    }

    /**
     * The embedded target object, or null when there is no live record to name.
     *
     * @return array{id: int, label: string}|null
     */
    public static function from(?Model $target, ?string $alias): ?array
    {
        if ($target === null) {
            return null;
        }

        $id = (int) $target->getKey();

        return [
            'id'    => $id,
            'label' => self::label($target, $alias) ?? self::fallbackLabel($alias, $id),
        ];
    }

    /**
     * The row's own identifying text, keyed on the public alias rather than on
     * the class.
     *
     * WHY THE ALIAS AND NOT `instanceof`: the alias is already the public
     * contract for this column, and matching on it keeps the Auth module from
     * importing a model class out of HR and Departments just to read one
     * attribute off it. The attributes read here are the ones that identify the
     * row to a person — nothing gated, nothing that costs another query.
     *
     * `employee` carries the staff number as well as the name because at this
     * headcount two people genuinely share الاسم الثلاثي, so a name alone names
     * nobody — the same reasoning that put `employee_number` in EmployeeStub.
     *
     * `user` is identified by email: the account holds no name of its own, and
     * resolving one would mean walking to `employees` for a label.
     */
    private static function label(Model $target, ?string $alias): ?string
    {
        return match ($alias) {
            'employee'   => trim(sprintf('%s (%s)', $target->full_name, $target->employee_number)),
            'department',
            'job_title'  => $target->name,
            'user'       => $target->email,
            'role',
            'permission' => $target->label ?: $target->name,
            // employee_assignment / employee_warning / employee_document carry
            // no single identifying string — a posting is a department, a title
            // and a date range, and a warning is a type and a date. Naming one
            // would mean inventing a sentence, so they take the fallback below,
            // which says exactly what it knows: which kind of record, and which.
            default      => null,
        };
    }

    /**
     * "سجل تعيين #17" — the honest minimum when there is no identifying text,
     * and the last resort for a type with no Arabic label at all.
     */
    private static function fallbackLabel(?string $alias, int $id): string
    {
        return sprintf('%s #%d', self::typeLabel($alias) ?? ($alias ?? 'سجل'), $id);
    }
}
