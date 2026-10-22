<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Resources\NotificationResource;
use App\Modules\Auth\Services\NotificationService;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * NotificationController
 *
 * Thin controller for a user's own notifications. Delegates to
 * NotificationService, which scopes every operation to the authenticated user
 * (so authorization is ownership — no permission middleware is needed and one
 * user can never touch another's notifications).
 *
 * ENDPOINTS (prefixed /api/auth/notifications):
 *   GET  /api/auth/notifications                → index        (list mine)
 *   GET  /api/auth/notifications/unread-count    → unreadCount
 *   POST /api/auth/notifications/{notification}/read → markRead
 */
class NotificationController extends Controller
{
    use ApiRespond;

    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $notifications = $this->notificationService->listForUser(
            $request->user(),
            $this->perPage($request),
        );

        return $this->paginated(
            data: NotificationResource::collection($notifications),
            message: 'تم جلب الإشعارات.',
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return $this->success(
            data: ['unread_count' => $this->notificationService->unreadCount($request->user())],
            message: 'تم جلب عدد الإشعارات غير المقروءة.',
        );
    }

    /**
     * The id is passed to the service rather than route-model bound, and that is
     * deliberate. Binding would load the row for ANY caller and leave ownership
     * to be re-checked afterwards; the service instead resolves it through
     * $user->notifications(), so someone else's id is indistinguishable from a
     * nonexistent one and yields a 404 — which is what §6 requires of row
     * scoping, since a 403 would confirm the row exists. The route constrains
     * the segment to digits, so a non-numeric id never reaches here.
     */
    /**
     * POST /api/auth/notifications/read-all
     */
    public function markAllRead(Request $request): JsonResponse
    {
        return $this->success(
            data: ['marked' => $this->notificationService->markAllAsRead($request->user())],
            message: 'تم وضع علامة "مقروء" على جميع الإشعارات.',
        );
    }

    public function markRead(Request $request, int $notification): JsonResponse
    {
        $marked = $this->notificationService->markAsRead($request->user(), $notification);

        return $this->success(
            data: new NotificationResource($marked),
            message: 'تم وضع علامة "مقروء" على الإشعار.',
        );
    }
}
