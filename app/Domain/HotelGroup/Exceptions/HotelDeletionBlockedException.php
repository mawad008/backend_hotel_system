<?php

namespace App\Domain\HotelGroup\Exceptions;

use RuntimeException;

class HotelDeletionBlockedException extends RuntimeException
{
    public static function hasReservations(int $count): self
    {
        return new self((string) __('api.hotel.delete_has_reservations', ['count' => $count]));
    }

    public static function hasOperationalHistory(): self
    {
        return new self((string) __('api.hotel.delete_has_history'));
    }
}
