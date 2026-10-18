<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
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
            'user' => new UserResource($this->withSessionRelations($result['user'])),
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
            new UserResource($this->withSessionRelations($request->user())),
        );
    }

    /**
     * A Group Owner has no user_hotel_access rows — they reach every hotel
     * by bypass (HotelAccessService::canAccessHotel) — so expose every hotel
     * as their session hotels for the dashboard's hotel picker.
     */
    private function withSessionRelations(User $user): User
    {
        $user->load('role.permissions');

        if ($user->isGroupOwner()) {
            return $user->setRelation('hotels', Hotel::query()->orderBy('id')->get());
        }

        return $user->load('hotels');
    }
}
