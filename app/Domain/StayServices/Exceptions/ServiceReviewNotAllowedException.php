<?php

namespace App\Domain\StayServices\Exceptions;

use RuntimeException;

/**
 * Thrown when a service review cannot be submitted. `$reason` is a short,
 * fixed machine code — never a secret or an internal detail (same
 * convention as ReviewNotAllowedException).
 */
class ServiceReviewNotAllowedException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("This service review action is not allowed ({$reason}).");
    }

    public static function orderNotFulfilled(string $status): self
    {
        return new self("service_order_not_fulfilled:{$status}");
    }
}
