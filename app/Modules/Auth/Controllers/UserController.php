<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Requests\CreateUserRequest;
use App\Modules\Auth\Requests\UpdateUserRequest;
use App\Modules\Auth\Resources\UserResource;
use App\Modules\Auth\Services\UserService;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * UserController
 *
 * Admin user-management surface: list/create/update/delete user accounts.
 * Role grants are handled separately by RoleController (assign/unassign).
 *
 * ENDPOINTS (prefixed /api/auth):
 *   GET    /api/auth/users          → index   (permission:auth.users.view)
 *   POST   /api/auth/users          → store   (permission:auth.users.create)
 *   PUT    /api/auth/users/{user}   → update  (permission:auth.users.update)
 *   DELETE /api/auth/users/{user}   → destroy (permission:auth.users.delete)
 */
class UserController extends Controller
{
    use ApiRespond;

    public function __construct(
        private readonly UserService $userService,
    ) {}

    /**
     * Built with paginated(), never success() (C21). Passing a ResourceCollection
     * to success() nests it under `data` and silently drops the metadata — which
     * is exactly what this endpoint used to do, leaving clients unable to page.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->paginated(
            data: UserResource::collection($this->userService->paginate($this->perPage($request))),
            message: 'تم جلب المستخدمين.',
        );
    }

    public function store(CreateUserRequest $request): JsonResponse
    {
        $user = $this->userService->create($request->validated());

        return $this->created(
            data: new UserResource($user),
            message: 'تم إنشاء المستخدم.',
        );
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $user = $this->userService->update($user, $request->validated());

        return $this->success(
            data: new UserResource($user),
            message: 'تم تحديث المستخدم.',
        );
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        // The self-deletion guard lives in UserService::delete(), which every
        // caller crosses (C4) — this only reports who is asking, which §3
        // explicitly permits a controller to do. No business `if` here (C2).
        $this->userService->delete($user, $request->user()?->getKey());

        return $this->success(message: 'تم حذف المستخدم.');
    }
}
