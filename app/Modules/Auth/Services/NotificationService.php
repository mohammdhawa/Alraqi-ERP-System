<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\Notification;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Departments\Models\Department;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

/**
 * NotificationService
 *
 * Two responsibilities:
 *
 * READ SIDE — a user's own notifications. Every operation is scoped to the
 * owning user (via the user's relationship), so one user can never read or
 * mutate another user's notifications — the scoping is the authorization.
 *
 * SEND SIDE (the fan-out layer, ratified in erp-phase1-architecture.md §7.2) —
 * the ONLY way notification rows are created. Three explicit entry points:
 *
 *   sendToUser()        one recipient.
 *   sendToDepartment()  every current member of an organizational unit AND its
 *                       subtree (see the method docblock for the scope rule).
 *   sendToRole()        every current holder of a role.
 *
 * SNAPSHOT SEMANTICS: department/role sends expand to individual users AT SEND
 * TIME and write one plain per-user row each. The audience is frozen at that
 * moment — someone who joins the department or gains the role later does NOT
 * retroactively receive the notification. This is why the storage schema never
 * models a department or role as a recipient: the expansion happens here, once.
 *
 * ELIGIBILITY (uniform across all three): a recipient is a user account that
 * EXISTS and has `is_active = true`; the department path additionally requires
 * a non-soft-deleted employee row linking the person to a unit. An employee
 * with no login can never receive a row. Empty audiences are valid and silent
 * (0 rows, no exception).
 *
 * DELIBERATELY NOT Laravel's notification system: `$user->notify()` and the
 * stock `database` channel are unused by design — the stock UUID/notifiable/
 * JSON-data table does not exist in this schema. All creation goes through
 * this service. Physical transport (mail, FCM, websockets) is a separate,
 * later concern; this layer only creates the stored rows.
 */
class NotificationService
{
    /**
     * The authenticated user's notifications, newest first, paginated.
     *
     * @return LengthAwarePaginator<Notification>
     */
    public function listForUser(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return $user->notifications()
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Count the user's unread notifications.
     */
    public function unreadCount(User $user): int
    {
        return $user->notifications()
            ->where('is_read', false)
            ->count();
    }

    /**
     * Mark one of the user's notifications as read.
     *
     * Scoped to the user's own notifications: an id that belongs to someone
     * else (or doesn't exist) yields a 404, never a cross-user update.
     */
    public function markAsRead(User $user, int $notificationId): Notification
    {
        /** @var Notification $notification */
        $notification = $user->notifications()->findOrFail($notificationId);

        // Idempotent: only write when the state actually changes.
        if (! $notification->is_read) {
            $notification->update(['is_read' => true]);
        }

        return $notification;
    }

    /**
     * Send a notification to a single user.
     *
     * The base case of the fan-out layer: at most one row. An inactive (or
     * nonexistent) account receives nothing — silently, per the uniform
     * eligibility rule in the class docblock.
     *
     * @return int rows created (0 or 1)
     */
    public function sendToUser(
        User|int $user,
        string $title,
        ?string $body = null,
        ?string $type = null,
        ?Model $reference = null,
    ): int {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        $recipients = User::query()
            ->whereKey($userId)
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        return $this->deliver($recipients, $title, $body, $type, $reference);
    }

    /**
     * Send a notification to every current member of an organizational unit.
     *
     * SCOPE — SUBTREE-INCLUSIVE: "the unit" means the unit AND every unit
     * beneath it. Sending to a division (إدارة) reaches its directly-attached
     * staff plus every member of its sections; sending to the root reaches the
     * whole company. Anything else would make notifying "الإدارة الهندسية"
     * silently skip everyone in its sections, which is never what the sender
     * means. Resolution walks the parent_id tree level by level (one query per
     * tier, bounded by DepartmentLevel) — see departmentSubtreeIds().
     *
     * RECIPIENTS are a snapshot of the members at this moment: users whose
     * (non-soft-deleted) employee row sits in the subtree and whose account is
     * active. Employees without a login get nothing. A soft-deleted or unknown
     * department is an empty audience: 0 rows, no error.
     *
     * Employment status (active/inactive/terminated) is deliberately NOT
     * consulted — whether a person may use the system is the account's
     * is_active flag, one fact in one place.
     *
     * @return int rows created
     */
    public function sendToDepartment(
        Department|int $department,
        string $title,
        ?string $body = null,
        ?string $type = null,
        ?Model $reference = null,
    ): int {
        $departmentId = $department instanceof Department ? (int) $department->getKey() : $department;

        // Trashed or unknown target => empty audience (the SoftDeletes scope
        // makes a trashed unit read as missing here).
        if (! Department::query()->whereKey($departmentId)->exists()) {
            return 0;
        }

        $unitIds = $this->departmentSubtreeIds($departmentId);

        // One row per matching user id — structurally duplicate-free (a user
        // links to exactly one employee via the unique users.employee_id), and
        // whereHas applies Employee's SoftDeletes scope, so a trashed employee
        // never carries a notification to their account.
        $recipients = User::query()
            ->where('is_active', true)
            ->whereHas('employee', fn ($query) => $query->whereIn('department_id', $unitIds))
            ->pluck('id')
            ->all();

        return $this->deliver($recipients, $title, $body, $type, $reference);
    }

    /**
     * Send a notification to every current holder of a role.
     *
     * Same snapshot semantics: the role's user list is expanded now; users
     * granted the role later are not retroactively notified. Only active
     * accounts receive rows. An unknown role is an empty audience (0 rows).
     *
     * @return int rows created
     */
    public function sendToRole(
        Role|int $role,
        string $title,
        ?string $body = null,
        ?string $type = null,
        ?Model $reference = null,
    ): int {
        $roleId = $role instanceof Role ? (int) $role->getKey() : $role;

        $recipients = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereKey($roleId))
            ->pluck('id')
            ->all();

        return $this->deliver($recipients, $title, $body, $type, $reference);
    }

    /**
     * The ids of a unit and every unit beneath it (subtree-inclusive).
     *
     * Iterative frontier walk over parent_id: one query per tier, so the cost
     * is O(depth) — bounded by DepartmentLevel (3 today) and unchanged if a
     * deeper tier is ever added. The visited set guards against a pathological
     * cycle ever reaching the data (mirroring DepartmentHierarchyGuard's own
     * defensive walk). Soft-deleted units are excluded by the default scope.
     *
     * @return list<int>
     */
    private function departmentSubtreeIds(int $departmentId): array
    {
        $collected = [$departmentId => true];
        $frontier = [$departmentId];

        while ($frontier !== []) {
            $childIds = Department::query()
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->all();

            $frontier = [];

            foreach ($childIds as $childId) {
                $childId = (int) $childId;

                if (! isset($collected[$childId])) {
                    $collected[$childId] = true;
                    $frontier[] = $childId;
                }
            }
        }

        return array_keys($collected);
    }

    /**
     * Write one notification row per recipient id. The single place a
     * notification row is born: the public sendTo* methods differ only in how
     * they resolve the audience, never in how a row is written.
     *
     * De-duplicates defensively (the resolvers are already duplicate-free, but
     * a user must never receive the same notification twice even if a future
     * combined send surfaces them via two paths). Empty audiences are a valid
     * no-op. Bulk insert is safe because the Notification model deliberately
     * has no model events (no HasAuditLog — see the model docblock).
     *
     * @param  array<int, int|string>  $userIds
     * @return int rows created
     */
    private function deliver(
        array $userIds,
        string $title,
        ?string $body,
        ?string $type,
        ?Model $reference,
    ): int {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        if ($userIds === []) {
            return 0;
        }

        $now = now();

        Notification::query()->insert(array_map(fn (int $userId): array => [
            'user_id'        => $userId,
            'title'          => $title,
            'body'           => $body,
            'type'           => $type,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id'   => $reference?->getKey(),
            'is_read'        => false,
            'created_at'     => $now,
            'updated_at'     => $now,
        ], $userIds));

        return count($userIds);
    }
}
