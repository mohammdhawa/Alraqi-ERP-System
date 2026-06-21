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
        $notifications = $this->notificationService->listForUser($request->user());

        return $this->success(
            data: NotificationResource::collection($notifications),
            message: 'Notifications retrieved.',
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return $this->success(
            data: ['unread_count' => $this->notificationService->unreadCount($request->user())],
            message: 'Unread notification count retrieved.',
        );
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $marked = $this->notificationService->markAsRead($request->user(), (int) $notification);

        return $this->success(
            data: new NotificationResource($marked),
            message: 'Notification marked as read.',
        );
    }
}
