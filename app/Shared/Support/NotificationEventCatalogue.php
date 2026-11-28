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

    // Leave — to the employee whose approved leave HR cut short or cancelled
    // because they came back to work (legacy's leave_returned_to_work).
    public const LEAVE_RETURNED_TO_WORK = 'leave.returned_to_work';

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

    // Work shifts — to the employee whose shift HR changed (piece 2, spec §5.4).
    public const SHIFT_CHANGE_RECORDED = 'shift.change_recorded';

    public const SHIFT_CHANGE_REMOVED = 'shift.change_removed';

    // Duty exchanges — to the requester and the partner (piece 2, spec §6.5).
    public const SHIFT_SWAP_SUBMITTED = 'shift_swap.submitted';

    public const SHIFT_SWAP_REQUESTED = 'shift_swap.requested';

    public const SHIFT_SWAP_WITHDRAWN = 'shift_swap.withdrawn';

    public const SHIFT_SWAP_APPROVED = 'shift_swap.approved';

    public const SHIFT_SWAP_REJECTED = 'shift_swap.rejected';

    public const SHIFT_SWAP_CANCELLED = 'shift_swap.cancelled';

    // Documents between managers — to the creator, and to the system
    // administrators when a unit has no stamp.
    public const DOCUMENT_APPROVED = 'document.approved';

    public const DOCUMENT_REJECTED = 'document.rejected';

    public const DOCUMENT_REJECTED_BY_MANAGER = 'document.rejected_by_manager';

    public const DOCUMENT_STAMP_MISSING = 'document.stamp_missing';

    // Documents — to the creator, when the system administrator closes a
    // stuck document «لم يُستكمل» (documents design §7.8).
    public const DOCUMENT_NOT_COMPLETED = 'document.not_completed';

    // Documents to employees — to the recipient, when one of HR's managers
    // sends them a document to sign (documents to employees, piece 4).
    public const DOCUMENT_AWAITING_SIGNATURE = 'document.awaiting_signature';

    // Documents to employees — to the sender, when the employee signs
    // «قرأت ووافقت» or refuses with a reason.
    public const DOCUMENT_SIGNED = 'document.signed';

    public const DOCUMENT_REFUSED = 'document.refused';

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
        self::LEAVE_RETURNED_TO_WORK       => 'تم تعديل إجازتك لعودتك إلى العمل',
        self::APPROVAL_STEP_PENDING        => 'طلب بانتظار موافقتك',
        self::APPROVAL_STEP_ASSIGNED       => 'أصبحت ضمن مسار اعتماد',
        self::ATTENDANCE_CHECKOUT_REMINDER => 'تذكير بتسجيل الانصراف',
        self::ATTENDANCE_MISSING_CHECKOUT  => 'لم يُسجَّل الانصراف',
        self::ATTENDANCE_RECORD_REJECTED   => 'تم رفض سجل الحضور',
        self::ATTENDANCE_RECORD_UNREJECTED => 'تم إلغاء رفض سجل الحضور',
        self::SHIFT_CHANGE_RECORDED        => 'تعديل الوردية',
        self::SHIFT_CHANGE_REMOVED         => 'إلغاء تعديل الوردية',
        self::SHIFT_SWAP_SUBMITTED         => 'تم تقديم طلب تبديل المناوبة',
        self::SHIFT_SWAP_REQUESTED         => 'طلب تبديل مناوبة معك',
        self::SHIFT_SWAP_WITHDRAWN         => 'سحب طلب تبديل المناوبة',
        self::SHIFT_SWAP_APPROVED          => 'تمت الموافقة على تبديل المناوبة',
        self::SHIFT_SWAP_REJECTED          => 'تم رفض تبديل المناوبة',
        self::SHIFT_SWAP_CANCELLED         => 'تم إلغاء تبديل المناوبة',
        self::DOCUMENT_APPROVED            => 'تمت الموافقة على المستند',
        self::DOCUMENT_REJECTED            => 'تم رفض المستند',
        self::DOCUMENT_REJECTED_BY_MANAGER => 'رفض مدير المستند',
        self::DOCUMENT_STAMP_MISSING       => 'ختم وحدة غير مرفوع',
        self::DOCUMENT_NOT_COMPLETED       => 'لم يُستكمل المستند',
        self::DOCUMENT_AWAITING_SIGNATURE  => 'مستند بانتظار توقيع موظف',
        self::DOCUMENT_SIGNED              => 'وقّع موظف على مستند',
        self::DOCUMENT_REFUSED             => 'رفض موظف مستنداً',
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
