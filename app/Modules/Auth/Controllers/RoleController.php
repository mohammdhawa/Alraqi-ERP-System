<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Requests\AssignRoleRequest;
use App\Modules\Auth\Requests\CreateRoleRequest;
use App\Modules\Auth\Requests\UnassignRoleRequest;
use App\Modules\Auth\Requests\UpdateRoleRequest;
use App\Modules\Auth\Resources\RoleResource;
use App\Modules\Auth\Resources\UserResource;
use App\Modules\Auth\Services\RoleService;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
 *   POST   /api/auth/roles/assign   → assign   (permission:auth.roles.assign)
 *   POST   /api/auth/roles/unassign → unassign (permission:auth.roles.assign)
 */
class RoleController extends Controller
{
    use ApiRespond;

    public function __construct(
        private readonly RoleService $roleService,
    ) {}

    /**
     * Built with paginated(), never success() (C21) — see UserController::index.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->paginated(
            data: RoleResource::collection($this->roleService->paginate($this->perPage($request))),
            message: 'تم جلب الأدوار.',
        );
    }

    public function store(CreateRoleRequest $request): JsonResponse
    {
        $role = $this->roleService->create($request->validated(), $request->user());

        return $this->created(
            data: new RoleResource($role),
            message: 'تم إنشاء الدور.',
        );
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $role = $this->roleService->update($role, $request->validated(), $request->user());

        return $this->success(
            data: new RoleResource($role),
            message: 'تم تحديث الدور.',
        );
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        // System roles (e.g. super_admin) are the RBAC bootstrap and cannot be
        // deleted. That guard lives in RoleService — it throws a self-rendering
        // SystemRoleException (409) that covers every delete path, not just this
        // controller — so nothing is needed here beyond delegating. So does the
        // GAP-16 bound on what the caller may take away from the role's holders.
        $this->roleService->delete($role, $request->user());

        return $this->success(message: 'تم حذف الدور.');
    }

    /**
     * Ids go to the service; it does the loading (C3). This action used to call
     * User::findOrFail() and Role::findOrFail() itself — repository work in a
     * handler, which also produced a 404 whose message leaked the model class.
     *
     * The response now carries the user's resulting role set, so a client does
     * not need a follow-up request to refresh what it just changed.
     *
     * The caller is passed as the grantor: the service refuses a grant that
     * reaches further than the caller's own `auth.roles.assign` (GAP-3; split
     * out of `auth.roles.update` by GAP-15).
     */
    public function assign(AssignRoleRequest $request): JsonResponse
    {
        $user = $this->roleService->assign(
            (int) $request->validated('user_id'),
            (int) $request->validated('role_id'),
            $request->scopeType() ?? ScopeType::Company,
            $request->scopeDepartmentId(),
            $request->user(),
        );

        return $this->success(
            data: new UserResource($user),
            message: 'تم إسناد الدور إلى المستخدم.',
        );
    }

    public function unassign(UnassignRoleRequest $request): JsonResponse
    {
        $user = $this->roleService->unassign(
            (int) $request->validated('user_id'),
            (int) $request->validated('role_id'),
            $request->scopeType(),
            $request->scopeDepartmentId(),
            $request->user(),
        );

        return $this->success(
            data: new UserResource($user),
            message: 'تم سحب الدور من المستخدم.',
        );
    }
}
