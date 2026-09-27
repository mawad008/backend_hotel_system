<?php

namespace App\Domain\Location\Exceptions;

use RuntimeException;

/**
 * Thrown when a City is used together with a Country it does not belong to
 * (e.g. a hotel submitted with country_id = Egypt and city_id = a Saudi
 * city). Frontend relationship integrity is never trusted — this is the
 * server-side backstop behind the hotel form-request validation.
 */
class CountryCityMismatchException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct((string) __('api.location.city_country_mismatch'));
    }
}
