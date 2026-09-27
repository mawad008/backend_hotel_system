<?php

namespace App\Http\Controllers\Api\V1\Guest;

use App\Domain\Discovery\Services\HotelDiscoveryService;
use App\Domain\StayServices\Services\ServiceCatalogService;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ServiceCategoryResource;
use App\Http\Resources\V1\ServiceResource;
use Illuminate\Http\JsonResponse;

/**
 * Anonymous guest read of a hotel's service catalog
 * (`/api/v1/guest/hotels/{hotel}/service-categories`,
 * `/api/v1/guest/hotels/{hotel}/services`). No auth, no hotel scope —
 * mirrors GuestDiscoveryController: active hotel, active categories/services
 * only. A hotel that does not exist and one that is deactivated are an
 * identical plain 404.
 */
class GuestServiceCatalogController extends Controller
{
    public function __construct(
        private readonly HotelDiscoveryService $discovery,
        private readonly ServiceCatalogService $catalog,
    ) {}

    public function categories(int $hotel): JsonResponse
    {
        $found = $this->discovery->findHotel($hotel);

        if (! $found) {
            abort(404);
        }

        return $this->success(
            ServiceCategoryResource::collection($this->catalog->activeCategoriesForHotel($found))
        );
    }

    public function services(int $hotel): JsonResponse
    {
        $found = $this->discovery->findHotel($hotel);

        if (! $found) {
            abort(404);
        }

        return $this->success(
            ServiceResource::collection($this->catalog->activeServicesForHotel($found))
        );
    }
}
