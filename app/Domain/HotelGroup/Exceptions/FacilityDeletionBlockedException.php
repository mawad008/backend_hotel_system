<?php

namespace App\Domain\HotelGroup\Exceptions;

use RuntimeException;

/**
 * Thrown when a Facility cannot be deleted because one or more hotels still
 * reference it via `facility_hotel`. Deactivate instead.
 */
class FacilityDeletionBlockedException extends RuntimeException
{
    public static function inUse(): self
    {
        return new self((string) __('api.facility.delete_blocked'));
    }
}
