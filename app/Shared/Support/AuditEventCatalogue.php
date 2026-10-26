<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * AuditEventCatalogue
 *
 * The READER's vocabulary for `audit_logs.event`: which group an event belongs
 * to, and what it is called in Arabic.
 *
 * WHY A CATALOGUE AND NOT AN ENUM OVER THE COLUMN: the column is a free string
 * written by three independent paths (HasAuditLog, AuditLogMiddleware, direct
 * AuditLogService::logAction calls), and it holds rows written before this class
 * existed. A backed enum cast would throw on the first value it did not know —
 * on an APPEND-ONLY table, where the unknown row is exactly the one an
 * investigator needs to see. So this is a lens the reader applies, never a
 * constraint the writer must satisfy.
 *
 * THE CONSEQUENCE, STATED DELIBERATELY: an event key absent from these lists
 * serialises with `event_label: null` and `event_group: null`, and the client
 * renders the raw key. A new backend event must LOOK unfamiliar rather than look
 * like something else — a wrong label on an audit row is worse than no label.
 *
 * ⚠️ WHEN YOU ADD A NEW `logAction()` EVENT, add it here in the same commit, or
 * it arrives in the UI unlabelled and is invisible to the `event_group` filter.
 * AuditEventCatalogueTest asserts that every event name the app actually emits
 * is listed, so the omission fails the build rather than the screen.
 *
 * GROUPS EXIST BECAUSE OF VOLUME. Every authenticated request writes one
 * `api_request` row, so that one event is ~99% of the table and would bury every
 * real change under routine traffic. `event_group` is what lets a client ask for
 * the other 1% in a single parameter.
 */
final class AuditEventCatalogue
{
    /**
     * Field-level change history written by the HasAuditLog trait.
     */
    public const GROUP_MODEL = 'model';

    /**
     * Identity and RBAC actions, written by AuthService / UserService /
     * RoleService. Not tied to a model row, so they carry no auditable.
     */
    public const GROUP_AUTH = 'auth';

    /**
     * The request-level firehose from AuditLogMiddleware.
     */
    public const GROUP_REQUEST = 'request';

    /**
     * HR actions that touch many rows at once and belong to no one row — the
     * upload import's check and real run (EMPLOYEE_IMPORT_UPLOAD_DESIGN.md
     * §4.5). The rows the real run writes are audited by their own models.
     */
    public const GROUP_HR = 'hr';

    /**
     * group => the event names in it. This is the WHOLE vocabulary the app
     * emits today — verified against every `logAction()` call site and the three
     * model events in HasAuditLog.
     *
     * @var array<string, array<int, string>>
     */
    private const GROUPS = [
        self::GROUP_MODEL => [
            'created',
            'updated',
            'deleted',
        ],
        self::GROUP_AUTH => [
            'user_logged_in',
            'user_logged_out',
            'user_logged_out_everywhere',
            'session_revoked',
            'tokens_refreshed',
            'password_changed',
            'all_tokens_revoked',
            'refresh_token_replay_detected',
            'user_created',
            'user_updated',
            'user_deleted',
            'role_created',
            'role_updated',
            'role_deleted',
            'role_assigned',
            'role_unassigned',
        ],
        self::GROUP_REQUEST => [
            'api_request',
        ],
        self::GROUP_HR => [
            'employees_import_checked',
            'employees_import_excluded',
            'employees_import_executed',
        ],
    ];

    /**
     * Arabic display labels — the `event_label` a client shows verbatim (C20).
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'created'                       => 'إنشاء',
        'updated'                       => 'تعديل',
        'deleted'                       => 'حذف',
        'user_logged_in'                => 'تسجيل دخول',
        'user_logged_out'               => 'تسجيل خروج',
        'user_logged_out_everywhere'    => 'تسجيل خروج من جميع الأجهزة',
        'session_revoked'               => 'إنهاء جلسة',
        'tokens_refreshed'              => 'تجديد الجلسة',
        'password_changed'              => 'تغيير كلمة المرور',
        'all_tokens_revoked'            => 'إنهاء جميع الجلسات',
        'refresh_token_replay_detected' => 'اكتشاف إعادة استخدام رمز تجديد',
        'user_created'                  => 'إنشاء مستخدم',
        'user_updated'                  => 'تعديل مستخدم',
        'user_deleted'                  => 'حذف مستخدم',
        'role_created'                  => 'إنشاء دور',
        'role_updated'                  => 'تعديل دور',
        'role_deleted'                  => 'حذف دور',
        'role_assigned'                 => 'إسناد دور',
        'role_unassigned'               => 'إلغاء إسناد دور',
        'api_request'                   => 'طلب واجهة برمجية',
        'employees_import_checked'      => 'فحص ملف استيراد الموظفين',
        'employees_import_excluded'     => 'استبعاد سطور من استيراد الموظفين',
        'employees_import_executed'     => 'استيراد الموظفين من ملف',
    ];

    /**
     * The group names, for the `event_group` filter's allow-list.
     *
     * @return array<int, string>
     */
    public static function groupNames(): array
    {
        return array_keys(self::GROUPS);
    }

    /**
     * Every event name the catalogue knows, for the `event` filter's allow-list.
     *
     * @return array<int, string>
     */
    public static function eventNames(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    /**
     * The group an event belongs to, or null for an event the catalogue does not
     * know. Null is the honest answer — see the class docblock.
     */
    public static function groupOf(?string $event): ?string
    {
        if ($event === null) {
            return null;
        }

        foreach (self::GROUPS as $group => $events) {
            if (in_array($event, $events, true)) {
                return $group;
            }
        }

        return null;
    }

    /**
     * The Arabic label for an event, or null for an unknown key.
     */
    public static function labelOf(?string $event): ?string
    {
        return $event === null ? null : (self::LABELS[$event] ?? null);
    }

    /**
     * Every event name in the given groups — what the `event_group` filter
     * expands to before it reaches the WHERE clause.
     *
     * The filter is resolved to event names rather than stored as a column of
     * its own (P1, C25): the group is derived from the event, so a column
     * holding it would be the same fact twice and could drift from this list.
     * The `event` column is indexed, so the expanded IN is the cheap form.
     *
     * @param  array<int, string>  $groups
     * @return array<int, string>
     */
    public static function eventsIn(array $groups): array
    {
        $events = [];

        foreach ($groups as $group) {
            foreach (self::GROUPS[$group] ?? [] as $event) {
                $events[] = $event;
            }
        }

        return $events;
    }
}
