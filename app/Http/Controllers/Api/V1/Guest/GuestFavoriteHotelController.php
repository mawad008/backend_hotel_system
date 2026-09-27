<?php

namespace App\Http\Controllers\Api\V1\Guest;

use App\Domain\Discovery\Services\GuestFavoriteHotelService;
use App\Domain\Reservation\Models\Guest;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Guest\GuestFavoriteHotelResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The guest's favourite hotels (`/guest/favorites/hotels`). The guest is
 * always the token owner; save/remove are idempotent.
 */
class GuestFavoriteHotelController extends Controller
{
    public function __construct(
        private readonly GuestFavoriteHotelService $favorites,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->success(GuestFavoriteHotelResource::collection(
            $this->favorites->listFor($this->guest($request)),
        ));
    }

    public function store(Request $request, int $hotel): JsonResponse
    {
        $favorite = $this->favorites->add($this->guest($request), $hotel);

        return $this->success(new GuestFavoriteHotelResource($favorite), __('api.updated'));
    }

    public function destroy(Request $request, int $hotel): JsonResponse
    {
        $this->favorites->remove($this->guest($request), $hotel);

        return $this->success(null, __('api.deleted'));
    }

    private function guest(Request $request): Guest
    {
        /** @var Guest $guest */
        $guest = $request->user();

        return $guest;
    }
}
