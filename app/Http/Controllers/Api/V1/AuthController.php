<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\IdentityAccess\Services\AuthService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->attempt(
            $request->validated('email'),
            $request->validated('password'),
        );

        return $this->success([
            'user' => new UserResource($result['user']->load(['role', 'hotels'])),
            'token' => $result['token'],
        ], __('api.login_success'));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return $this->success(null, __('api.logout_success'));
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success(
            new UserResource($request->user()->load(['role.permissions', 'hotels'])),
        );
    }
}
