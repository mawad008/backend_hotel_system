<?php

namespace App\Domain\Discovery\Services;

use App\Domain\Inventory\Models\RoomType;

/**
 * One room type's availability for a specific stay — a read-only preview, not
 * a hold. `roomsAvailable` uses the same checkout-exclusive overlap predicate
 * and Option-A aggregate-capacity rule as ReservationService::create(), so the
 * discovery number and the booking outcome agree.
 */
final class RoomAvailability
{
    public function __construct(
        public readonly RoomType $roomType,
        public readonly int $roomsTotal,
        public readonly int $roomsAvailable,
        public readonly int $nights,
        public readonly string $estimatedTotal,
    ) {}

    public function isAvailable(): bool
    {
        return $this->roomsAvailable > 0;
    }
}
