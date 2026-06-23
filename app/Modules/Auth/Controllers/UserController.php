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

    public function index(): JsonResponse
    {
        return $this->success(
            data: UserResource::collection($this->userService->list()),
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
        // Safety guard: deleting your own account mid-session would lock you out
        // with no way to undo it, so self-deletion is blocked.
        if ($user->id === $request->user()->id) {
            return $this->error('لا يمكنك حذف حسابك الخاص.', 422);
        }

        $this->userService->delete($user);

        return $this->success(message: 'تم حذف المستخدم.');
    }
}
