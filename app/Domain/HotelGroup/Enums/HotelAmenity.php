<?php

namespace App\Domain\HotelGroup\Enums;

/**
 * The fixed amenity vocabulary, stored as a JSON array of these string
 * values on `room_types.amenities` (RoomType's own, separate amenity list).
 *
 * Hotel-level facilities no longer use this enum — they were migrated to a
 * real `Facility` catalog + `facility_hotel` pivot (see the
 * `create_facilities_table` migration, which seeds the catalog from these
 * same 12 values so no data/vocabulary was lost). This enum is kept only
 * because RoomType still has its own separate, unmigrated `amenities`
 * column (see the Hotel module plan — Room Type ↔ Facilities is a
 * deliberately deferred decision, not built yet).
 */
enum HotelAmenity: string
{
    case FreeWifi = 'free_wifi';
    case Breakfast = 'breakfast';
    case Parking = 'parking';
    case Pool = 'pool';
    case Gym = 'gym';
    case FamilyRooms = 'family_rooms';
    case AirportShuttle = 'airport_shuttle';
    case RoomService = 'room_service';
    case AirConditioning = 'air_conditioning';
    case Restaurant = 'restaurant';
    case Spa = 'spa';
    case BusinessCenter = 'business_center';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }
}
