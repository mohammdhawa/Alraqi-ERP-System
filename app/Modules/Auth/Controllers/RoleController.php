<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Requests\AssignRoleRequest;
use App\Modules\Auth\Requests\CreateRoleRequest;
use App\Modules\Auth\Requests\UpdateRoleRequest;
use App\Modules\Auth\Resources\RoleResource;
use App\Modules\Auth\Services\RoleService;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * RoleController
 *
 * Full RBAC administration surface: list/create/update/delete roles and assign
 * a role to a user.
 *
 * ENDPOINTS (prefixed /api/auth):
 *   GET    /api/auth/roles          → index   (permission:auth.roles.view)
 *   POST   /api/auth/roles          → store   (permission:auth.roles.create)
 *   PUT    /api/auth/roles/{role}   → update  (permission:auth.roles.update)
 *   DELETE /api/auth/roles/{role}   → destroy  (permission:auth.roles.delete)
 *   POST   /api/auth/roles/assign   → assign   (permission:auth.roles.update)
 *   POST   /api/auth/roles/unassign → unassign (permission:auth.roles.update)
 */
class RoleController extends Controller
{
    use ApiRespond;

    public function __construct(
        private readonly RoleService $roleService,
    ) {}

    public function index(): JsonResponse
    {
        return $this->success(
            data: RoleResource::collection($this->roleService->list()),
            message: 'تم جلب الأدوار.',
        );
    }

    public function store(CreateRoleRequest $request): JsonResponse
    {
        $role = $this->roleService->create($request->validated());

        return $this->created(
            data: new RoleResource($role),
            message: 'تم إنشاء الدور.',
        );
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $role = $this->roleService->update($role, $request->validated());

        return $this->success(
            data: new RoleResource($role),
            message: 'تم تحديث الدور.',
        );
    }

    public function destroy(Role $role): JsonResponse
    {
        // Safety guard: the seeded 'admin' role is the bootstrap of the whole
        // RBAC system. Deleting it would strip every admin of their access with
        // no way back in, so it is protected.
        if ($role->name === 'admin') {
            return $this->error('لا يمكن حذف دور المدير (admin).', 422);
        }

        $this->roleService->delete($role);

        return $this->success(message: 'تم حذف الدور.');
    }

    public function assign(AssignRoleRequest $request): JsonResponse
    {
        $user = User::findOrFail($request->validated('user_id'));
        $role = Role::findOrFail($request->validated('role_id'));

        $this->roleService->assign($user, $role);

        return $this->success(
            message: "تم إسناد الدور '{$role->name}' إلى {$user->email}.",
        );
    }

    public function unassign(AssignRoleRequest $request): JsonResponse
    {
        $user = User::findOrFail($request->validated('user_id'));
        $role = Role::findOrFail($request->validated('role_id'));

        $this->roleService->unassign($user, $role);

        return $this->success(
            message: "تم سحب الدور '{$role->name}' من {$user->email}.",
        );
    }
}
