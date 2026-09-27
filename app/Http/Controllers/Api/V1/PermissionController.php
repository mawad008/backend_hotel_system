<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\IdentityAccess\Repositories\Contracts\PermissionRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PermissionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class PermissionController extends Controller
{
    public function __construct(private readonly PermissionRepositoryInterface $permissions) {}

    public function index(): JsonResponse
    {
        Gate::authorize('permissions.view');

        return $this->success(PermissionResource::collection($this->permissions->all()));
    }
}
