<?php

namespace App\Http\Controllers\Api\V1\Guest;

use App\Domain\Discovery\Services\HotelDiscoveryService;
use App\Domain\Review\Services\ReviewCategoryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Guest\AvailabilityQueryRequest;
use App\Http\Requests\Api\V1\Guest\HotelListQueryRequest;
use App\Http\Resources\V1\PublicHotelResource;
use App\Http\Resources\V1\RoomAvailabilityResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Slice 1 — anonymous guest discovery. No auth, no hotel scope (there is no
 * caller identity): every response is limited server-side to active hotels /
 * active room types. A hotel that does not exist and one that is deactivated
 * are an identical plain 404.
 */
class GuestDiscoveryController extends Controller
{
    public function __construct(
        private readonly HotelDiscoveryService $discovery,
        private readonly ReviewCategoryService $reviewCategories,
    ) {}

    public function hotels(HotelListQueryRequest $request): JsonResponse
    {
        $hotels = $this->discovery->listHotels(
            city: $request->validated('city'),
            search: $request->validated('q'),
            perPage: (int) ($request->validated('per_page') ?? 15),
            sort: $request->sort(),
            minPrice: $request->minPrice(),
            maxPrice: $request->maxPrice(),
            facilities: $request->facilities(),
        );

        return $this->success(PublicHotelResource::collection($hotels));
    }

    public function cities(): JsonResponse
    {
        return $this->success($this->discovery->cities());
    }

    public function show(int $hotel): JsonResponse
    {
        $found = $this->discovery->findHotel($hotel);

        if (! $found) {
            abort(404);
        }

        $found->setRelation('roomTypes', $this->discovery->activeRoomTypes($found->id));
        $found->load(['highlights', 'nearbyPlaces'])->loadCount('rooms');

        return $this->success(
            (new PublicHotelResource($found))
                ->withReviewSummary($this->reviewCategories->publicSummary($found)),
        );
    }

    public function availability(AvailabilityQueryRequest $request, int $hotel): JsonResponse
    {
        $found = $this->discovery->findHotel($hotel);

        if (! $found) {
            abort(404);
        }

        $checkIn = $request->validated('check_in');
        $checkOut = $request->validated('check_out');

        $rooms = $this->discovery->availability(
            hotelId: $found->id,
            checkIn: $checkIn,
            checkOut: $checkOut,
            adults: $request->adults(),
            children: $request->children(),
        );

        return $this->success([
            'hotel_id' => $found->id,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'adults' => $request->adults(),
            'children' => $request->children(),
            'nights' => CarbonImmutable::parse($checkIn)->diffInDays(CarbonImmutable::parse($checkOut)),
            'currency' => config('payment.currency'),
            'rooms' => RoomAvailabilityResource::collection($rooms),
        ]);
    }
}
