<?php

namespace App\Domain\StayServices\Exceptions;

use App\Support\ErrorText;
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
        parent::__construct(ErrorText::message('service_review_not_allowed', ['reason' => ErrorText::reason($reason)]));
    }

    public static function orderNotFulfilled(string $status): self
    {
        return new self("service_order_not_fulfilled:{$status}");
    }
}
