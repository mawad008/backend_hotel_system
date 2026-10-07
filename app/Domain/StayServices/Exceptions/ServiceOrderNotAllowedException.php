<?php

namespace App\Domain\StayServices\Exceptions;

use App\Support\ErrorText;
use RuntimeException;

/**
 * Thrown when a service order cannot be created because a business
 * precondition is unmet. $reason is a short, fixed machine code — never a
 * secret, a provider payload, or an internal detail. The public
 * constructors below are the only reasons Phase 8 checks.
 */
class ServiceOrderNotAllowedException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(ErrorText::message('service_order_not_allowed', ['reason' => ErrorText::reason($reason)]));
    }

    public static function reservationNotServiceable(string $status): self
    {
        return new self("reservation_not_serviceable:{$status}");
    }

    public static function serviceInactive(): self
    {
        return new self('service_inactive');
    }

    public static function serviceHotelMismatch(): self
    {
        return new self('service_hotel_mismatch');
    }

    public static function invalidQuantity(): self
    {
        return new self('invalid_quantity');
    }
}
