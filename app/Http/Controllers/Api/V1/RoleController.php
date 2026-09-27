<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\IdentityAccess\Models\Role;
use App\Domain\IdentityAccess\Services\RoleService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Role\StoreRoleRequest;
use App\Http\Requests\Api\V1\Role\UpdateRoleRequest;
use App\Http\Resources\V1\RoleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        return $this->success(RoleResource::collection($this->roles->list()));
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $this->authorize('create', Role::class);

        $role = $this->roles->create($request->validated(), $request->user());

        return $this->success(new RoleResource($role), __('api.created'), 201);
    }

    public function show(Request $request, Role $role): JsonResponse
    {
        $this->authorize('view', $role);

        return $this->success(new RoleResource($role->load('permissions')->loadCount('users')));
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $this->authorize('update', $role);

        $role = $this->roles->update($role, $request->validated(), $request->user());

        return $this->success(new RoleResource($role), __('api.updated'));
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->authorize('delete', $role);

        $this->roles->delete($role, $request->user());

        return $this->success(null, __('api.deleted'));
    }
}
