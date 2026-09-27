<?php

namespace App\Domain\HotelGroup\Enums;

/**
 * The type of a hotel's nearby place (`hotel_nearby_places.category`).
 * Clients use it to group/filter and as the icon fallback when the operator
 * set no explicit icon key.
 */
enum NearbyPlaceCategory: string
{
    case Airport = 'airport';
    case Transport = 'transport';
    case Landmark = 'landmark';
    case Attraction = 'attraction';
    case Shopping = 'shopping';
    case Dining = 'dining';
    case Beach = 'beach';
    case Business = 'business';
    case Health = 'health';
    case Worship = 'worship';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }
}
