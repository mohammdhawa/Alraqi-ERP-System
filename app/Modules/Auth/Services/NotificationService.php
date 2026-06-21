<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\Notification;
use App\Modules\Auth\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * NotificationService
 *
 * Business logic for a user's own notifications. Every operation is scoped to
 * the owning user (via the user's relationship), so one user can never read or
 * mutate another user's notifications — the scoping is the authorization.
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
}
