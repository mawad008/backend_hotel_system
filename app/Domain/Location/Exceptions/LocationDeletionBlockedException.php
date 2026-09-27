<?php

namespace App\Domain\Location\Exceptions;

use RuntimeException;

/**
 * Thrown when a Country or City cannot be deleted because other records
 * still reference it (a country with cities, or a country/city referenced
 * by a hotel). Deactivate instead.
 */
class LocationDeletionBlockedException extends RuntimeException
{
    public static function country(): self
    {
        return new self((string) __('api.location.country_delete_blocked'));
    }

    public static function city(): self
    {
        return new self((string) __('api.location.city_delete_blocked'));
    }
}
