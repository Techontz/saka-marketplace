<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Enums\Permission as PermissionEnum;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

/**
 * Role and permission introspection.
 *
 * Read-mostly by design. The role/permission MATRIX lives in
 * Permission::forRole() and is applied by a seeder — editing it in code and
 * re-seeding is reviewable and reproducible, whereas letting an admin invent
 * permission sets at runtime makes an environment's authorization
 * unreconstructable from the repository.
 *
 * Which roles a USER holds is fully manageable (UserController::updateRoles).
 */
class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAssign($request);

        $roles = Role::with('permissions:id,name')->orderBy('level')->get();

        return response()->json([
            'data' => $roles->map(fn (Role $role) => [
                'name' => $role->name,
                'label' => $role->label(),
                'description' => $role->description,
                'level' => $role->level,
                'is_assignable' => (bool) $role->is_assignable,
                'permissions' => $role->permissions->pluck('name')->all(),
                'users_count' => $role->users()->count(),
            ])->all(),
        ]);
    }

    /** The full permission catalogue, grouped, for building an admin UI. */
    public function permissions(Request $request): JsonResponse
    {
        $this->authorizeAssign($request);

        $grouped = Permission::orderBy('name')->get()
            ->groupBy(fn (Permission $p) => explode('.', $p->name)[0])
            ->map(fn ($items) => $items->pluck('name')->values()->all());

        return response()->json(['data' => $grouped]);
    }

    private function authorizeAssign(Request $request): void
    {
        if (! $request->user()?->hasPermission(PermissionEnum::UserAssignRole)) {
            throw ApiException::forbidden();
        }
    }
}
