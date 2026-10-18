<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * NotificationEventCatalogue
 *
 * The vocabulary of `notifications.type`: which events the app notifies on,
 * and what each is called in Arabic (work order §4.3, mapping §8,
 * EMPLOYEE_APP_REQUIREMENTS.md §5.7).
 *
 * THE AuditEventCatalogue PATTERN, for the same reason: `type` is a free
 * string column, rows written before an event was catalogued must still read,
 * and an unknown type serialises with `type_label: null` so the client shows
 * the raw key — a wrong label is worse than none. A lens the reader applies,
 * never a constraint on the writer.
 *
 * ⚠️ WHEN YOU SEND A NEW TYPE, add it here in the same commit, and pass it as
 * one of these constants. NotificationEventCatalogueTest finds every `type:`
 * of a sendTo…() call under app/ and fails on one this class does not list.
 *
 * The names are `subject.event`, dotted, so the app can route a tap by prefix
 * (a `leave.*` notification opens the leave screen) and the reference
 * (`reference_type` / `reference_id`) picks the row.
 *
 * Only the v1 events are here. The spec's other triggers — salary deposited,
 * salary updated, circular, contract ending — have no source until payroll,
 * documents and contracts exist; they are added with the code that sends them.
 */
final class NotificationEventCatalogue
{
    // Leave — to the requester (or the employee an excuse concerns).
    public const LEAVE_SUBMITTED = 'leave.submitted';

    public const LEAVE_APPROVED = 'leave.approved';

    public const LEAVE_REJECTED = 'leave.rejected';

    public const LEAVE_CANCELLED = 'leave.cancelled';

    public const LEAVE_EXCUSE_FILED = 'leave.excuse_filed';

    public const LEAVE_EXCUSE_CANCELLED = 'leave.excuse_cancelled';

    // Approvals — to the approver whose step just became current.
    public const APPROVAL_STEP_PENDING = 'approval.step_pending';

    // Approvals — to someone a LATER step was reassigned to: on the chain,
    // not yet their turn (legacy's «أصبحت ضمن مسار اعتماد…»).
    public const APPROVAL_STEP_ASSIGNED = 'approval.step_assigned';

    // Attendance — to the employee.
    public const ATTENDANCE_CHECKOUT_REMINDER = 'attendance.checkout_reminder';

    public const ATTENDANCE_MISSING_CHECKOUT = 'attendance.missing_checkout';

    public const ATTENDANCE_RECORD_REJECTED = 'attendance.record_rejected';

    public const ATTENDANCE_RECORD_UNREJECTED = 'attendance.record_unrejected';

    /**
     * Arabic display labels — the `type_label` a client shows verbatim (C20).
     *
     * @var array<string, string>
     */
    private const LABELS = [
        self::LEAVE_SUBMITTED              => 'تم تقديم طلب الإجازة',
        self::LEAVE_APPROVED               => 'تمت الموافقة على الإجازة',
        self::LEAVE_REJECTED               => 'تم رفض الإجازة',
        self::LEAVE_CANCELLED              => 'تم إلغاء الإجازة',
        self::LEAVE_EXCUSE_FILED           => 'تم تسجيل عذر',
        self::LEAVE_EXCUSE_CANCELLED       => 'تم إلغاء العذر',
        self::APPROVAL_STEP_PENDING        => 'طلب بانتظار موافقتك',
        self::APPROVAL_STEP_ASSIGNED       => 'أصبحت ضمن مسار اعتماد',
        self::ATTENDANCE_CHECKOUT_REMINDER => 'تذكير بتسجيل الانصراف',
        self::ATTENDANCE_MISSING_CHECKOUT  => 'لم يُسجَّل الانصراف',
        self::ATTENDANCE_RECORD_REJECTED   => 'تم رفض سجل الحضور',
        self::ATTENDANCE_RECORD_UNREJECTED => 'تم إلغاء رفض سجل الحضور',
    ];

    /**
     * Every type the catalogue knows.
     *
     * @return array<int, string>
     */
    public static function eventNames(): array
    {
        return array_keys(self::LABELS);
    }

    /**
     * The Arabic label for a type, or null for an unknown one.
     */
    public static function labelOf(?string $type): ?string
    {
        return $type === null ? null : (self::LABELS[$type] ?? null);
    }
}
