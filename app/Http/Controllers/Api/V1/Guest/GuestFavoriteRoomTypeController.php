<?php

namespace App\Http\Controllers\Api\V1\Guest;

use App\Domain\Discovery\Services\GuestFavoriteRoomTypeService;
use App\Domain\Reservation\Models\Guest;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Guest\GuestFavoriteRoomTypeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The guest's favourite rooms (`/guest/favorites/rooms`, keyed by room type).
 * The guest is always the token owner; save/remove are idempotent.
 */
class GuestFavoriteRoomTypeController extends Controller
{
    public function __construct(
        private readonly GuestFavoriteRoomTypeService $favorites,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->success(GuestFavoriteRoomTypeResource::collection(
            $this->favorites->listFor($this->guest($request)),
        ));
    }

    public function store(Request $request, int $roomType): JsonResponse
    {
        $favorite = $this->favorites->add($this->guest($request), $roomType);

        return $this->success(new GuestFavoriteRoomTypeResource($favorite), __('api.updated'));
    }

    public function destroy(Request $request, int $roomType): JsonResponse
    {
        $this->favorites->remove($this->guest($request), $roomType);

        return $this->success(null, __('api.deleted'));
    }

    private function guest(Request $request): Guest
    {
        /** @var Guest $guest */
        $guest = $request->user();

        return $guest;
    }
}
