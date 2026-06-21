<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Requests\AssignRoleRequest;
use App\Modules\Auth\Resources\RoleResource;
use App\Modules\Auth\Services\RoleService;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * RoleController
 *
 * Minimal RBAC administration surface: list roles and assign a role to a user.
 * Full role CRUD (create/edit/delete roles, edit their permission sets) is
 * intentionally deferred — for now roles/permissions are managed by seeders,
 * and admins only need to grant existing roles to users.
 *
 * ENDPOINTS (prefixed /api/auth):
 *   GET  /api/auth/roles         → index   (permission:auth.roles.view)
 *   POST /api/auth/roles/assign  → assign  (permission:auth.roles.update)
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
            message: 'Roles retrieved.',
        );
    }

    public function assign(AssignRoleRequest $request): JsonResponse
    {
        $user = User::findOrFail($request->validated('user_id'));
        $role = Role::findOrFail($request->validated('role_id'));

        $this->roleService->assign($user, $role);

        return $this->success(
            message: "Role '{$role->name}' assigned to {$user->email}.",
        );
    }
}
