<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Services\UserService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\User\StoreUserRequest;
use App\Http\Requests\Api\V1\User\UpdateUserRequest;
use App\Http\Resources\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        return $this->success(UserResource::collection($this->users->list()));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $user = $this->users->create($request->validated(), $request->user());

        return $this->success(new UserResource($user), __('api.created'), 201);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return $this->success(new UserResource($user->load(['role', 'hotels'])));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $user = $this->users->update($user, $request->validated(), $request->user());

        return $this->success(new UserResource($user), __('api.updated'));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        $this->users->delete($user, $request->user());

        return $this->success(null, __('api.deleted'));
    }
}
